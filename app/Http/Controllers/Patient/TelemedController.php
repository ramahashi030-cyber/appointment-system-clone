<?php

namespace App\Http\Controllers\Patient;

use App\ConsultationReason;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\ServiceTele;
use App\Models\ServiceTimeslotTele;
use App\Models\UnavailableTimeslotTele;
use App\Support\AppointmentQrCode;
use App\Support\AppointmentSchema;
use App\Support\Telemed;
use App\Symptom;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Patient-facing telemedicine screens (mirrors telemed.php / book_tele.php /
 * timeslots_tele.php).
 */
class TelemedController extends Controller
{
    /**
     * GET /telemed — patient hub.
     */
    public function home(): View
    {
        $patient = Telemed::currentPatient();
        $patientId = $patient?->id;

        $activeAppointment = Telemed::activeAppointment($patientId);

        $upcoming = $patientId
            ? $this->presentAppointments(
                $this->patientAppointments($patientId)
                    ->where('a.date', '>=', Carbon::today()->toDateString())
                    ->orderBy('a.date')
                    ->orderBy('a.time_slot')
                    ->get()
            )
            : [];

        $services = ServiceTele::orderBy('service_name')
            ->get()
            ->map(fn (ServiceTele $service) => [
                'id' => $service->id,
                'service_name' => $service->service_name,
                'availability_days' => implode(', ', array_map(
                    fn (string $day) => substr($day, 0, 3),
                    Telemed::codesToFull($service->availability_day)
                )),
            ])
            ->all();

        $pendingFaceRequests = $patientId
            ? $this->presentRequests(
                $this->patientRequests($patientId)
                    ->where('a.request_mode', 'FACE')
                    ->orderByDesc('a.created_at')
                    ->get()
            )
            : [];

        $recentNotifications = $patient?->notifications()
            ->latest('created_at')
            ->limit(10)
            ->get() ?? collect();

        return view('patients.home', [
            'patient' => $patient,
            'patientName' => Telemed::patientFullName($patient),
            'activeAppointment' => $activeAppointment,
            'upcoming' => $upcoming,
            'pendingFaceRequests' => $pendingFaceRequests,
            'services' => $services,
            'consultationReasons' => ConsultationReason::options(),
            'symptoms' => Symptom::options(),
            'unreadNotificationCount' => $patient?->notifications()
                ->where('is_read', false)
                ->count() ?? 0,
            'recentNotifications' => $recentNotifications,
        ]);
    }

    /**
     * POST /telemed/consent — record consent before opening the booking form.
     */
    public function consent(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'service_id' => ['nullable', 'integer', 'exists:services_tele,id'],
        ]);

        $patient = Telemed::currentPatient();

        if ($patient === null) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Please sign in before providing consent and booking a visit.',
                ], 401);
            }

            return redirect()->route('auth.login')->with(
                'error',
                'Please sign in before providing consent and booking a visit.'
            );
        }

        $patient->consents()->create([
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $activeAppointment = Telemed::activeAppointment($patient->id);
        $parameters = isset($validated['service_id'])
            ? ['service_id' => $validated['service_id']]
            : [];
        $message = $activeAppointment
            ? 'Your consent has been recorded. You can review your active appointment before continuing.'
            : 'Your consent has been recorded. You may now continue with your booking.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'service_id' => $validated['service_id'] ?? null,
                'active_appointment' => $activeAppointment,
            ], 201);
        }

        return redirect()->route('telemed.book', $parameters)->with('success', $message);
    }

    /**
     * GET /telemed/book — appointment request form (no calendar).
     *
     * Patients answer the consultation question first. Picking a specific
     * consultation type creates a face-to-face pending request immediately;
     * picking "None of the above" reveals the symptom selector and complaint
     * details, which submit as a telemedicine pending request.
     */
    public function showBook(Request $request): View
    {
        $patient = Telemed::currentPatient();
        $patientId = $patient?->id;

        $age = Telemed::age($patient?->dob);
        $active = Telemed::activeAppointment($patientId);

        $pendingFaceRequest = $patientId
            ? Appointment::query()
                ->where('patient_id', $patientId)
                ->where('request_mode', 'FACE')
                ->whereNull('date')
                ->whereIn('triager_status', ['Pending', 'Processing', 'In Progress', 'Approved'])
                ->orderByDesc('created_at')
                ->first()
            : null;

        return view('patients.book', [
            'patientName' => Telemed::patientFullName($patient),
            'ageDisplay' => $age['display'],
            'ageValue' => $age['value'],
            'gender' => $patient?->gender ?? '',
            'activeAppointment' => $active,
            'isExpired' => (bool) ($active['is_expired'] ?? false),
            'pendingFaceRequest' => $pendingFaceRequest,
            'consultationReasons' => ConsultationReason::options(),
            'symptoms' => Symptom::options(),
        ]);
    }

    /**
     * POST /telemed/book — create a pending appointment request.
     *
     * The patient never picks a date or time. A specific consultation type
     * becomes a face-to-face request; "None of the above" becomes a
     * telemedicine request with symptoms and complaint details.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $patient = Telemed::currentPatient();

        if ($patient === null) {
            return $this->bookingError($request, 'No patient session found. Please sign in again.');
        }

        $reason = $request->input('consultation_reason', '');

        // Specific consultation type → face-to-face pending request.
        if ($reason !== '' && $reason !== ConsultationReason::NoneOfTheAbove->value) {
            return $this->storeFaceRequest($request, $patient, $reason);
        }

        // None of the above → telemedicine pending request with symptoms.
        return $this->storeTeleRequest($request, $patient);
    }

    /**
     * Create a face-to-face pending request from a specific consultation type.
     */
    private function storeFaceRequest(Request $request, Patient $patient, string $reason): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'consultation_reason' => ['required', Rule::enum(ConsultationReason::class)],
        ]);

        $reasonEnum = ConsultationReason::from($validated['consultation_reason']);

        if ($reasonEnum === ConsultationReason::NoneOfTheAbove) {
            return $this->bookingError($request, 'Please choose what you need to consult for.');
        }

        $existingPending = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('request_mode', 'FACE')
            ->whereNull('date')
            ->whereIn('triager_status', ['Pending', 'Processing', 'In Progress', 'Approved'])
            ->orderByDesc('created_at')
            ->first();

        if ($existingPending !== null) {
            $message = 'You already have a pending face-to-face request. Please wait for the triage team to process it before submitting another.';

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'existing_request_id' => $existingPending->id,
                    'appointments_url' => route('telemed.mine'),
                ], 422);
            }

            return redirect()->route('telemed.mine')->with('error', $message);
        }

        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'consultation_reason' => $reasonEnum->value,
            'complaint' => Str::limit($reasonEnum->label(), 255),
            'complaint_details' => $reasonEnum->label(),
            'status' => 'Pending',
            'mode' => 'FACE',
            'request_mode' => 'FACE',
            'triager_status' => 'Pending',
        ]);

        $message = 'Your face-to-face consultation request has been submitted. Our triage team will process it shortly.';

        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json([
                'message' => $message,
                'request_id' => $appointment->id,
                'appointments_url' => route('telemed.mine'),
            ], 201);
        }

        return redirect()->route('telemed.mine')->with('success', $message);
    }

    /**
     * Create a telemedicine pending request from symptoms + complaint details.
     */
    private function storeTeleRequest(Request $request, Patient $patient): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'symptoms' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'symptoms.*' => ['required', 'string', 'distinct', Rule::enum(Symptom::class)],
            'complaint_details' => ['required', 'string', 'min:3', 'max:2000'],
        ], [
            'symptoms.required' => 'Please select at least 1 and maximum of 3 symptoms.',
            'symptoms.min' => 'Please select at least 1 and maximum of 3 symptoms.',
            'symptoms.max' => 'Please select no more than 3 symptoms.',
            'symptoms.*.distinct' => 'Please do not select the same symptom more than once.',
            'complaint_details.required' => 'Please provide details about your complaint.',
        ]);

        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'symptoms' => $validated['symptoms'],
            'complaint' => Str::limit($validated['complaint_details'], 255),
            'complaint_details' => $validated['complaint_details'],
            'status' => 'Pending',
            'mode' => 'TELE',
            'request_mode' => 'TELE',
            'triager_status' => 'Pending',
        ]);

        $message = 'Your telemedicine consultation request has been submitted. Our triage team will process it shortly.';

        if ($request->expectsJson()) {
            $request->session()->flash('success', $message);

            return response()->json([
                'message' => $message,
                'request_id' => $appointment->id,
                'appointments_url' => route('telemed.mine'),
            ], 201);
        }

        return redirect()->route('telemed.mine')->with('success', $message);
    }

    /**
     * GET /telemed/appointments/{appointment}/qr — patient appointment QR.
     */
    public function qr(Appointment $appointment, AppointmentQrCode $appointmentQrCode): Response
    {
        abort_unless(
            (int) Telemed::currentPatientId() === (int) $appointment->patient_id,
            403,
            'You are not allowed to view this appointment QR code.'
        );
        abort_unless($appointment->mode === 'FACE', 404);

        try {
            AppointmentSchema::ensureCompatibleColumns();
        } catch (QueryException $exception) {
            report($exception);
            abort(503, 'The appointment database needs a safe schema update before the QR code can be loaded.');
        }

        $appointmentQrCode->ensureToken($appointment);

        if (blank($appointment->qr_code_path)) {
            $appointment->forceFill([
                'qr_code_path' => route('telemed.appointment.qr', $appointment, false),
            ])->save();
        }

        return response($appointmentQrCode->svg($appointment), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * GET /telemed/appointments/{appointment}/join — gated Jitsi entry for patients.
     */
    public function join(Appointment $appointment): RedirectResponse
    {
        abort_unless(
            (int) Telemed::currentPatientId() === (int) $appointment->patient_id,
            403,
            'You are not allowed to join this consultation.'
        );
        abort_unless($appointment->mode === 'TELE', 404);
        abort_unless(Appointment::hasActiveStatus($appointment->status), 403);
        abort_unless(filled($appointment->meeting_link), 404);
        abort_unless((bool) $appointment->room_opened, 403, 'Waiting for doctor to start the consultation.');

        return redirect()->away($appointment->meeting_link);
    }

    /**
     * POST /telemed/book/cancel
     */
    public function cancel(Request $request): RedirectResponse
    {
        $request->validate(['cancel_id' => ['required', 'integer']]);

        $patientId = Telemed::currentPatientId();

        $updated = $patientId
            ? Appointment::query()
                ->where('id', $request->input('cancel_id'))
                ->where('patient_id', $patientId)
                ->whereIn('status', Appointment::ACTIVE_STATUSES)
                ->where('mode', 'TELE')
                ->update(['status' => 'Cancelled'])
            : 0;

        return back()->with(
            $updated ? 'success' : 'error',
            $updated
                ? 'Your appointment has been cancelled.'
                : 'That appointment could not be cancelled.'
        );
    }

    /**
     * GET /telemed/calendar?service_id=&month=YYYY-MM — monthly booking availability.
     */
    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services_tele,id'],
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $service = ServiceTele::findOrFail($validated['service_id']);
        $month = Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();
        $availableWeekdays = Telemed::codesToFull($service->availability_day);
        $holidays = Telemed::holidaysMap();
        $slots = ServiceTimeslotTele::query()
            ->where('service_id', $service->id)
            ->orderBy('time_slot')
            ->get()
            ->keyBy('time_slot');

        $booked = Appointment::query()
            ->where('mode', 'TELE')
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->where('service_id', $service->id)
            ->whereBetween('date', [$month->toDateString(), $monthEnd->toDateString()])
            ->get(['date', 'time_slot'])
            ->groupBy(fn (Appointment $appointment) => $appointment->date->format('Y-m-d').'|'.$appointment->time_slot)
            ->map(fn ($appointments) => $appointments->count());

        $blocked = UnavailableTimeslotTele::query()
            ->where('service_id', $service->id)
            ->whereBetween('date', [$month->toDateString(), $monthEnd->toDateString()])
            ->get()
            ->mapWithKeys(fn (UnavailableTimeslotTele $slot) => [
                $slot->date->format('Y-m-d').'|'.$slot->time_slot => $slot->reason ?: 'Unavailable',
            ]);

        $days = [];
        $date = $month->copy();

        while ($date->lessThanOrEqualTo($monthEnd)) {
            $dateKey = $date->toDateString();
            $remainingSlots = 0;

            foreach ($slots as $timeSlot => $slot) {
                $slotKey = $dateKey.'|'.$timeSlot;

                if (! $blocked->has($slotKey)) {
                    $remainingSlots += max(
                        0,
                        (int) $slot->slots - (int) $booked->get($slotKey, 0)
                    );
                }
            }

            $isPast = $date->isBefore(Carbon::today()->startOfDay());
            $isClosedToday = $date->isSameDay(Carbon::today()) && now()->hour >= 18;
            $isServiceDay = in_array($date->format('l'), $availableWeekdays, true);
            $holiday = $holidays[$dateKey] ?? null;
            $isAvailable = ! $isPast
                && ! $isClosedToday
                && $isServiceDay
                && $holiday === null
                && $remainingSlots > 0;
            $isFullyBooked = ! $isPast
                && ! $isClosedToday
                && $isServiceDay
                && $holiday === null
                && $remainingSlots === 0;

            $reason = match (true) {
                $isPast => 'Date has passed',
                $isClosedToday => 'Booking is closed for today',
                $holiday !== null => $holiday,
                ! $isServiceDay => 'Service is not available on this day',
                $isFullyBooked => 'Fully booked',
                default => $remainingSlots.' slot'.($remainingSlots === 1 ? '' : 's').' remaining',
            };

            $days[] = [
                'date' => $dateKey,
                'day' => (int) $date->format('j'),
                'available' => $isAvailable,
                'fully_booked' => $isFullyBooked,
                'remaining_slots' => $remainingSlots,
                'reason' => $reason,
            ];

            $date->addDay();
        }

        return response()->json([
            'service' => [
                'id' => $service->id,
                'name' => $service->service_name,
            ],
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'days' => $days,
        ]);
    }

    /**
     * GET /telemed/timeslots?service_id=&date=  (JSON, port of timeslots_tele.php)
     */
    public function timeslots(Request $request): JsonResponse
    {
        $serviceId = (int) $request->query('service_id', 0);
        $date = (string) $request->query('date', '');

        if (! $serviceId || ! Carbon::hasFormat($date, 'Y-m-d')) {
            return response()->json(['error' => 'Invalid request'], 422);
        }

        $unavailable = UnavailableTimeslotTele::query()
            ->where('service_id', $serviceId)
            ->where('date', $date)
            ->get()
            ->mapWithKeys(fn ($row) => [$row->time_slot => $row->reason ?: 'Unavailable']);

        $booked = Appointment::query()
            ->where('service_id', $serviceId)
            ->where('date', $date)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->where('mode', 'TELE')
            ->get(['time_slot'])
            ->groupBy('time_slot')
            ->map(fn ($rows) => $rows->count());

        $slots = ServiceTimeslotTele::query()
            ->where('service_id', $serviceId)
            ->orderBy('time_slot')
            ->get()
            ->map(function ($slot) use ($unavailable, $booked) {
                if ($unavailable->has($slot->time_slot)) {
                    return [
                        'time_slot' => $slot->time_slot,
                        'remaining' => 0,
                        'blocked' => true,
                        'reason' => $unavailable[$slot->time_slot],
                    ];
                }

                return [
                    'time_slot' => $slot->time_slot,
                    'remaining' => max(0, (int) $slot->slots - (int) $booked->get($slot->time_slot, 0)),
                    'blocked' => false,
                ];
            })
            ->values();

        return response()->json($slots);
    }

    /**
     * GET /telemed/notifications/poll — live unread notification count.
     */
    public function pollNotifications(): JsonResponse
    {
        $patient = Telemed::currentPatient();

        if ($patient === null) {
            return response()->json(['unread' => 0, 'notifications' => []]);
        }

        $unread = $patient->notifications()
            ->where('is_read', false)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn ($notification) => [
                'id' => $notification->id,
                'message' => $notification->message,
                'created_at' => $notification->created_at?->format('M j, Y h:i A'),
                'is_read' => (bool) $notification->is_read,
            ]);

        return response()->json([
            'unread' => $unread->where('is_read', false)->count(),
            'notifications' => $unread,
        ]);
    }

    /**
     * GET /telemed/my-appointments — scheduled visits + pending requests.
     */
    public function myAppointments(): View
    {
        $patientId = Telemed::currentPatientId();

        $appointments = $patientId
            ? $this->presentAppointments(
                $this->patientAppointments($patientId)
                    ->orderByDesc('a.date')
                    ->orderByDesc('a.time_slot')
                    ->get()
            )
            : [];

        $requests = $patientId
            ? $this->presentRequests(
                $this->patientRequests($patientId)
                    ->orderByDesc('a.created_at')
                    ->get()
            )
            : [];

        return view('patients.appointment', [
            'appointments' => $appointments,
            'requests' => $requests,
        ]);
    }

    /**
     * Return a JSON error for the dashboard booking modal or the legacy redirect.
     */
    private function bookingError(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->with('error', $message);
    }

    private function activeAppointmentMessage(?int $patientId = null): string
    {
        $appointment = Telemed::activeAppointment($patientId ?? Telemed::currentPatientId());
        $date = $appointment['date'] ?? '—';
        $time = $appointment['time_slot'] ?? '—';

        return "You already have an active appointment on {$date} at {$time}. Cancel or complete it before booking another visit.";
    }

    /**
     * Shape the joined rows the way the Blade views read them (plain strings,
     * so dates render as Y-m-d instead of a Carbon object).
     *
     * @param  Collection<int, Appointment>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentAppointments(Collection $rows): array
    {
        return $rows->map(function (Appointment $row) {
            $symptoms = is_array($row->symptoms) ? $row->symptoms : [];
            $symptomLabels = array_values(array_filter(array_map(
                fn (mixed $symptom): ?string => is_string($symptom)
                    ? Symptom::tryFrom($symptom)?->label()
                    : null,
                $symptoms,
            )));

            $isActive = in_array(strtolower((string) $row->status), ['booked', 'pending', 'confirmed'], true);
            $canJoin = $isActive && filled($row->meeting_link) && (bool) $row->room_opened;

            return [
                'id' => $row->id,
                'service_name' => $row->service_name ?? 'Consultation',
                'date' => $row->date?->format('Y-m-d'),
                'time_slot' => $row->time_slot,
                'status' => $row->status,
                'mode' => $row->mode,
                'meeting_link' => $row->meeting_link,
                'can_join' => $canJoin,
                'consultation_reason' => $row->consultation_reason,
                'consultation_reason_label' => ConsultationReason::tryFrom((string) $row->consultation_reason)?->label(),
                'symptoms' => $symptoms,
                'symptom_labels' => $symptomLabels,
                'complaint_details' => $row->complaint_details,
                'qr_code_url' => $row->mode === 'FACE' && $row->qr_code_token
                    ? route('telemed.appointment.qr', $row, false)
                    : null,
                'join_url' => $row->mode === 'TELE' && $canJoin
                    ? route('telemed.appointment.join', $row, false)
                    : null,
                'waiting_for_doctor' => $row->mode === 'TELE'
                    && $isActive
                    && filled($row->meeting_link)
                    && ! (bool) $row->room_opened,
                'is_expired' => $row->date ? $row->date->lt(Carbon::today()) : false,
            ];
        })->all();
    }

    /**
     * Scheduled appointments for one patient (face-to-face and telemedicine).
     */
    private function patientAppointments(int $patientId): Builder
    {
        return Appointment::query()
            ->from('appointments as a')
            ->select([
                'a.*',
                DB::raw("COALESCE(st.service_name, s.service_name, 'Consultation') as service_name"),
            ])
            ->leftJoin('services_tele as st', function ($join): void {
                $join->on('a.service_id', '=', 'st.id')->where('a.mode', '=', 'TELE');
            })
            ->leftJoin('services as s', function ($join): void {
                $join->on('a.service_id', '=', 's.id')->where('a.mode', '=', 'FACE');
            })
            ->where('a.patient_id', $patientId)
            ->whereNotNull('a.date')
            ->whereIn('a.status', Appointment::ACTIVE_STATUSES);
    }

    /**
     * Pending appointment requests for one patient (no date/time yet).
     */
    private function patientRequests(int $patientId): Builder
    {
        return Appointment::query()
            ->from('appointments as a')
            ->where('a.patient_id', $patientId)
            ->whereNull('a.date')
            ->whereIn('a.triager_status', ['Pending', 'Processing', 'In Progress', 'Approved']);
    }

    /**
     * Shape pending requests for the patient dashboard.
     *
     * @param  Collection<int, Appointment>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentRequests(Collection $rows): array
    {
        return $rows->map(function (Appointment $row) {
            $symptoms = is_array($row->symptoms) ? $row->symptoms : [];
            $symptomLabels = array_values(array_filter(array_map(
                fn (mixed $symptom): ?string => is_string($symptom)
                    ? Symptom::tryFrom($symptom)?->label()
                    : null,
                $symptoms,
            )));

            $reasonLabel = $row->consultation_reason !== null
                ? ConsultationReason::tryFrom($row->consultation_reason)?->label()
                : null;

            $isFace = $row->request_mode === 'FACE';
            $symptomsText = $isFace && $reasonLabel
                ? $reasonLabel
                : implode(', ', $symptomLabels);
            $consultationDetails = $isFace
                ? ($reasonLabel ?? 'None')
                : ($row->complaint_details ?? 'None');

            return [
                'id' => $row->id,
                'is_face' => $isFace,
                'mode_label' => $isFace ? 'Face-to-Face' : 'Telemedicine',
                'symptoms' => $isFace && $reasonLabel ? [$reasonLabel] : $symptomLabels,
                'symptoms_text' => $symptomsText,
                'consultation_reason' => $row->consultation_reason,
                'consultation_reason_label' => $reasonLabel,
                'consultation_details' => $consultationDetails,
                'complaint_details' => $row->complaint_details,
                'status' => $row->status,
                'triager_status' => $row->triager_status,
                'triager_action' => $row->triager_action,
                'requested_at' => $row->created_at,
                'qr_code_url' => null,
            ];
        })->all();
    }

    /**
     * Jitsi room name: lastname + firstname + hospital# + date, exactly like
     * the legacy app (falls back to the patient id when HOMIS has no number
     * yet, otherwise every patient without a hospital # would collide).
     */
    private function buildMeetingLink(Patient $patient, Carbon $date): string
    {
        $clean = fn ($value) => preg_replace('/[^A-Za-z0-9]/', '', (string) $value);

        $room = $clean($patient->last_name)
            .$clean($patient->first_name)
            .$clean($patient->hospital_number ?: 'P'.$patient->id)
            .$date->format('Ymd');

        return 'https://meet.jit.si/'.$room;
    }
}

<?php

use App\Models\Appointment;
use App\Models\ServiceTele;
use App\Models\ServiceTimeslotTele;
use App\Support\AppointmentQrCode;
use App\Support\AppointmentSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function telemedBookingTables(): void
{
    if (! Schema::hasTable('appointments')) {
        Schema::create('appointments', function ($table): void {
            $table->id();
            $table->integer('patient_id')->nullable();
            $table->integer('service_id')->nullable();
            $table->string('complaint')->nullable();
            $table->string('consultation_reason', 100)->nullable();
            $table->json('symptoms')->nullable();
            $table->text('complaint_details')->nullable();
            $table->date('date')->nullable();
            $table->string('time_slot')->nullable();
            $table->string('status')->nullable();
            $table->string('qr_code_path')->nullable();
            $table->char('qr_code_token', 64)->nullable()->unique();
            $table->boolean('reminder_sent')->nullable();
            $table->string('mode')->nullable();
            $table->string('meeting_link')->nullable();
            $table->boolean('room_opened')->nullable();
            $table->integer('opened_by')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('services_tele')) {
        Schema::create('services_tele', function ($table): void {
            $table->id();
            $table->string('service_name');
            $table->string('availability_day')->nullable();
            $table->string('homis_code')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    if (! Schema::hasTable('holidays_tele')) {
        Schema::create('holidays_tele', function ($table): void {
            $table->increments('id');
            $table->date('holiday_date');
            $table->string('description')->nullable();
        });
    }

    if (! Schema::hasTable('service_timeslots_tele')) {
        Schema::create('service_timeslots_tele', function ($table): void {
            $table->increments('id');
            $table->integer('service_id');
            $table->string('time_slot');
            $table->integer('slots')->default(1);
        });
    }

    if (! Schema::hasTable('unavailable_timeslots_tele')) {
        Schema::create('unavailable_timeslots_tele', function ($table): void {
            $table->increments('id');
            $table->integer('service_id');
            $table->date('date');
            $table->string('time_slot');
            $table->string('reason')->nullable();
        });
    }

    if (! Schema::hasTable('notifications')) {
        Schema::create('notifications', function ($table): void {
            $table->id();
            $table->integer('patient_id')->nullable();
            $table->text('message')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
        });
    }

    if (! Schema::hasTable('patient_consent')) {
        Schema::create('patient_consent', function ($table): void {
            $table->id();
            $table->integer('patient_id');
            $table->timestamp('consented_at')->nullable();
            $table->string('ip_address', 50)->nullable();
            $table->text('user_agent')->nullable();
        });
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function telemedBookingPayload(ServiceTele $service, Carbon $date, array $overrides = []): array
{
    return array_merge([
        'service_id' => $service->id,
        'date' => $date->toDateString(),
        'time_slot' => '08:00 - 10:00',
        'consultation_reason' => 'general_check_up',
        'symptoms' => ['headache', 'dizziness'],
        'complaint_details' => 'Persistent headache since yesterday.',
    ], $overrides);
}

function createTelemedTestService(): ServiceTele
{
    $service = ServiceTele::create([
        'service_name' => 'Family Medicine',
        'availability_day' => 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
        'homis_code' => 'FAM',
    ]);

    ServiceTimeslotTele::create([
        'service_id' => $service->id,
        'time_slot' => '08:00 - 10:00',
        'slots' => 2,
    ]);

    return $service;
}

test('booking requires one reason one to three symptoms and complaint details', function () {
    telemedBookingTables();

    $service = createTelemedTestService();
    $date = Carbon::today()->addDay()->startOfDay();

    $this->withSession(['patient_id' => makePatient()->id])
        ->postJson('/telemed/book', telemedBookingPayload($service, $date, [
            'consultation_reason' => null,
            'symptoms' => [],
            'complaint_details' => '',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['consultation_reason', 'symptoms', 'complaint_details']);

    $this->withSession(['patient_id' => makePatient(['username' => 'too-many'])->id])
        ->postJson('/telemed/book', telemedBookingPayload($service, $date, [
            'symptoms' => ['headache', 'dizziness', 'cough', 'fever_or_chills'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['symptoms']);

    $this->withSession(['patient_id' => makePatient(['username' => 'invalid-symptom'])->id])
        ->postJson('/telemed/book', telemedBookingPayload($service, $date, [
            'symptoms' => ['not-a-real-symptom'],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['symptoms.0']);
});

test('booking additively repairs a legacy appointments table before inserting', function () {
    telemedBookingTables();

    $legacyColumns = array_values(array_filter([
        'consultation_reason',
        'symptoms',
        'complaint_details',
        'qr_code_token',
    ], fn (string $column): bool => Schema::hasColumn('appointments', $column)));

    if ($legacyColumns !== []) {
        Schema::table('appointments', function ($table) use ($legacyColumns): void {
            if (in_array('qr_code_token', $legacyColumns, true)) {
                $table->dropUnique(['qr_code_token']);
            }

            $table->dropColumn($legacyColumns);
        });
    }

    $schemaProperty = (new ReflectionClass(AppointmentSchema::class))->getProperty('isReady');
    $schemaProperty->setAccessible(true);
    $schemaProperty->setValue(null, false);

    $patient = makePatient();
    $service = createTelemedTestService();
    $date = Carbon::today()->addDay()->startOfDay();

    $this->withSession(['patient_id' => $patient->id])
        ->postJson('/telemed/book', telemedBookingPayload($service, $date))
        ->assertCreated();

    expect(Schema::hasColumn('appointments', 'consultation_reason'))->toBeTrue()
        ->and(Schema::hasColumn('appointments', 'symptoms'))->toBeTrue()
        ->and(Schema::hasColumn('appointments', 'complaint_details'))->toBeTrue()
        ->and(Schema::hasColumn('appointments', 'qr_code_token'))->toBeTrue();
});

test('a valid booking stores structured intake data and returns a QR URL', function () {
    telemedBookingTables();

    $patient = makePatient();
    $service = createTelemedTestService();
    $date = Carbon::today()->addDay()->startOfDay();

    $response = $this->withSession(['patient_id' => $patient->id])
        ->postJson('/telemed/book', telemedBookingPayload($service, $date))
        ->assertCreated()
        ->assertJsonPath('appointment.service_name', 'Family Medicine')
        ->assertJsonPath('appointment.date', $date->toDateString())
        ->assertJsonPath('appointment.time_slot', '08:00 - 10:00');

    $appointment = Appointment::where('patient_id', $patient->id)->firstOrFail();

    expect($appointment->consultation_reason)->toBe('general_check_up')
        ->and($appointment->symptoms)->toBe(['headache', 'dizziness'])
        ->and($appointment->complaint_details)->toBe('Persistent headache since yesterday.')
        ->and($appointment->qr_code_token)->toHaveLength(64)
        ->and($response->json('appointment.qr_code_url'))
        ->toBe(route('telemed.appointment.qr', $appointment, false));

    $pagePatient = makePatient([
        'username' => 'booking-page-patient',
        'contact_number' => '09170000008',
    ]);

    $this->withSession(['patient_id' => $pagePatient->id])
        ->get(route('telemed.book'))
        ->assertOk()
        ->assertSee('Ano ang ipapakonsulta? (Pumili ng Isa)', false)
        ->assertSee('Please select at least 1 and maximum of 3 symptoms.', false)
        ->assertSee('Enter here...', false);

    $this->withSession(['patient_id' => $patient->id])
        ->get(route('telemed.mine'))
        ->assertOk()
        ->assertSee('QR Code', false)
        ->assertSee('General check-up', false);
});

test('pending and confirmed appointments block another telemedicine booking', function (string $status) {
    telemedBookingTables();

    $patient = makePatient();
    $service = createTelemedTestService();
    $date = Carbon::today()->addDay()->startOfDay();

    $activeAppointment = Appointment::create([
        'patient_id' => $patient->id,
        'service_id' => $service->id,
        'complaint' => 'Existing appointment',
        'date' => $date->toDateString(),
        'time_slot' => '08:00 - 10:00',
        'status' => $status,
        'mode' => 'TELE',
        'qr_code_token' => str_repeat('a', 64),
    ]);

    $this->withSession(['patient_id' => $patient->id])
        ->postJson('/telemed/consent', ['service_id' => $service->id])
        ->assertCreated()
        ->assertJsonPath('active_appointment.id', $activeAppointment->id)
        ->assertJsonPath('active_appointment.date', $date->toDateString())
        ->assertJsonPath('active_appointment.time_slot', '08:00 - 10:00');

    $this->withSession(['patient_id' => $patient->id])
        ->get('/telemed')
        ->assertOk()
        ->assertSee('You already have an active appointment', false)
        ->assertSee('Cancel or complete it before booking another visit.', false)
        ->assertDontSee('id="bookingModal"', false);

    $this->withSession(['patient_id' => $patient->id])
        ->postJson('/telemed/book', telemedBookingPayload($service, $date))
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            "You already have an active appointment on {$date->toDateString()} at 08:00 - 10:00. Cancel or complete it before booking another visit."
        );

    expect(Appointment::where('patient_id', $patient->id)->count())->toBe(1);
})->with(['Booked', 'Pending', 'Confirmed']);

test('the patient QR is an SVG containing a kiosk verification token', function () {
    telemedBookingTables();

    $patient = makePatient();
    $otherPatient = makePatient([
        'username' => 'other-qr-patient',
        'contact_number' => '09170000009',
    ]);
    $service = createTelemedTestService();
    $date = Carbon::today()->addDay()->startOfDay();

    $this->withSession(['patient_id' => $patient->id])
        ->postJson('/telemed/book', telemedBookingPayload($service, $date))
        ->assertCreated();

    $appointment = Appointment::where('patient_id', $patient->id)->firstOrFail();
    $qrCode = new AppointmentQrCode;

    $this->withSession(['patient_id' => $patient->id])
        ->get(route('telemed.appointment.qr', $appointment))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')
        ->assertSee('<svg', false);

    expect($qrCode->payload($appointment))->toBe(
        "Appointment ID: {$appointment->id}\nVerification: {$appointment->qr_code_token}"
    );

    $this->withSession(['patient_id' => $otherPatient->id])
        ->get(route('telemed.appointment.qr', $appointment))
        ->assertForbidden();
});

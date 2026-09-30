@extends('layouts.app')

@section('title', 'Request an Appointment')

@section('styles')
    .standalone-booking-intro {
        max-width: 760px;
        margin-bottom: 1rem;
        padding: 1rem 1.15rem;
        border: 1px solid #cfe1f4;
        border-radius: 14px;
        background: #f4f9ff;
        color: #315a8b;
    }

    .standalone-booking-card {
        padding: 1.25rem;
        border: 0;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
    }

    .standalone-booking-actions {
        display: flex;
        justify-content: flex-end;
        gap: .75rem;
        margin-top: 1rem;
    }

    .standalone-active-summary {
        max-width: 720px;
        margin: 0 auto;
        padding: 2rem;
        border: 1px solid #f0cf8b;
        border-radius: 16px;
        background: #fffaf0;
        box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
        text-align: center;
    }

    .standalone-active-summary > i {
        color: #d88a0b;
        font-size: 3rem;
    }

    .standalone-active-summary h2 {
        margin: .75rem 0;
        color: #173f6b;
        font-size: 1.35rem;
        font-weight: 700;
    }

    .standalone-active-summary p {
        color: #64748b;
    }

    .booking-reason-card {
        cursor: pointer;
        transition: all .15s ease;
    }

    .booking-reason-card:hover {
        border-color: #0d6efd !important;
        background: #f0f7ff !important;
    }

    .booking-reason-card input:checked + span {
        color: #0d6efd;
        font-weight: 600;
    }

    .booking-symptom-card {
        cursor: pointer;
        transition: all .15s ease;
    }

    .booking-symptom-card:hover {
        border-color: #0d6efd !important;
        background: #f0f7ff !important;
    }

    .booking-symptom-card input:checked + span {
        color: #0d6efd;
        font-weight: 600;
    }

    @media (max-width: 575.98px) {
        .standalone-booking-card,
        .standalone-active-summary {
            padding: 1rem;
        }

        .standalone-booking-actions {
            align-items: stretch;
            flex-direction: column;
        }
    }
@endsection

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
            <h1 class="page-title h3 mb-0">Request an Appointment</h1>
            <p class="page-subtitle mb-0">QMMC &middot; Consultation Request</p>
        </div>
        <a href="{{ route('telemed.home') }}" class="btn btn-outline-secondary btn-pill">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
        </a>
    </div>

    @if ($activeAppointment)
        <section class="standalone-active-summary" aria-labelledby="standaloneActiveTitle">
            <i class="bi bi-calendar2-x-fill" aria-hidden="true"></i>
            <h2 id="standaloneActiveTitle">Request not available yet</h2>
            <p>
                You already have an active appointment on
                <strong>{{ $activeAppointment['date'] ?? '—' }}</strong>
                at <strong>{{ $activeAppointment['time_slot'] ?? '—' }}</strong>.
                Wait for it to be completed before requesting another visit.
            </p>
            <div class="standalone-booking-actions">
                <a href="{{ route('telemed.mine') }}" class="btn btn-outline-primary btn-pill">
                    View appointment
                </a>
            </div>
        </section>
    @elseif ($pendingFaceRequest)
        <section class="standalone-active-summary" aria-labelledby="standaloneActiveTitle">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <h2 id="standaloneActiveTitle">You have a pending face-to-face request</h2>
            <p>
                You already have a pending face-to-face consultation request
                (<strong>{{ \App\ConsultationReason::tryFrom($pendingFaceRequest->consultation_reason)?->label() ?? 'Face-to-Face Consultation' }}</strong>)
                submitted on <strong>{{ $pendingFaceRequest->created_at?->format('M j, Y h:i A') }}</strong>.
                Please wait for the triage team to process it before submitting another request.
            </p>
            <div class="standalone-booking-actions">
                <a href="{{ route('telemed.mine') }}" class="btn btn-outline-primary btn-pill">
                    View my requests
                </a>
            </div>
        </section>
    @else
        <div class="standalone-booking-intro">
            <strong class="d-block mb-1">How to request a consultation:</strong>
            Choose what you need to consult about. Our triage team will review your request and schedule your visit.
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                Please correct the highlighted booking details.
            </div>
        @endif

        <form class="booking-card standalone-booking-card" action="{{ route('telemed.book.store') }}" method="POST" data-request-form>
            @csrf

            <fieldset class="booking-intake-section">
                <legend class="booking-step-label">
                    <span>1</span>
                    <span>
                        Ano ang ipapakonsulta? (Pumili ng Isa)
                        <small>What would you like to consult about? (Choose one)</small>
                    </span>
                </legend>

                <div class="booking-reason-grid">
                    @foreach (($consultationReasons ?? []) as $reasonValue => $reasonLabel)
                        <label class="booking-choice-card booking-reason-card">
                            <input
                                type="radio"
                                name="consultation_reason"
                                value="{{ $reasonValue }}"
                                @checked(old('consultation_reason') === $reasonValue)
                                data-booking-reason
                                required
                            >
                            <span>{{ $reasonLabel }}</span>
                        </label>
                    @endforeach
                </div>
                @error('consultation_reason')
                    <p class="booking-field-error" role="alert">{{ $message }}</p>
                @enderror
            </fieldset>

            <div data-symptom-section hidden>
                <fieldset class="booking-intake-section">
                    <legend class="booking-step-label booking-symptom-heading">
                        <span>2</span>
                        <span>
                            Please select at least 1 and maximum of 3 symptoms.
                            <small lang="fil">Pumili ng Isa o hanggang sa Tatlong Sintomas</small>
                        </span>
                        <strong data-booking-symptom-count aria-live="polite">0 / 3 selected</strong>
                    </legend>

                    <div class="booking-symptom-grid" data-booking-symptoms>
                        @foreach (($symptoms ?? []) as $symptomValue => $symptomLabel)
                            <label class="booking-choice-card booking-symptom-card">
                                <input
                                    type="checkbox"
                                    name="symptoms[]"
                                    value="{{ $symptomValue }}"
                                    @checked(in_array($symptomValue, old('symptoms', []), true))
                                    data-booking-symptom
                                >
                                <span>{{ $symptomLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('symptoms')
                        <p class="booking-field-error" role="alert">{{ $message }}</p>
                    @enderror
                    @error('symptoms.*')
                        <p class="booking-field-error" role="alert">{{ $message }}</p>
                    @endforeach
                </fieldset>

                <fieldset class="booking-intake-section">
                    <legend class="booking-step-label">
                        <span>3</span>
                        <span>
                            Details about your Complaint
                            <small lang="fil">Magbigay ng konting detalye ukol sa inyong karamdaman</small>
                        </span>
                    </legend>

                    <label class="visually-hidden" for="bookingComplaintDetails">Details about your complaint</label>
                    <textarea
                        class="booking-complaint-input"
                        id="bookingComplaintDetails"
                        name="complaint_details"
                        rows="5"
                        maxlength="2000"
                        required
                        placeholder="Enter here..."
                        data-booking-complaint-details
                    >{{ old('complaint_details') }}</textarea>
                    <div class="booking-character-count">
                        <span>Keep the details clear and medically relevant.</span>
                        <span data-booking-detail-count>0 / 2000</span>
                    </div>
                    @error('complaint_details')
                        <p class="booking-field-error" role="alert">{{ $message }}</p>
                    @endforeach
                </fieldset>
            </div>

            <div class="booking-intake-error" data-booking-intake-error hidden role="alert"></div>

            <div class="standalone-booking-actions" data-request-actions hidden>
                <a href="{{ route('telemed.home') }}" class="btn btn-outline-secondary btn-pill">Cancel</a>
                <button type="submit" class="btn btn-success btn-pill" data-booking-submit>
                    <i class="bi bi-send-check me-1" aria-hidden="true"></i>Submit Request
                </button>
            </div>
        </form>
    @endif

    @include('partials.active-appointment-modal', ['autoOpenActiveModal' => true])
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('[data-request-form]');
            if (!form) return;

            const reasonInputs = Array.from(form.querySelectorAll('[data-booking-reason]'));
            const symptomSection = form.querySelector('[data-symptom-section]');
            const requestActions = form.querySelector('[data-request-actions]');
            const symptomInputs = Array.from(form.querySelectorAll('[data-booking-symptom]'));
            const complaintDetails = form.querySelector('[data-booking-complaint-details]');
            const symptomCount = form.querySelector('[data-booking-symptom-count]');
            const detailCount = form.querySelector('[data-booking-detail-count]');
            const intakeError = form.querySelector('[data-booking-intake-error]');
            const submitButton = form.querySelector('[data-booking-submit]');

            const NONE_VALUE = 'none_of_the_above';

            const selectedSymptomCount = () => symptomInputs.filter((input) => input.checked).length;

            const showError = (message) => {
                if (!intakeError) return;
                intakeError.textContent = message;
                intakeError.hidden = false;
            };

            const clearError = () => {
                if (!intakeError) return;
                intakeError.textContent = '';
                intakeError.hidden = true;
            };

            const updateSymptomCount = () => {
                if (symptomCount) {
                    const count = selectedSymptomCount();
                    symptomCount.textContent = `${count} / 3 selected`;
                    symptomCount.classList.toggle('limit-reached', count === 3);
                }
            };

            const updateDetailCount = () => {
                if (detailCount && complaintDetails) {
                    detailCount.textContent = `${complaintDetails.value.length} / 2000`;
                }
            };

            const isSymptomFlowComplete = () => {
                const count = selectedSymptomCount();
                const details = complaintDetails?.value.trim() ?? '';
                return count >= 1 && count <= 3 && details.length >= 3;
            };

            const updateSubmitState = () => {
                if (submitButton) {
                    submitButton.disabled = !isSymptomFlowComplete();
                }
            };

            reasonInputs.forEach((input) => {
                input.addEventListener('change', () => {
                    clearError();
                    const isNone = input.value === NONE_VALUE;

                    if (symptomSection) {
                        symptomSection.hidden = !isNone;
                    }
                    if (requestActions) {
                        requestActions.hidden = false;
                    }

                    if (!isNone) {
                        // Specific consultation type → auto-submit face-to-face request.
                        form.submit();
                        return;
                    }

                    updateSymptomCount();
                    updateDetailCount();
                    updateSubmitState();
                });
            });

            symptomInputs.forEach((input) => {
                input.addEventListener('change', () => {
                    const exceededLimit = input.checked && selectedSymptomCount() > 3;
                    if (exceededLimit) {
                        input.checked = false;
                        showError('Please select no more than 3 symptoms.');
                    } else {
                        clearError();
                    }
                    updateSymptomCount();
                    updateSubmitState();
                });
            });

            complaintDetails?.addEventListener('input', () => {
                updateDetailCount();
                updateSubmitState();
            });

            updateSymptomCount();
            updateDetailCount();
            updateSubmitState();
        })();
    </script>
@endpush

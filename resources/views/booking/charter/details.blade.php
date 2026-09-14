{{--
    Charter, step 3 — the summary, and who the booking belongs to.

    Follows the app's "Your details" screen: everything the customer has chosen, priced,
    then the three fields the dispatcher needs and a box for anything they want to say.

    A VISITOR SEES THIS PAGE. Not the form — a sign-in button in its place, and the same
    summary above it. The client's instruction of 2026-08-21 is that an account is asked
    for once, at the end; bouncing a visitor to the login screen from middleware would
    ask for it without ever showing them what they were signing in for.

    The draft lives in the session, so it survives the trip out to the login or
    registration screens and back — including a customer who registers and reads a
    verification email before returning.
--}}
@extends('layouts.public')

@section('title', __('lang.your_details'))

@section('head')
        @include('booking.partials.reset-on-reload')
@endsection

@section('content')
<div class="page booking-page">
    @include('booking.charter.steps', ['current' => 3])

    <div class="page-head">
        <h1 class="page-title">{{ __('lang.your_details') }}</h1>
        <p class="page-subtitle">{{ __('lang.details_step_lead') }}</p>
    </div>

    @if ($errors->has('booking'))
        <ul class="form-error">
            @foreach ($errors->get('booking') as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    <div class="card summary-card">
        <div class="card-body">
            <h2 class="summary-title">{{ __('lang.booking_summary') }}</h2>

            <dl class="review-list">
                <div>
                    <dt>{{ __('lang.service') }}</dt>
                    <dd>{{ $services->charter() }}</dd>
                </div>

                <div>
                    <dt>{{ __('lang.pickup_address') }}</dt>
                    <dd>{{ $draft['pickupAddress'] }}</dd>
                </div>

                @foreach ((array) ($draft['dropoffAddresses'] ?? []) as $index => $stop)
                    <div>
                        <dt>
                            {{ count($draft['dropoffAddresses']) > 1
                                ? __('lang.drop_off_numbered', ['number' => $index + 1])
                                : __('lang.drop_off_address') }}
                        </dt>
                        <dd>{{ $stop }}</dd>
                    </div>
                @endforeach

                <div>
                    <dt>{{ __('lang.car_type') }}</dt>
                    <dd>{{ $vehicleType['name'] ?? '' }}</dd>
                </div>

                <div>
                    <dt>{{ __('lang.passengers') }}</dt>
                    <dd>{{ $draft['passengers'] }}</dd>
                </div>

                <div>
                    <dt>{{ __('lang.trip_type') }}</dt>
                    <dd>{{ ($draft['tripType'] ?? 'one_way') === 'round_trip' ? __('lang.round_trip') : __('lang.one_way') }}</dd>
                </div>

                <div>
                    <dt>{{ __('lang.date_and_time') }}</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse($draft['travelDate'])->format('d/m/Y') }} · {{ $draft['pickupTime'] }}</dd>
                </div>

                @if (! empty($draft['returnDate']))
                    <div>
                        <dt>{{ __('lang.return_date') }}</dt>
                        <dd>{{ \Illuminate\Support\Carbon::parse($draft['returnDate'])->format('d/m/Y') }}</dd>
                    </div>
                @endif

                <div>
                    <dt>{{ __('lang.distance') }}</dt>
                    <dd>
                        {{ rtrim(rtrim(number_format($quote->distanceKm, 1, '.', ','), '0'), '.') }} km
                        @if (($draft['tripType'] ?? 'one_way') === 'round_trip')
                            <span class="summary-hint">{{ __('lang.there_and_back') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="summary-total">
                <span>{{ __('lang.cost') }}</span>
                <strong>{{ $money->format($quote->total) }}</strong>
            </div>

            <p class="form-help">
                {{ __('lang.days_times_rate', ['days' => $quote->billableDays, 'rate' => $money->format($quote->dailyRate)]) }}
                @if ($quote->distanceCost > 0)
                    + {{ __('lang.km_times_rate', [
                        'km' => rtrim(rtrim(number_format($quote->distanceKm, 1, '.', ','), '0'), '.'),
                        'rate' => $money->format($quote->perKmRate),
                    ]) }}
                @endif
            </p>

            @include('booking.partials.coupon', [
                'type' => 'charter',
                'code' => $couponCode,
                'subtotal' => $quote->total,
                'discount' => $discount,
            ])

            @if ($quote->distanceDays > $quote->dateDays)
                {{-- Worth naming: the customer asked for one day and is being charged
                     for two, and the reason is the distance. --}}
                <p class="form-help">
                    {{ __('lang.billed_for_distance_days', ['days' => $quote->billableDays]) }}
                </p>
            @endif
        </div>
    </div>

    @auth
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('book.charter.confirm') }}" class="form">
                    @csrf

                    <h2 class="form-section">{{ __('lang.your_details') }}</h2>

                    <div class="form-field">
                        <label for="customerName" class="form-label">{{ __('lang.full_name') }}</label>
                        <input class="form-input" id="customerName" name="customerName" type="text" maxlength="120" value="{{ old('customerName', $customer['customerName']) }}" data-required-message="{{ __('lang.enter_customer_name') }}" required>
                        @if ($errors->has('customerName'))
                            <ul class="form-error">
                                @foreach ($errors->get('customerName') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="booking-grid">
                        <div class="form-field">
                            <label for="customerPhone" class="form-label">{{ __('lang.phone_number') }}</label>

                            @include('partials.phone-field', [
                                'id' => 'customerPhone',
                                'name' => 'customerPhone',
                                'value' => old('customerPhone', $customer['customerPhone']),
                            ])
                            {{-- Required where the app leaves email optional: a
                                 charter is a driver meeting somebody at an address,
                                 and the dispatcher rings on the morning. --}}
                            <p class="form-help">{{ __('lang.phone_used_to_confirm') }}</p>
                            @if ($errors->has('customerPhone'))
                                <ul class="form-error">
                                    @foreach ($errors->get('customerPhone') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="form-field">
                            <label for="customerEmail" class="form-label">{{ __('lang.email_optional') }}</label>
                            <input class="form-input" id="customerEmail" name="customerEmail" type="email" maxlength="191" value="{{ old('customerEmail', $customer['customerEmail']) }}">
                            @if ($errors->has('customerEmail'))
                                <ul class="form-error">
                                    @foreach ($errors->get('customerEmail') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    {{-- ONE NAME PER PASSENGER, as on the shuttle.

                         Client's rule, 2026-09-02. The party size is set on step 2 and
                         cannot change here, so the boxes are rendered server-side and no
                         script is involved.

                         Separate from the contact name above: the person paying is not
                         assumed to be travelling — a company books a car for its own
                         guests, and one real charter booking in the client's Firestore
                         already carries a list that is not the customer. --}}
                    <h2 class="form-section">{{ __('lang.passenger_names') }}</h2>

                    <p class="form-help">{{ __('lang.passenger_names_hint_charter') }}</p>

                    @for ($seat = 0; $seat < max(1, (int) ($draft['passengers'] ?? 1)); $seat++)
                        <div class="form-field">
                            <label for="passenger-{{ $seat }}" class="form-label">
                                {{ __('lang.passenger_number', ['number' => $seat + 1]) }}
                            </label>

                            <input class="form-input" id="passenger-{{ $seat }}"
                                   name="passengerNames[]" type="text" maxlength="120" required
                                   value="{{ old('passengerNames.'.$seat, $draft['passengerNames'][$seat] ?? '') }}"
                                   data-required-message="{{ __('lang.enter_passenger_name') }}">

                            {{-- Keyed by index, so the message sits under the box it is
                                 about rather than all of them under the last one. --}}
                            @if ($errors->has('passengerNames.'.$seat))
                                <ul class="form-error">
                                    @foreach ($errors->get('passengerNames.'.$seat) as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endfor

                    @if ($errors->has('passengerNames'))
                        <ul class="form-error">
                            @foreach ($errors->get('passengerNames') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="form-field">
                        <label for="notes" class="form-label">{{ __('lang.notes') }}</label>
                        <textarea id="notes" name="notes" class="form-input" rows="3" maxlength="1000"
                                  placeholder="{{ __('lang.optional_specific_instructions') }}">{{ old('notes', $draft['notes'] ?? '') }}</textarea>
                        @if ($errors->has('notes'))
                            <ul class="form-error">
                                @foreach ($errors->get('notes') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <p class="form-help">
                        {{ __('lang.booking_is_a_request') }}
                    </p>

                    <div class="row is-between is-wrap booking-actions">
                        <a href="{{ route('book.charter.vehicle') }}" class="btn btn-ghost">{{ __('lang.back') }}</a>
                        <button type="submit" class="btn btn-primary">{{ __('lang.confirm_booking') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        {{-- The gate, and the only place an account becomes necessary. The price is
             above it: nobody hands over an email address for a number they have not
             been shown. --}}
        <div class="card">
            <div class="card-body stack">
                <h2 class="form-section">{{ __('lang.almost_there') }}</h2>

                <p>{{ __('lang.login_to_complete_booking') }}</p>

                <div class="row is-wrap">
                    <a href="{{ route('login') }}" class="btn btn-primary">{{ __('lang.log_in') }}</a>
                    <a href="{{ route('register') }}" class="btn btn-secondary">{{ __('lang.create_an_account') }}</a>
                </div>
            </div>
        </div>

        <div class="row is-between is-wrap booking-actions">
            <a href="{{ route('book.charter.vehicle') }}" class="btn btn-ghost">{{ __('lang.back') }}</a>
        </div>
    @endauth
</div>

    <script src="{{ asset('js/coupon.js') }}?v={{ is_file(public_path('js/coupon.js')) ? filemtime(public_path('js/coupon.js')) : '' }}"></script>

    @include('partials.form-validation')
@endsection

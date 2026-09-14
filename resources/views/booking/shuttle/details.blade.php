{{--
    Shuttle, step 3 — the summary, and who the booking belongs to.

    A VISITOR SEES THIS PAGE. Not the form — a sign-in button in its place, and the same
    summary above it. The client's instruction of 2026-08-21: an account is asked for once,
    at the end, and never before a price has been shown.

    The seats-left line is information, NOT a promise. A seat is only taken when an
    operator accepts the order, so the number can fall between now and them looking at it,
    and a customer who books the last seat can still be turned down. That is the client's
    own rule and it is said here, at the point of booking, rather than in an email after.
--}}
@extends('layouts.public')

@section('title', __('lang.your_details'))

@section('head')
    @include('booking.partials.reset-on-reload', ['restart' => 'book.shuttle.restart'])
@endsection

@section('content')
<div class="page booking-page">
    @include('booking.shuttle.steps', ['current' => 3])

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
                    <dd>{{ $services->shuttle() }}</dd>
                </div>

                <div>
                    <dt>{{ __('lang.direction') }}</dt>
                    <dd>
                        {{ $draft['direction'] === 'from_airport'
                            ? __('lang.from_the_airport')
                            : __('lang.to_the_airport') }}
                    </dd>
                </div>

                <div>
                    <dt>{{ __('lang.airport') }}</dt>
                    <dd>{{ $rate['airportName'] ?? '' }}</dd>
                </div>

                <div>
                    <dt>{{ __('lang.city') }}</dt>
                    <dd>{{ $rate['cityGroupName'] ?? '' }}</dd>
                </div>

                <div>
                    {{-- Drop point leaving the airport, PICKUP POINT going to it. --}}
                    <dt>{{ ($draft['direction'] ?? '') === 'to_airport' ? __('lang.pickup_point') : __('lang.drop_point') }}</dt>
                    <dd>{{ $rate['dropPoint'] ?? '' }}</dd>
                </div>

                <div>
                    <dt>{{ __('lang.date_and_time') }}</dt>
                    <dd>
                        {{ \Illuminate\Support\Carbon::parse($draft['travelDate'])->format('d/m/Y') }}
                        · {{ $draft['pickupTime'] }}
                    </dd>
                </div>

                <div>
                    <dt>{{ __('lang.seats') }}</dt>
                    <dd>{{ $passengers }}</dd>
                </div>

                @if (! empty($draft['luggage']))
                    <div>
                        <dt>{{ __('lang.luggage') }}</dt>
                        <dd>{{ $draft['luggage'] }}</dd>
                    </div>
                @endif

                @if (! empty($draft['flightDetails']))
                    <div>
                        <dt>{{ __('lang.flight_details') }}</dt>
                        <dd>{{ $draft['flightDetails'] }}</dd>
                    </div>
                @endif
            </dl>

            <div class="summary-total">
                <span>{{ __('lang.cost') }}</span>
                <strong>
                    @if ($rate['isCustomQuote'] ?? false)
                        {{ __('lang.quoted_per_booking') }}
                    @else
                        {{ $money->format($total) }}
                    @endif
                </strong>
            </div>

            @unless ($rate['isCustomQuote'] ?? false)
                <p class="form-help">
                    {{ __('lang.count_times_fare', ['count' => $passengers, 'fare' => $money->format($fare)]) }}
                </p>

                {{-- Not on a route quoted per booking: there is no published price for a
                     percentage to work on, and a fixed amount off an unknown total is a
                     promise nobody can keep. --}}
                @include('booking.partials.coupon', [
                    'type' => 'shuttle',
                    'code' => $couponCode,
                    'subtotal' => $total,
                    'discount' => $discount,
                ])
            @endunless

            {{-- Never a promise. See the file comment. --}}
            <div class="pay-pending">
                <p class="pay-lead">{{ __('lang.seat_held_on_acceptance') }}</p>

                @if ($cancelled)
                    <p class="form-help">{{ __('lang.cancelled_departure') }}</p>
                @elseif ($seatsLeft !== null)
                    <p class="form-help">
                        @if ($seatsLeft <= 0)
                            {{ __('lang.sold_out') }}
                        @elseif ($seatsLeft < $passengers)
                            {{ __('lang.shuttle_seats_short_warning', ['left' => $seatsLeft, 'asked' => $passengers]) }}
                        @else
                            {{ __('lang.seats_left', ['count' => $seatsLeft]) }}
                        @endif
                    </p>
                @endif
            </div>
        </div>
    </div>

    @auth
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('book.shuttle.confirm') }}" class="form">
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

                    {{-- ONE NAME PER SEAT.

                         The client's rule, 2026-09-02, and the mobile app's behaviour: a
                         shuttle is a shared vehicle, the driver has a list, and a seat
                         with no name on it is a seat nobody can be checked into.

                         Separate from the contact name above rather than assuming the
                         person paying is travelling — a hotel books a guest's transfer,
                         and one live booking has "Test User" booking three seats for
                         three other people.

                         The count comes from step 2 and cannot change here, so the
                         fields are rendered server-side and no script is involved. --}}
                    <h2 class="form-section">{{ __('lang.passenger_names') }}</h2>

                    <p class="form-help">{{ __('lang.passenger_names_hint') }}</p>

                    @for ($seat = 0; $seat < $passengers; $seat++)
                        <div class="form-field">
                            <label for="passenger-{{ $seat }}" class="form-label">
                                {{ __('lang.passenger_number', ['number' => $seat + 1]) }}
                            </label>

                            <input class="form-input" id="passenger-{{ $seat }}"
                                   name="passengerNames[]" type="text" maxlength="120" required
                                   value="{{ old('passengerNames.'.$seat, $draft['passengerNames'][$seat] ?? '') }}"
                                   data-required-message="{{ __('lang.enter_passenger_name') }}">

                            {{-- Keyed by index: the server reports `passengerNames.0`,
                                 and the message belongs under the box it is about
                                 rather than all of them under the last one. --}}
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

                    <p class="form-help">{{ __('lang.booking_is_a_request') }}</p>

                    <div class="row is-between is-wrap booking-actions">
                        <a href="{{ route('book.shuttle.trip') }}" class="btn btn-ghost">{{ __('lang.back') }}</a>
                        <button type="submit" class="btn btn-primary">{{ __('lang.confirm_booking') }}</button>
                    </div>
                </form>
            </div>
        </div>
    @else
        {{-- The gate, and the only place an account becomes necessary. The fare is above
             it: nobody hands over an email address for a number they have not seen. --}}
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
            <a href="{{ route('book.shuttle.trip') }}" class="btn btn-ghost">{{ __('lang.back') }}</a>
        </div>
    @endauth

        <script src="{{ asset('js/coupon.js') }}?v={{ is_file(public_path('js/coupon.js')) ? filemtime(public_path('js/coupon.js')) : '' }}"></script>

    @include('partials.form-validation')
</div>
@endsection

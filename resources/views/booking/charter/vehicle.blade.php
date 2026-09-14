{{--
    Charter, step 2 — the party, the vehicle, and when.

    One screen, following the mobile app: the four answers are one decision. The party
    decides which vehicles are offered, the vehicle and the dates decide the price, and
    the trip type decides both the dates asked for and the distance billed. Splitting
    them put a page load between a customer changing their mind and seeing what it cost.

    The month grid is the panel's, and the app's before that: every day says how many of
    the chosen vehicle are free, so a sold-out weekend is visible while the customer is
    choosing rather than discovered by a refusal afterwards. ONE grid, not two — a return
    trip selects a range on the same month, as it does in the app.

    Everything here works without JavaScript: a native number field, a select, radio
    buttons for the trip type and two date fields. The script replaces the last two with
    the grid and adds the running estimate.
--}}
@extends('layouts.public')

@section('title', __('lang.book_charter'))

@section('head')
        @include('booking.partials.reset-on-reload')
@endsection

@section('content')
<div class="page booking-page">
    @include('booking.charter.steps', ['current' => 2])

    <div class="page-head">
        <h1 class="page-title">{{ __('lang.vehicle_and_dates') }}</h1>
        <p class="page-subtitle">{{ __('lang.vehicle_step_lead') }}</p>
    </div>

    @if ($errors->has('booking'))
        <ul class="form-error">
            @foreach ($errors->get('booking') as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    @if (empty($types))
        {{-- Not an error page: the admin has published no charter vehicle, and the
             honest answer is to say so and offer a way through. --}}
        <div class="card">
            <div class="card-body stack">
                <p>{{ __('lang.no_vehicles_published') }}</p>
                @if ($whatsapp = $site->whatsapp())
                    <div class="row is-wrap">
                        <a href="https://wa.me/{{ ltrim(preg_replace('/[^0-9+]/', '', $whatsapp), '+') }}"
                           class="btn btn-primary" rel="noopener">{{ __('lang.ask_us_directly') }}</a>
                    </div>
                @endif
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('book.charter.vehicle') }}" class="form" id="charter-trip-form">
            @csrf

            <div class="card">
                <div class="card-body">
                    <h2 class="form-section">{{ __('lang.vehicle') }}</h2>

                    <div class="booking-grid">
                        <div class="form-field">
                            <label for="passengers" class="form-label">{{ __('lang.passengers') }}</label>
                            <input class="form-input" id="passengers" name="passengers" type="number" min="1" max="60" value="{{ old('passengers', $draft['passengers'] ?? 1) }}" data-required-message="{{ __('lang.enter_how_many_people_travelling') }}" required autofocus>
                            <p class="form-help">{{ __('lang.only_vehicles_that_seat_all') }}</p>
                            @if ($errors->has('passengers'))
                                <ul class="form-error">
                                    @foreach ($errors->get('passengers') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="form-field">
                            <label for="vehicleTypeId" class="form-label">{{ __('lang.car_type') }}</label>

                            {{-- The plain select is what posts, and what a customer
                                 with no JavaScript uses. The script replaces it with
                                 a searchable dropdown — Select2's behaviour, without
                                 Select2: see public/js/searchable-select.js. --}}
                            <select id="vehicleTypeId" name="vehicleTypeId" class="form-select" required>
                                @foreach ($types as $id => $type)
                                    <option value="{{ $id }}"
                                            data-seats="{{ (int) ($type['seatCapacity'] ?? 0) }}"
                                            @selected(old('vehicleTypeId', $draft['vehicleTypeId'] ?? '') === $id)>
                                        {{ $type['name'] ?? '' }} · {{ __('lang.count_seats', ['count' => $type['seatCapacity'] ?? 0]) }}
                                    </option>
                                @endforeach
                            </select>

                            <div id="vehicle-type-picker" class="ss" hidden></div>

                            {{-- How many of that type are free on the chosen dates. --}}
                            <p class="form-help is-accent" id="vehicle-availability"></p>
                            @if ($errors->has('vehicleTypeId'))
                                <ul class="form-error">
                                    @foreach ($errors->get('vehicleTypeId') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="vehicleUnitId" class="form-label">{{ __('lang.vehicle') }}</label>

                        {{-- The vans of the chosen type that are free for the chosen
                             dates. Filled by the script from the same counts the
                             calendar is coloured with, so the list and the month can
                             never disagree.

                             Left EMPTY without a script: a customer who cannot see
                             which vans are free must not be made to name one, and the
                             reservation picks a free one when the field is blank. --}}
                        <select id="vehicleUnitId" name="vehicleUnitId" class="form-select">
                            <option value="">{{ __('lang.any_available_vehicle') }}</option>
                        </select>

                        <div id="vehicle-unit-picker" class="ss" hidden></div>

                        <p class="form-help" id="vehicle-unit-note"></p>
                        @if ($errors->has('vehicleUnitId'))
                            <ul class="form-error">
                                @foreach ($errors->get('vehicleUnitId') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="form-field">
                        <label class="form-label">{{ __('lang.trip_type') }}</label>

                        {{-- Radios styled as the app's two buttons. Radios rather
                             than buttons plus a hidden field so the choice posts,
                             and is keyboard-reachable, with no script at all. --}}
                        <div class="choice-row" role="group">
                            @php $trip = old('tripType', $draft['tripType'] ?? 'one_way'); @endphp

                            <label class="choice {{ $trip === 'one_way' ? 'is-chosen' : '' }}">
                                <input type="radio" name="tripType" value="one_way" @checked($trip === 'one_way')>
                                <span class="choice-label">{{ __('lang.one_way') }}</span>
                                <span class="choice-mark" aria-hidden="true">→</span>
                            </label>

                            <label class="choice {{ $trip === 'round_trip' ? 'is-chosen' : '' }}">
                                <input type="radio" name="tripType" value="round_trip" @checked($trip === 'round_trip')>
                                <span class="choice-label">{{ __('lang.round_trip') }}</span>
                                <span class="choice-mark" aria-hidden="true">⇄</span>
                            </label>
                        </div>

                        @if ($errors->has('tripType'))
                            <ul class="form-error">
                                @foreach ($errors->get('tripType') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="form-section">{{ __('lang.schedule') }}</h2>

                    <div class="form-field">
                        <label for="travelDate" class="form-label">{{ __('lang.travel_dates') }}</label>

                        {{-- The fallback, and the fields the form actually posts.
                             Hidden by the script the moment the grid is mounted. --}}
                        <div class="date-fallback" data-date-fallback>
                            <div class="booking-grid">
                                <div class="form-field">
                                    <label for="travelDate" class="form-label">{{ __('lang.start_date') }}</label>
                                    <input class="form-input" id="travelDate" name="travelDate" type="date" value="{{ old('travelDate', $draft['travelDate'] ?? '') }}" min="{{ $minDate }}" max="{{ $maxDate }}" required>
                                </div>

                                <div class="form-field">
                                    <label for="returnDate" class="form-label">{{ __('lang.return_date') }}</label>
                                    <input class="form-input" id="returnDate" name="returnDate" type="date" value="{{ old('returnDate', $draft['returnDate'] ?? '') }}" min="{{ $minDate }}" max="{{ $maxDate }}">
                                    <p class="form-help">{{ __('lang.round_trips_only') }}</p>
                                </div>
                            </div>
                        </div>

                        <div id="travel-calendar" class="daycal-mount" hidden></div>

                        {{-- What the grid is currently asking for, and what has been
                             chosen so far. Filled by the script. --}}
                        <p class="form-help" id="dates-note"></p>
                        <ul id="dates-error" class="form-error" hidden></ul>

                        @if ($errors->has('travelDate'))
                            <ul class="form-error">
                                @foreach ($errors->get('travelDate') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($errors->has('returnDate'))
                            <ul class="form-error">
                                @foreach ($errors->get('returnDate') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="form-field">
                        <label for="pickupTime" class="form-label">{{ __('lang.pickup_time') }}</label>
                        <input class="form-input" id="pickupTime" name="pickupTime" type="time" value="{{ old('pickupTime', $draft['pickupTime'] ?? '') }}" data-required-message="{{ __('lang.choose_pickup_time') }}" required>
                        @if ($errors->has('pickupTime'))
                            <ul class="form-error">
                                @foreach ($errors->get('pickupTime') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            {{-- The app's bottom bar: the running total, always in view on a phone.
                 An estimate and labelled as one — the operator re-checks the
                 distance before accepting, which is what `pending` is for. --}}
            <div class="estimate-bar">
                <div class="estimate-text">
                    <span class="estimate-label">{{ __('lang.estimated_cost') }}</span>
                    <span class="estimate-note" id="estimate-note"></span>
                </div>
                <span class="estimate-value" id="estimate-value">—</span>
            </div>

            <div class="row is-between is-wrap booking-actions">
                <a href="{{ route('book.charter') }}" class="btn btn-ghost">{{ __('lang.back') }}</a>
                <button type="submit" class="btn btn-primary">{{ __('lang.continue') }}</button>
            </div>
        </form>
    @endif
</div>

@if (! empty($types))
    <script>
        window.charterTrip = {
            calendar: @json($calendar),
            distanceKm: @json($distanceKm),
            roundTripKm: @json($roundTripKm),
            distanceDayThresholdKm: @json((int) config('bookings.distance_day_threshold_km', 500)),
            money: @json($money->parts()),
            types: @json($rates),
        };

        window.charterTripLabels = {
            oneFree: @json(__('lang.count_vehicle')),
            manyFree: @json(__('lang.count_vehicles')),
            noneFree: @json(__('lang.none_free')),
            legendFree: @json(__('lang.available')),
            legendLimited: @json(__('lang.limited_or_fewer')),
            legendNone: @json(__('lang.unavailable')),
            tooLate: @json(__('lang.too_far_ahead')),
            previousMonth: @json(__('lang.previous_month')),
            nextMonth: @json(__('lang.next_month')),
            available: @json(__('lang.count_free_on_your_dates')),
            anyVehicle: @json(__('lang.any_available_vehicle')),
            unitFree: @json(__('lang.free_on_your_dates')),
            unitTaken: @json(__('lang.taken_on_your_dates')),
            unitTakenShort: @json(__('lang.booked')),

            unitNone: @json(__('lang.no_unit_of_type_free')),
            unitAnyNote: @json(__('lang.vehicle_any_or_specific')),
            search: @json(__('lang.search')),
            noMatches: @json(__('lang.no_matches')),
            availableNone: @json(__('lang.none_free_on_your_dates')),
            availablePickDate: @json(__('lang.choose_dates_see_what_free')),
            pickStart: @json(__('lang.please_choose_travel_dates')),
            pickReturn: @json(__('lang.now_choose_day_come_back')),
            {{-- Used instead of the line above when the trip is short enough to
                 drive there and back in a day: tapping the start date again is
                 not a thing anybody guesses at, so the hint has to say it. --}}
            pickReturnOrSame: @json(__('lang.now_choose_day_come_back_or_same')),
            chosenOne: @json(__('lang.travelling_one_day')),
            chosenMany: @json(__('lang.travelling_many_days')),
            estimateNeedsDates: @json(__('lang.choose_your_dates')),
            estimateReturn: @json(__('lang.km_there_and_back')),
            estimateOneWay: @json(__('lang.km')),
            estimateDay: @json(__('lang.day_billed')),
            estimateDays: @json(__('lang.days_billed')),
        };
    </script>
    {{-- `searchable-select.js` calls `trackDropdownScroll` as it loads, so this has to
         come FIRST. It was missing here while the shuttle's step 1 loaded both, so the
         vehicle picker threw on this page and this page only. --}}
    <script src="{{ asset('js/dropdown-position.js') }}?v={{ is_file(public_path('js/dropdown-position.js')) ? filemtime(public_path('js/dropdown-position.js')) : '' }}"></script>
    <script src="{{ asset('js/searchable-select.js') }}?v={{ is_file(public_path('js/searchable-select.js')) ? filemtime(public_path('js/searchable-select.js')) : '' }}"></script>
    <script src="{{ asset('js/day-calendar.js') }}?v={{ is_file(public_path('js/day-calendar.js')) ? filemtime(public_path('js/day-calendar.js')) : '' }}"></script>
    <script src="{{ asset('js/charter-trip.js') }}?v={{ is_file(public_path('js/charter-trip.js')) ? filemtime(public_path('js/charter-trip.js')) : '' }}"></script>
    <script>
        (function () {
            'use strict';

            var mount = document.getElementById('travel-calendar');

            // Nothing is swapped until both scripts are known to be there. A failed
            // download leaves the page exactly as it rendered — a working form with
            // two native date fields.
            if (!window.initDayCalendar || !window.initCharterTrip || !mount) {
                return;
            }

            document.querySelectorAll('[data-date-fallback]').forEach(function (node) {
                node.hidden = true;
            });

            mount.hidden = false;

            window.initCharterTrip({
                data: window.charterTrip,
                labels: window.charterTripLabels,
                locale: document.documentElement.lang || 'en',
            });
        }());
    </script>
    @include('partials.form-validation')
@endif
@endsection

{{--
    Shuttle, step 2 — the date, the departure, and how many seats.

    The month grid colours a day by the BEST run on it, because the question a customer is
    asking while they look at a month is "can I travel that day at all". The run they then
    pick shows its own number beside it — a day coloured by its worst run would grey out a
    date with an empty bus on it.

    THE DEPARTURE IS A CLOSED LIST. It was a free field until 2026-08-27, on the client's
    instruction of 2026-08-21 — back when seats belonged to the whole day and the departure
    did not matter. It does now: a seat is counted against a RUN, a time on no timetable
    has no run, and a booking without one holds no seat while looking complete. The panel's
    own form closed this on 2026-08-25.

    Everything works without JavaScript: a native date field and radio buttons for the
    departures. The script replaces the date field with the grid and puts the seat counts
    on the departures.
--}}
@extends('layouts.public')

@section('title', __('lang.book_shuttle_seat'))

@section('head')
    @include('booking.partials.reset-on-reload', ['restart' => 'book.shuttle.restart'])
@endsection

@section('content')
<div class="page booking-page">
    @include('booking.shuttle.steps', ['current' => 2])

    <div class="page-head">
        <h1 class="page-title">{{ __('lang.date_and_seats') }}</h1>
        <p class="page-subtitle">
            {{ $rate['cityGroupName'] ?? '' }} · {{ $rate['dropPoint'] ?? '' }}
        </p>
    </div>

    @if ($errors->has('booking'))
        <ul class="form-error">
            @foreach ($errors->get('booking') as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('book.shuttle.trip') }}" class="form">
        @csrf

        <div class="card">
            <div class="card-body">
                <h2 class="form-section">{{ __('lang.schedule') }}</h2>

                <div class="form-field">
                    <label class="form-label">{{ __('lang.departure_time') }}</label>

                    @if (empty($times))
                        <p class="form-help">{{ __('lang.no_departures_scheduled') }}</p>
                    @else
                        {{-- One card per RUN, and they come BEFORE the calendar: the
                             departure is what a seat belongs to, so once it is chosen the
                             month below can count THAT bus rather than the best of them.

                             The value posted is the time, but the position is what the
                             seat is counted against — the server turns the time back into
                             its run index and refuses anything not on this stop's own
                             timetable. --}}
                        <div class="run-list" id="run-list">
                            @foreach ($times as $index => $time)
                                <label class="run" data-run="{{ $index }}">
                                    <input type="radio" name="pickupTime" value="{{ $time }}"
                                           @checked(old('pickupTime', $draft['pickupTime'] ?? '') === $time) required>
                                    <span class="run-body">
                                        <span class="run-time">{{ $time }}</span>
                                        <span class="run-seats" data-run-seats></span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <p class="form-help">{{ __('lang.departures_are_scheduled') }}</p>
                    @if ($errors->has('pickupTime'))
                        <ul class="form-error">
                            @foreach ($errors->get('pickupTime') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="form-field">
                    <label for="travelDate" class="form-label">{{ __('lang.when_are_you_travelling') }}</label>

                    {{-- The fallback, and the field the form actually posts. --}}
                    <div class="date-fallback" data-date-fallback>
                        <input class="form-input" id="travelDate" name="travelDate" type="date" value="{{ old('travelDate', $draft['travelDate'] ?? '') }}" min="{{ $minDate }}" max="{{ $maxDate }}" required data-required-message="{{ __('lang.please_choose_travel_dates') }}">
                    </div>

                    {{-- Coloured for the departure chosen above, when one has been; by the
                         emptiest bus of the day when none has. --}}
                    <p class="form-help" id="seats-scope"></p>

                    <div id="travel-calendar" class="daycal-mount" hidden></div>

                    <p class="form-help" id="dates-note"></p>
                    <ul id="dates-error" class="form-error" hidden></ul>

                    @if ($errors->has('travelDate'))
                        <ul class="form-error">
                            @foreach ($errors->get('travelDate') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="form-section">{{ __('lang.seats') }}</h2>

                <div class="booking-grid">
                    <div class="form-field">
                        <label for="passengers" class="form-label">{{ __('lang.how_many_seats') }}</label>
                        <input class="form-input" id="passengers" name="passengers" type="number" min="1" max="30" value="{{ old('passengers', $draft['passengers'] ?? 1) }}" required data-required-message="{{ __('lang.enter_how_many_seats') }}">
                        {{-- The rule the client is most likely to be asked about, said at
                             the point of booking rather than in an email afterwards. --}}
                        <p class="form-help">{{ __('lang.seat_held_on_acceptance') }}</p>
                        @if ($errors->has('passengers'))
                            <ul class="form-error">
                                @foreach ($errors->get('passengers') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="form-field">
                        <label for="luggage" class="form-label">{{ __('lang.luggage') }}</label>
                        <input class="form-input" id="luggage" name="luggage" type="number" min="0" max="30" value="{{ old('luggage', $draft['luggage'] ?? 0) }}">
                        @if ($errors->has('luggage'))
                            <ul class="form-error">
                                @foreach ($errors->get('luggage') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="form-field">
                    <label for="flightDetails" class="form-label">{{ __('lang.flight_details') }}</label>
                    <input class="form-input" id="flightDetails" name="flightDetails" type="text" maxlength="120" value="{{ old('flightDetails', $draft['flightDetails'] ?? '') }}" placeholder="{{ __('lang.flight_details_placeholder') }}">
                    <p class="form-help">{{ __('lang.flight_details_help') }}</p>
                    @if ($errors->has('flightDetails'))
                        <ul class="form-error">
                            @foreach ($errors->get('flightDetails') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- The fare, which is fixed per seat on this route. --}}
        <div class="estimate-bar">
            <div class="estimate-text">
                <span class="estimate-label">{{ __('lang.estimated_cost') }}</span>
                <span class="estimate-note" id="estimate-note"></span>
            </div>
            <span class="estimate-value" id="estimate-value">—</span>
        </div>

        <div class="row is-between is-wrap booking-actions">
            <a href="{{ route('book.shuttle') }}" class="btn btn-ghost">{{ __('lang.back') }}</a>

            <div class="continue-group">
                {{-- Why Continue is closed, kept current by the script. A disabled button
                     with no reason beside it is a dead end; this is what stops it being
                     one. Empty and hidden while the form is ready to send. --}}
                <p class="continue-note" id="continue-note" hidden></p>
                <button type="submit" class="btn btn-primary" id="shuttle-continue">{{ __('lang.continue') }}</button>
            </div>
        </div>
    </form>

    <script>
        window.shuttleTrip = {
            calendar: @json($calendar),
            fare: @json($fare),
            money: @json($money->parts()),
        };

        window.shuttleTripLabels = {
            oneSeat: @json(__('lang.one_seat_left')),
            manySeats: @json(__('lang.seats_left')),
            soldOut: @json(__('lang.sold_out')),
            notRunning: @json(__('lang.not_running')),
            unknownSeats: @json(__('lang.seats_unknown')),
            legendFree: @json(__('lang.available')),
            legendLimited: @json(__('lang.limited_or_fewer')),
            legendNone: @json(__('lang.unavailable')),
            tooLate: @json(__('lang.too_far_ahead')),
            previousMonth: @json(__('lang.previous_month')),
            nextMonth: @json(__('lang.next_month')),
            pickDate: @json(__('lang.please_choose_travel_dates')),
            pickRun: @json(__('lang.please_choose_a_departure')),
            seatsForRun: @json(__('lang.seats_for_run', ['time' => ':time'])),
            seatsBestRun: @json(__('lang.seats_best_run')),
            chosen: @json(__('lang.travelling_one_day')),
            estimateSeats: @json(__('lang.count_times_fare')),
            onlySeatsLeft: @json(__('lang.only_seats_left', ['count' => ':count'])),
            soldOutDeparture: @json(__('lang.sold_out_departure')),
            cancelledDeparture: @json(__('lang.cancelled_departure')),
        };
    </script>
    <script src="{{ asset('js/day-calendar.js') }}?v={{ is_file(public_path('js/day-calendar.js')) ? filemtime(public_path('js/day-calendar.js')) : '' }}"></script>
    <script src="{{ asset('js/shuttle-trip.js') }}?v={{ is_file(public_path('js/shuttle-trip.js')) ? filemtime(public_path('js/shuttle-trip.js')) : '' }}"></script>
    <script>
        (function () {
            'use strict';

            var mount = document.getElementById('travel-calendar');

            // A failed download leaves the page as it rendered: a working date field and
            // a list of real departures.
            if (!window.initDayCalendar || !window.initShuttleTrip || !mount) {
                return;
            }

            document.querySelectorAll('[data-date-fallback]').forEach(function (node) {
                node.hidden = true;
            });

            mount.hidden = false;

            window.initShuttleTrip({
                data: window.shuttleTrip,
                labels: window.shuttleTripLabels,
                locale: document.documentElement.lang || 'en',
            });
        }());
    </script>

    @include('partials.form-validation')
</div>
@endsection

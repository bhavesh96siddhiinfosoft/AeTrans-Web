{{--
    Shuttle, step 1 — which way, which airport, which city, which stop.

    The CITY is asked for before the stop because the city is what SEATS are counted
    against: all eight Ngawi stops share one bus and one row of seats on a given run. A
    customer choosing a stop without knowing its city would be comparing two stops that
    were never competing for different seats.

    Only `direction` and `rateId` are posted. The airport and city selects narrow the stop
    list and nothing more, so a browser with no JavaScript — where they do not filter —
    still produces a valid booking: the stop carries its own airport and city, and the
    server re-reads both from the rate.

    Each stop `<option>` carries its fare and timetable in data attributes. That is what
    the dropdown falls back to when the stop endpoint cannot be reached, so a dropped
    connection costs the live narrowing, not the ability to book.
--}}
@extends('layouts.public')

@section('title', __('lang.book_shuttle_seat'))

@section('head')
    @include('booking.partials.reset-on-reload', ['restart' => 'book.shuttle.restart'])
@endsection

@php
    /*
     * The stop is a DROP POINT leaving the airport and a PICKUP POINT going to it. It is
     * the same row of the timetable either way, but the customer is set down at one and
     * collected at the other — and "drop point" on the leg TO the airport is simply
     * wrong. Client's report, 2026-09-02.
     *
     * Both wordings are written out in full rather than assembled from the direction,
     * because the translation coverage scan reads this file as text: a key it cannot see
     * is a key it reports as dead.
     */
    $toAirport = $direction === 'to_airport';
    $stopLabel = $toAirport ? __('lang.pickup_point') : __('lang.drop_point');
    $stopPrompt = $toAirport ? __('lang.choose_a_pickup_point') : __('lang.choose_a_stop');
    $stopHint = $toAirport ? __('lang.choose_pickup_hint') : __('lang.choose_stop_hint');
    $seatsNote = $toAirport ? __('lang.city_shares_seats_pickup') : __('lang.city_shares_seats');
@endphp

@section('content')
<div class="page booking-page">
    @include('booking.shuttle.steps', ['current' => 1])

    <div class="page-head">
        <h1 class="page-title">{{ $services->shuttle() }}</h1>
        <p class="page-subtitle">{{ __('lang.shuttle_step_lead') }}</p>
    </div>

    @if ($errors->has('booking'))
        <ul class="form-error">
            @foreach ($errors->get('booking') as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    @endif

    @if (empty($rates))
        {{-- Not an error: the admin has published nothing on this leg yet. --}}
        <div class="card">
            <div class="card-body stack">
                <p>{{ __('lang.no_routes_in_direction') }}</p>

                <div class="row is-wrap">
                    <a href="{{ route('book.shuttle', ['direction' => $direction === 'from_airport' ? 'to_airport' : 'from_airport']) }}"
                       class="btn btn-secondary">{{ __('lang.to_or_from_airport') }}</a>

                    @if ($whatsapp = $site->whatsapp())
                        <a href="https://wa.me/{{ ltrim(preg_replace('/[^0-9+]/', '', $whatsapp), '+') }}"
                           class="btn btn-primary" rel="noopener">{{ __('lang.ask_us_directly') }}</a>
                    @endif
                </div>
            </div>
        </div>
    @else
        <form method="POST" action="{{ route('book.shuttle') }}" class="form">
            @csrf

            <div class="card">
                <div class="card-body">
                    <h2 class="form-section">{{ __('lang.direction') }}</h2>

                    {{-- Links, not radios: changing direction changes which airports,
                         cities and stops exist, and that list is built on the server. A
                         radio would leave the page showing the other leg's routes. --}}
                    <div class="segmented" role="group" aria-label="{{ __('lang.direction') }}">
                        <a class="segment {{ $direction === 'from_airport' ? 'is-chosen' : '' }}"
                           href="{{ route('book.shuttle', ['direction' => 'from_airport']) }}">
                            <span class="segment-icon" aria-hidden="true">
                                {{-- A plane that has LANDED: this leg starts at the
                                     airport. The direction is the whole message, so it
                                     is drawn rather than left to the label. --}}
                                <i class="fa-solid fa-plane-arrival"></i>
                            </span>
                            <span class="segment-text">
                                <span class="segment-label">{{ __('lang.from_the_airport') }}</span>
                                <span class="segment-note">{{ __('lang.airport_to_city') }}</span>
                            </span>
                        </a>

                        <a class="segment {{ $direction === 'to_airport' ? 'is-chosen' : '' }}"
                           href="{{ route('book.shuttle', ['direction' => 'to_airport']) }}">
                            <span class="segment-icon" aria-hidden="true">
                                {{-- And one TAKING OFF, for the leg that ends there. --}}
                                <i class="fa-solid fa-plane-departure"></i>
                            </span>
                            <span class="segment-text">
                                <span class="segment-label">{{ __('lang.to_the_airport') }}</span>
                                <span class="segment-note">{{ __('lang.city_to_airport') }}</span>
                            </span>
                        </a>
                    </div>

                    <input type="hidden" name="direction" value="{{ $direction }}">
                    @if ($errors->has('direction'))
                        <ul class="form-error">
                            @foreach ($errors->get('direction') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h2 class="form-section">{{ __('lang.step_route') }}</h2>

                    {{-- Three choices in order, each one opening the next: an airport,
                         then a city that airport reaches, then a stop in that city.

                         Not filters with an "all" option any more. A list of every stop
                         the business runs is a list nobody reads, and the middle choice —
                         the city — is the one that decides which seats a booking draws
                         on, so it is worth making deliberately rather than skipping. --}}
                    <div class="booking-grid">
                        <div class="form-field">
                            <label for="airportFilter" class="form-label">{{ __('lang.airport') }}</label>

                            {{-- `data-required-message` is a slot, not a promise: the
                                 script marks whichever of the three is the next step the
                                 customer can take, and clears it from the others. --}}
                            <select id="airportFilter" class="form-select"
                                    data-required-message="{{ __('lang.select_airport') }}">
                                <option value="">{{ __('lang.select_airport') }}</option>
                                @foreach ($airports as $id => $airport)
                                    <option value="{{ $id }}" @selected(($draft['airportId'] ?? '') === $id)>
                                        {{ $airport['name'] ?? '' }}
                                    </option>
                                @endforeach
                            </select>

                            <div id="airport-picker" class="ss" hidden></div>
                        </div>

                        {{-- Beside the airport, because it IS part of the airport: a
                             shuttle collects a passenger at Terminal 1 or Terminal 2, and
                             a driver sent to the wrong one has missed them.

                             Hidden until an airport is chosen, and hidden ENTIRELY for an
                             airport the admin has published no terminals for — a dropdown
                             with nothing in it is a question the customer cannot answer.
                             `hidden` here rather than absent, so the script has something
                             to fill and a customer with no JavaScript still gets the
                             airport and stop selects that carry the booking. --}}
                        <div class="form-field" id="terminal-field" hidden>
                            <label for="terminal" class="form-label">{{ __('lang.terminal') }}</label>

                            <select id="terminal" name="terminal" class="form-select"
                                    data-required-message="{{ __('lang.choose_a_terminal') }}">
                                <option value="">{{ __('lang.choose_a_terminal') }}</option>
                            </select>

                            <div id="terminal-picker" class="ss" hidden></div>

                            @if ($errors->has('terminal'))
                                <ul class="form-error">
                                    @foreach ($errors->get('terminal') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>

                    {{-- The city and the stop, on their own line: the city is what the
                         SEATS belong to, and the stop is chosen inside it. --}}
                    <div class="booking-grid">
                        <div class="form-field">
                            <label for="cityFilter" class="form-label">{{ __('lang.city') }}</label>

                            {{-- Filled by the script once an airport is chosen: a city
                                 reachable from Juanda is not necessarily reachable from
                                 anywhere else, and offering one that leads to an empty
                                 stop list is a dead end to back out of. --}}
                            <select id="cityFilter" class="form-select"
                                    data-required-message="{{ __('lang.select_city') }}">
                                <option value="">{{ __('lang.select_city') }}</option>
                            </select>

                            <div id="city-picker" class="ss" hidden></div>

                            {{-- Named, because it is what the seats belong to. --}}
                            <p class="form-help">{{ $seatsNote }}</p>
                        </div>

                        <div class="form-field">
                            <label for="rateId" class="form-label">{{ $stopLabel }}</label>

                            {{-- `data-required` as well as `required`: the script hides
                                 this select and draws a dropdown over it, and a hidden
                                 field would otherwise be skipped by the validator — a
                                 required control with its checking silently switched
                                 off. --}}
                            <select id="rateId" name="rateId" class="form-select" required data-required
                                    data-required-message="{{ $stopPrompt }}">
                                <option value="">{{ $stopPrompt }}</option>
                                @foreach ($rates as $id => $rate)
                                    @php
                                        $custom = ($rate['isCustomQuote'] ?? false) === true;
                                        $fare = (float) ($rate['fixCost'] ?? 0);
                                        $times = $catalog->departureTimes($rate, $direction);
                                    @endphp
                                    <option value="{{ $id }}"
                                            data-airport="{{ $rate['airportId'] ?? '' }}"
                                            data-city="{{ $rate['cityGroupId'] ?? '' }}"
                                            data-city-name="{{ $rate['cityGroupName'] ?? '' }}"
                                            data-stop="{{ $rate['dropPoint'] ?? '' }}"
                                            data-fare="{{ $custom || $fare <= 0 ? '' : $money->format($fare).' '.__('lang.per_seat') }}"
                                            data-times="{{ implode(',', $times) }}"
                                            @selected(($draft['rateId'] ?? '') === $id)>
                                        {{-- The stop, WITHOUT its city. Every option in
                                             this list is in the city chosen above, so the
                                             prefix was the same on all of them and it was
                                             what a phone truncated away. --}}
                                        {{ $rate['dropPoint'] ?? '' }}@if (! $custom && $fare > 0) — {{ $money->format($fare) }} {{ __('lang.per_seat') }}@endif
                                    </option>
                                @endforeach
                            </select>

                            <div id="stop-picker" class="ss" hidden></div>

                            {{-- The chosen stop's departures, so the timetable is seen
                                 before committing to the next step. --}}
                            <p class="form-help" id="stop-note">{{ $stopHint }}</p>

                            @if ($errors->has('rateId'))
                                <ul class="form-error">
                                    @foreach ($errors->get('rateId') as $message)
                                        <li>{{ $message }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="row is-between is-wrap booking-actions">
                <a href="{{ route('home') }}" class="btn btn-ghost">{{ __('lang.cancel') }}</a>
                <button type="submit" class="btn btn-primary">{{ __('lang.continue') }}</button>
            </div>
        </form>

        <script src="{{ asset('js/dropdown-position.js') }}?v={{ is_file(public_path('js/dropdown-position.js')) ? filemtime(public_path('js/dropdown-position.js')) : '' }}"></script>
        <script src="{{ asset('js/searchable-select.js') }}?v={{ is_file(public_path('js/searchable-select.js')) ? filemtime(public_path('js/searchable-select.js')) : '' }}"></script>
        <script src="{{ asset('js/shuttle-route.js') }}?v={{ is_file(public_path('js/shuttle-route.js')) ? filemtime(public_path('js/shuttle-route.js')) : '' }}"></script>
        <script>
            (function () {
                'use strict';

                if (!window.initShuttleRoute) {
                    return;
                }

                window.initShuttleRoute({
                    direction: @json($direction),
                    endpoint: @json(route('book.shuttle.stops')),
                    cities: @json($cityOptions),
                    terminals: @json($terminalOptions),
                    chosenTerminal: @json($draft['terminal'] ?? ''),
                    labels: {
                        selectAirport: @json(__('lang.select_airport')),
                        selectCity: @json(__('lang.select_city')),
                        chooseStop: @json($stopPrompt),
                        chooseStopHint: @json($stopHint),
                        pickAirportFirst: @json(__('lang.pick_airport_first')),
                        pickCityFirst: @json(__('lang.pick_city_first')),
                        chooseTerminal: @json(__('lang.choose_a_terminal')),
                        departuresAre: @json(__('lang.departures_are', ['times' => ':times'])),
                        noDepartures: @json(__('lang.no_departures_scheduled')),
                        search: @json(__('lang.search')),
                        noMatches: @json(__('lang.no_matches')),
                    },
                });
            }());
        </script>

        @include('partials.form-validation')
    @endif
</div>
@endsection

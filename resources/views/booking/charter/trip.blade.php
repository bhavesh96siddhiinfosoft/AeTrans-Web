{{--
    Charter, step 1 — the route.

    The addresses are Google address fields: type and pick from the dropdown, or press
    the pin and choose on a map. Every field carries hidden latitude and longitude, and
    THOSE are what the distance is measured from — text with no pin behind it has no
    coordinates, and a distance guessed from it would price a trip nobody is taking.

    More than one drop-off is allowed, and the customer can reorder them, because a
    charter is an itinerary. The van drives through the stops in the order shown, and the
    distance is measured through them in that order — so moving one changes the price.

    The distance is READ-ONLY and measured, not typed. It only becomes an ordinary
    number when maps are unavailable — no key, no network, or the Directions API not
    enabled on the key — so that the booking can still be completed by hand.
--}}
@extends('layouts.public')

@section('title', __('lang.book_charter'))

@section('head')
        @include('booking.partials.reset-on-reload')
@endsection

@section('content')
<div class="page booking-page">
    @include('booking.charter.steps', ['current' => 1])

    <div class="page-head">
        <h1 class="page-title">{{ __('lang.tell_us_the_trip') }}</h1>
        <p class="page-subtitle">{{ __('lang.route_step_lead') }}</p>
    </div>

    @php
        /*
         * The boxes start EMPTY. The client's default address (2026-09-08) is where the
         * MAP opens when there is no pin yet — not a value put into the field. See
         * `config('bookings.default_address')` and location-picker.js.
         */

        // At least one drop-off row always renders, so the form is usable before any
        // script runs and a customer with no JavaScript still has a trip to describe.
        $stops = old('dropoffAddresses', $draft['dropoffAddresses'] ?? ['']);
        $stopLats = old('dropoffLat', $draft['dropoffLat'] ?? []);
        $stopLngs = old('dropoffLng', $draft['dropoffLng'] ?? []);
        $stops = $stops ?: [''];
    @endphp

    <div class="card">
        <div class="card-body">
            <form method="POST" action="{{ route('book.charter') }}" class="form" data-trip-form>
                @csrf

                <div class="form-field" data-pickup>
                    <label for="pickupAddress" class="form-label">{{ __('lang.pickup_address') }}</label>

                    <div class="address-row">
                        {{-- The ✕ lives INSIDE the box (see .field-clearable): a stop
                             row already has one beside it that removes the whole stop,
                             and two identical buttons doing different things is a trap. --}}
                        <div class="field-clearable">
                            <input id="pickupAddress" name="pickupAddress" type="text" class="form-input is-clearable"
                                   value="{{ old('pickupAddress', $draft['pickupAddress'] ?? '') }}"
                                   placeholder="{{ __('lang.start_typing_address_pick_map') }}"
                                   data-required-message="{{ __('lang.enter_pickup_address') }}"
                                   autocomplete="off" required autofocus data-pickup-input>

                            <button type="button" class="field-clear" data-address-clear hidden
                                    aria-label="{{ __('lang.clear_the_address') }}">✕</button>
                        </div>

                        {{-- Hidden until the Maps API has actually loaded: a pin that
                             does nothing when pressed is worse than no pin. --}}
                        <button type="button" class="address-pin" data-pin data-pickup-pin hidden
                                aria-label="{{ __('lang.pick_pickup_point_map') }}">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z" stroke-linejoin="round"/>
                                <circle cx="12" cy="10" r="2.5"/>
                            </svg>
                        </button>
                    </div>

                    <input type="hidden" name="pickupLat" value="{{ old('pickupLat', $draft['pickupLat'] ?? '') }}" data-lat>
                    <input type="hidden" name="pickupLng" value="{{ old('pickupLng', $draft['pickupLng'] ?? '') }}" data-lng>
                    @if ($errors->has('pickupAddress'))
                        <ul class="form-error">
                            @foreach ($errors->get('pickupAddress') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="form-field">
                    <label class="form-label">{{ __('lang.drop_off_addresses') }}</label>

                    <div data-stops class="stop-list">
                        @foreach ($stops as $index => $stop)
                            <div class="stop-row" data-stop>
                                <span class="stop-number" data-stop-number>{{ $index + 1 }}</span>

                                <div class="address-row">
                                    <div class="field-clearable">
                                        <input name="dropoffAddresses[]" type="text" class="form-input is-clearable"
                                               value="{{ $stop }}"
                                               placeholder="{{ __('lang.start_typing_address_pick_map') }}"
                                               data-required-message="{{ __('lang.enter_drop_off_address') }}"
                                               autocomplete="off" required data-stop-input>

                                        <button type="button" class="field-clear" data-address-clear hidden
                                                aria-label="{{ __('lang.clear_the_address') }}">✕</button>
                                    </div>

                                    <button type="button" class="address-pin" data-pin data-stop-pin hidden
                                            aria-label="{{ __('lang.pick_stop_map') }}">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                            <path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z" stroke-linejoin="round"/>
                                            <circle cx="12" cy="10" r="2.5"/>
                                        </svg>
                                    </button>


                                    {{-- Reordering the itinerary. Buttons rather than
                                         drag-and-drop: this site is used from phones,
                                         where dragging a 40px row is fiddly, and
                                         buttons work from the keyboard for free. --}}
                                    <span class="stop-move" data-stop-move {{ count($stops) < 2 ? 'hidden' : '' }}>
                                        <button type="button" class="stop-move-btn" data-stop-up
                                                aria-label="{{ __('lang.move_stop_earlier') }}">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                                <path d="m6 15 6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>

                                        <button type="button" class="stop-move-btn" data-stop-down
                                                aria-label="{{ __('lang.move_stop_later') }}">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                                <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                        </button>
                                    </span>

                                    <button type="button" class="address-remove" data-stop-remove
                                            aria-label="{{ __('lang.remove_stop') }}" {{ count($stops) < 2 ? 'hidden' : '' }}>✕</button>
                                </div>

                                <input type="hidden" name="dropoffLat[]" value="{{ $stopLats[$index] ?? '' }}" data-lat>
                                <input type="hidden" name="dropoffLng[]" value="{{ $stopLngs[$index] ?? '' }}" data-lng>
                            </div>
                        @endforeach
                    </div>

                    {{-- The blueprint the Add button clones. A <template> is inert —
                         its inputs are not part of the form until one is added. --}}
                    <template data-stop-template>
                        <div class="stop-row" data-stop>
                            <span class="stop-number" data-stop-number>2</span>

                            <div class="address-row">
                                <div class="field-clearable">
                                    <input name="dropoffAddresses[]" type="text" class="form-input is-clearable"
                                           placeholder="{{ __('lang.start_typing_address_pick_map') }}"
                                           data-required-message="{{ __('lang.enter_drop_off_address') }}"
                                           autocomplete="off" required data-stop-input>

                                    <button type="button" class="field-clear" data-address-clear hidden
                                            aria-label="{{ __('lang.clear_the_address') }}">✕</button>
                                </div>

                                <button type="button" class="address-pin" data-pin data-stop-pin
                                        aria-label="{{ __('lang.pick_stop_map') }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M12 21s-7-5.5-7-11a7 7 0 1 1 14 0c0 5.5-7 11-7 11z" stroke-linejoin="round"/>
                                        <circle cx="12" cy="10" r="2.5"/>
                                    </svg>
                                </button>


                                {{-- Reordering the itinerary. Buttons rather than
                                     drag-and-drop: this site is used from phones,
                                     where dragging a 40px row is fiddly, and
                                     buttons work from the keyboard for free. --}}
                                <span class="stop-move" data-stop-move >
                                    <button type="button" class="stop-move-btn" data-stop-up
                                            aria-label="{{ __('lang.move_stop_earlier') }}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                            <path d="m6 15 6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>

                                    <button type="button" class="stop-move-btn" data-stop-down
                                            aria-label="{{ __('lang.move_stop_later') }}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                            <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                    </button>
                                </span>

                                <button type="button" class="address-remove" data-stop-remove
                                        aria-label="{{ __('lang.remove_stop') }}">✕</button>
                            </div>

                            <input type="hidden" name="dropoffLat[]" value="" data-lat>
                            <input type="hidden" name="dropoffLng[]" value="" data-lng>
                        </div>
                    </template>

                    {{-- A 40px square, the pin's twin, and the SCRIPT moves it onto the
                         last stop's row (see `renumber()` in booking-route.js) so it sits
                         with the buttons it belongs beside rather than as a full-width bar
                         underneath. It stays here in the markup as its home for the
                         no-JavaScript case, where the row it would move to never renders
                         a pin either. --}}
                    <button type="button" class="address-add" data-add-stop
                            title="{{ __('lang.add_another_stop') }}"
                            aria-label="{{ __('lang.add_another_stop') }}">+</button>

                    @if ($errors->has('dropoffAddresses'))
                        <ul class="form-error">
                            @foreach ($errors->get('dropoffAddresses') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($errors->has('dropoffAddresses.0'))
                        <ul class="form-error">
                            @foreach ($errors->get('dropoffAddresses.0') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="form-field">
                    <label for="distanceShown" class="form-label">{{ __('lang.distance_km') }}</label>

                    <div class="address-row">
                        {{-- Shown, not posted. `readonly` rather than `disabled` so
                             it is still readable by a screen reader and can be
                             opened up when maps are unavailable. --}}
                        <input id="distanceShown" type="text" class="form-input" readonly aria-readonly="true"
                               value="{{ old('distanceKm', $draft['distanceKm'] ?? '') }}"
                               placeholder="{{ __('lang.measured_from_addresses') }}"
                               data-distance-display>

                        <button type="button" class="btn btn-secondary btn-sm" data-measure hidden>
                            {{ __('lang.measure_route') }}
                        </button>
                    </div>

                    {{-- The ONE-WAY distance. A return trip bills for twice it, and
                         the doubling happens in CharterQuote on the next step, where
                         the trip type is known. --}}
                    {{-- `data-required` because the field is hidden: the customer
                         cannot type into the read-only box above, so the value they
                         are missing is this one, and the message belongs here. --}}
                    <input type="hidden" name="distanceKm" data-required
                           data-required-message="{{ __('lang.choose_addresses_to_measure') }}"
                           value="{{ old('distanceKm', $draft['distanceKm'] ?? '') }}" data-distance-value>

                    {{-- The SAME stops, then home again from the last one: A -> B -> C -> A.
                         A round trip is that loop, not the outbound distance doubled —
                         doubling drives back through every stop, which is road nobody
                         covers (client, 2026-09-09).

                         Measured here on step 1 with the other figure, because the trip
                         type is not asked until step 2 and that screen has no addresses
                         on it. NOT required: a hand-typed distance has no return leg to
                         measure, and the server doubles for that case alone. --}}
                    <input type="hidden" name="roundTripKm"
                           value="{{ old('roundTripKm', $draft['roundTripKm'] ?? '') }}" data-round-trip-value>

                    <p class="form-help" data-distance-note></p>
                    @if ($errors->has('distanceKm'))
                        <ul class="form-error">
                            @foreach ($errors->get('distanceKm') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="row is-between is-wrap">
                    <a href="{{ route('home') }}" class="btn btn-ghost">{{ __('lang.cancel') }}</a>
                    <button type="submit" class="btn btn-primary">{{ __('lang.continue') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('partials.google-maps')

<script>
    window.bookingRouteStrings = {
        needPoints: @json(__('lang.choose_addresses_from_suggestions')),
        measuring: @json(__('lang.measuring_route')),
        measured: @json(__('lang.distance_measured_note')),
        measureFailed: @json(__('lang.route_measure_failed')),
        mapsUnavailable: @json(__('lang.maps_unavailable')),
    };
</script>
<script src="{{ asset('js/booking-route.js') }}?v={{ is_file(public_path('js/booking-route.js')) ? filemtime(public_path('js/booking-route.js')) : '' }}"></script>
@include('partials.form-validation')
@endsection

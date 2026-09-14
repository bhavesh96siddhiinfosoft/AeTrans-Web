{{--
    The Google Maps key, and the picker the address fields open.

    Include once on a screen that needs an address. It brings three things:

      * the picker, WITHOUT the key — `google-maps-loader.js` fetches that from
        `/maps-key` (see MapsKeyController). It used to be printed here as JSON; the
        client asked on 2026-09-08 that it not sit in view-source. The key is still
        read on the SERVER, so this page needs no Firestore handle of its own, and it
        is still public once fetched — the Google Cloud console's referrer restriction
        is what limits it, not this;
      * the picker dialog's markup;
      * the loader, the picker and the address-field scripts.

    WHEN THERE IS NO KEY nothing here renders at all. The address fields stay ordinary
    text boxes, the distance is typed by hand, and the booking still completes — which
    is the behaviour to keep. A half-loaded map is worse than none.

    The scripts are shared with the admin panel by COPY, not by reference: the two are
    separate deployments. `location-picker.js` and `trip-route.js` are the panel's files
    unchanged; the loader differs and says why in its own header.
--}}
{{-- The gate stays SERVER-side: with no key configured, none of this renders at all
     and the address fields stay ordinary text boxes. Asking the server whether a key
     exists is not the same as printing it. --}}
@if ($site->mapKey())
    <div id="location-picker" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="lp-title">
        {{-- The backdrop is a sibling, not the overlay itself, so a click on the dialog
             cannot bubble out and close the picker mid-edit. --}}
        <div data-lp-backdrop class="lp-backdrop"></div>

        <div class="modal is-widest lp-modal">
            <div class="modal-header row is-between">
                <div>
                    <h2 id="lp-title" class="card-title">{{ __('lang.find_the_place') }}</h2>
                    <p class="card-subtitle">{{ __('lang.map_pin_hint') }}</p>
                </div>

                <button type="button" data-lp-cancel class="theme-toggle" aria-label="{{ __('lang.cancel') }}">✕</button>
            </div>

            <div class="modal-body">
                <div id="lp-error" class="alert alert-error hidden"></div>

                <label for="lp-search" class="form-label">{{ __('lang.search') }}</label>

                {{-- The × sits INSIDE the box, as the mobile app has it. `hidden` to
                     start: there is nothing to clear until something is typed, and a
                     dead button is worse than none. --}}
                <div class="field-clearable">
                    <input id="lp-search" type="text" autocomplete="off" class="form-input is-clearable"
                           placeholder="{{ __('lang.map_search_placeholder') }}">

                    <button type="button" id="lp-search-clear" data-lp-search-clear class="field-clear" hidden
                            aria-label="{{ __('lang.clear_the_address') }}">✕</button>
                </div>

                {{-- Shown only when the Places typeahead is unavailable and results come
                     back from the geocoder instead. --}}
                <ul id="lp-results" class="lp-results hidden"></ul>

                <div id="lp-map" class="lp-map"></div>

                <p id="lp-address" class="form-help"></p>

                {{-- The chosen point, HIDDEN — the client's call, 2026-09-08.
                     Two visible number boxes were the widest thing in this dialog and
                     the map paid for them: on a phone the map had been cut to 220px so
                     the address, both boxes and the buttons could share the viewport.
                     They are still INPUTS rather than a variable because they are what
                     "Use this location" reads at the moment it is pressed; deleting them
                     breaks the picker rather than tidying it. --}}
                <input id="lp-lat" type="hidden">
                <input id="lp-lng" type="hidden">
            </div>

            <div class="modal-footer">
                <button type="button" data-lp-cancel class="btn btn-secondary">{{ __('lang.cancel') }}</button>
                <button type="button" data-lp-use class="btn btn-primary">{{ __('lang.use_this_location') }}</button>
            </div>
        </div>
    </div>

    <script>
        // Here rather than in the JS files, so the messages stay translatable.
        window.googleMapsStrings = {
            noKey: @json(__('lang.maps_not_configured')),
            loadFailed: @json(__('lang.map_could_loaded')),
        };

        window.locationPickerStrings = {
            noResults: @json(__('lang.nothing_found_search')),
            pickFirst: @json(__('lang.choose_place_map_first')),
        };
    </script>

    @php
        $stamp = fn (string $path) => asset($path).(is_file(public_path($path)) ? '?v='.filemtime(public_path($path)) : '');
    @endphp

    <script src="{{ $stamp('js/google-maps-loader.js') }}"></script>
    <script src="{{ $stamp('js/location-picker.js') }}"></script>
    <script src="{{ $stamp('js/trip-route.js') }}"></script>
@endif

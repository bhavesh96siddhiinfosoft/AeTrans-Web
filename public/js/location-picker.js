/**
 * Google location picker.
 *
 * Pairs with resources/views/partials/location-picker.blade.php, which owns the
 * markup. Usage:
 *
 *   const place = await openLocationPicker({ lat, lng, query });
 *   // → null when cancelled, else { lat, lng, name, city, address }
 *
 * The Maps JS API is loaded lazily on first open, using the key stored in
 * Firestore at settings/mapSettings.mapAPIkey (set on Global Settings).
 *
 * settings/globalValue.regionCode (the "Map region" on that same screen) does two
 * things: a record with no coordinates yet opens with the map over that country
 * rather than at world view, and search results are biased towards it. Biased, not
 * restricted — a place outside the region is still findable, which matters while
 * the data is being seeded.
 *
 * Two ways to find a place, because the Places typeahead is not available on
 * every project: the `places` library gives an autocomplete dropdown when it
 * loads, and pressing Enter always falls back to the Geocoder, which is part of
 * the core API. Either way the marker stays draggable and the map clickable, so
 * the coordinates can be nudged by hand.
 */
(function () {
    'use strict';

    var map = null;
    var marker = null;
    var geocoder = null;
    var autocomplete = null;
    var region = '';          // ISO2, read from the shared loader once ready
    var regionView = null;    // that country's bounds, geocoded once and cached
    var settled = null;       // resolve() of the open() promise, null when closed

    function el(id) {
        return document.getElementById(id);
    }

    function root() {
        return el('location-picker');
    }

    function showError(message) {
        var box = el('lp-error');

        box.textContent = message;
        box.classList.remove('hidden');
    }

    function clearError() {
        el('lp-error').classList.add('hidden');
    }

    /** The × inside the search box shows only when there is something to clear. */
    function toggleSearchClear() {
        var button = el('lp-search-clear');

        if (button) {
            button.hidden = el('lp-search').value === '';
        }
    }

    /** Pulls the town/city out of a geocoder or place result. */
    function cityOf(components) {
        var wanted = ['locality', 'postal_town', 'administrative_area_level_2', 'administrative_area_level_1'];

        for (var i = 0; i < wanted.length; i++) {
            for (var j = 0; j < (components || []).length; j++) {
                if (components[j].types.indexOf(wanted[i]) !== -1) {
                    return components[j].long_name;
                }
            }
        }

        return '';
    }

    var current = { lat: null, lng: null, name: '', city: '', address: '' };

    function setCoordinates(lat, lng) {
        current.lat = lat;
        current.lng = lng;

        el('lp-lat').value = lat === null ? '' : lat;
        el('lp-lng').value = lng === null ? '' : lng;
    }

    /** Moves map + marker, without touching the name/city already resolved. */
    function moveTo(lat, lng, zoom) {
        setCoordinates(lat, lng);

        var position = { lat: lat, lng: lng };

        map.setCenter(position);

        if (zoom) {
            map.setZoom(zoom);
        }

        marker.setPosition(position);
        marker.setVisible(true);
    }

    /** Fills name/city/address from a geocoder or places result. */
    function applyPlace(result, fallbackName) {
        current.address = result.formatted_address || '';
        current.city = cityOf(result.address_components);
        current.name = result.name || fallbackName || '';

        el('lp-address').textContent = current.address;
    }

    /**
     * Names the coordinates after a drag or map click.
     *
     * The resolved address is written INTO THE SEARCH BOX as well as under the map
     * (client, 2026-09-08). Dragging the pin used to leave that box on its placeholder,
     * so the one field a customer reads as "the place I have chosen" stayed empty while
     * the answer sat in grey text below the map.
     *
     * Only on this path, not in `applyPlace`: a place chosen from the typeahead has
     * already put its own description in the box, and overwriting that mid-selection
     * fights the widget for the field.
     */
    function reverseGeocode(lat, lng) {
        geocoder.geocode({ location: { lat: lat, lng: lng } }, function (results, status) {
            if (status !== 'OK' || !results || !results.length) {
                el('lp-address').textContent = '';
                current.address = '';
                current.city = '';

                return;
            }

            applyPlace(results[0], current.name);

            // Assigning `value` fires no `input` event, so the typeahead is not
            // retriggered by this and no suggestion list opens over the map.
            el('lp-search').value = current.address;
            toggleSearchClear();
        });
    }

    /** Enter in the search box, when the typeahead has not resolved a place. */
    function searchByText(query) {
        if (!query.trim()) {
            return;
        }

        var request = { address: query };

        if (region) {
            request.region = region;
        }

        geocoder.geocode(request, function (results, status) {
            var list = el('lp-results');

            if (status !== 'OK' || !results || !results.length) {
                list.innerHTML = '';
                list.classList.add('hidden');
                showError(window.locationPickerStrings.noResults);

                return;
            }

            clearError();

            if (results.length === 1) {
                list.classList.add('hidden');
                choose(results[0], query);

                return;
            }

            // More than one match: let the user say which one they meant.
            list.innerHTML = '';

            results.slice(0, 8).forEach(function (result) {
                var item = document.createElement('li');
                var button = document.createElement('button');

                button.type = 'button';
                // `.ss-option`, the searchable-select option look — this is the same
                // interaction (a list of choices in a panel), so it should not be a
                // second style. It was a Tailwind string until 2026-08-13, which meant
                // this dropdown was styled ENTIRELY by the old build on screens
                // recorded as fully ported; deleting that build would have left it as
                // unstyled text, with no error to trace it by.
                button.className = 'ss-option';
                button.textContent = result.formatted_address;
                button.addEventListener('click', function () {
                    list.classList.add('hidden');
                    choose(result, query);
                });

                item.appendChild(button);
                list.appendChild(item);
            });

            list.classList.remove('hidden');
        });
    }

    function choose(result, fallbackName) {
        applyPlace(result, fallbackName);
        moveTo(result.geometry.location.lat(), result.geometry.location.lng(), 15);
    }

    function showWholeWorld() {
        map.setCenter({ lat: 0, lng: 0 });
        map.setZoom(2);
    }

    /**
     * Opening view when the record has no coordinates yet: the Map region set on
     * Global Settings, so the map starts over the country being operated in rather
     * than zoomed out to the whole world. The country's bounds are geocoded once
     * and cached for the rest of the page's life.
     */
    function focusRegion() {
        if (!region) {
            showWholeWorld();

            return;
        }

        if (regionView) {
            map.fitBounds(regionView);

            return;
        }

        geocoder.geocode({ componentRestrictions: { country: region } }, function (results, status) {
            if (status !== 'OK' || !results || !results.length) {
                showWholeWorld();

                return;
            }

            var geometry = results[0].geometry;
            regionView = geometry.viewport || geometry.bounds;

            if (regionView) {
                map.fitBounds(regionView);
            } else {
                map.setCenter(geometry.location);
                map.setZoom(5);
            }
        });
    }

    /** Builds the map once; later opens reuse it. */
    function buildMap() {
        if (map) {
            return;
        }

        geocoder = new google.maps.Geocoder();

        map = new google.maps.Map(el('lp-map'), {
            center: { lat: 0, lng: 0 },
            zoom: 2,
            mapTypeControl: false,
            streetViewControl: false,
            fullscreenControl: false,
        });

        marker = new google.maps.Marker({ map: map, draggable: true, visible: false });

        marker.addListener('dragend', function (event) {
            setCoordinates(event.latLng.lat(), event.latLng.lng());
            reverseGeocode(current.lat, current.lng);
        });

        map.addListener('click', function (event) {
            moveTo(event.latLng.lat(), event.latLng.lng());
            reverseGeocode(current.lat, current.lng);
        });

        // Typeahead when the places library is available; the Enter/geocoder path
        // below works either way, so a failure here is not fatal.
        try {
            autocomplete = new google.maps.places.Autocomplete(el('lp-search'), {
                fields: ['name', 'geometry', 'formatted_address', 'address_components'],
            });

            autocomplete.bindTo('bounds', map);

            autocomplete.addListener('place_changed', function () {
                var place = autocomplete.getPlace();

                if (!place || !place.geometry || !place.geometry.location) {
                    // Enter pressed without picking a suggestion.
                    searchByText(el('lp-search').value);

                    return;
                }

                clearError();
                el('lp-results').classList.add('hidden');
                choose(place, place.name);
            });
        } catch (error) {
            autocomplete = null;
        }

        el('lp-search').addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            event.preventDefault();

            // With the typeahead active, place_changed fires and handles this —
            // but only when a suggestion is highlighted, so search anyway when it
            // is not, and always when there is no typeahead at all.
            if (!autocomplete) {
                searchByText(el('lp-search').value);
            }
        });

        /*
         * Typing a coordinate by hand used to move the marker. Gone with the visible
         * boxes (client, 2026-09-08): a hidden input cannot be typed into, so the
         * listener could only ever have been dead code sitting where a reader would
         * think the feature still existed.
         */
    }

    /**
     * Opens on the client's own address, pin down, when the field being edited has no
     * coordinates of its own (client, 2026-09-08 — `config('bookings.default_address')`,
     * reaching here through `/maps-key`).
     *
     * The address is taken from the configuration rather than reverse-geocoded from the
     * point: it is the wording the client chose, it costs no Geocoding request, and the
     * two cannot drift apart on the screen.
     *
     * Returns false when nothing is configured, so the caller falls back to the
     * region-wide view the picker showed before.
     */
    function openAtDefault() {
        var place = window.googleMapsDefaultPlace ? window.googleMapsDefaultPlace() : null;

        if (!place) {
            return false;
        }

        var lat = parseFloat(place.lat);
        var lng = parseFloat(place.lng);

        if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
            return false;
        }

        moveTo(lat, lng, 15);

        /*
         * `current.address` is set too, not just the labels, because "Use this location"
         * hands `current` back to the field. Pressing it straight away accepts the
         * default — which is the point of showing it — and the address that reaches the
         * booking is the one on screen.
         */
        current.address = (place.address || '').trim();
        current.name = current.address;

        el('lp-address').textContent = current.address;
        el('lp-search').value = current.address;
        toggleSearchClear();

        return true;
    }

    function close(result) {
        root().classList.add('hidden');

        var resolve = settled;
        settled = null;

        if (resolve) {
            resolve(result);
        }
    }

    /** Opens the picker. Resolves with the chosen place, or null if cancelled. */
    window.openLocationPicker = function (options) {
        options = options || {};

        // A second call while open would strand the first promise unresolved.
        if (settled) {
            close(null);
        }

        var dialog = root();

        if (!dialog) {
            return Promise.reject(new Error('location-picker markup is missing — include partials.location-picker.'));
        }

        clearError();
        el('lp-results').classList.add('hidden');
        el('lp-results').innerHTML = '';
        el('lp-address').textContent = '';
        el('lp-search').value = '';
        toggleSearchClear();

        current = { lat: null, lng: null, name: '', city: '', address: '' };
        setCoordinates(null, null);

        dialog.classList.remove('hidden');

        return window.loadGoogleMaps().then(function () {
            // Set from the shared loader rather than read here: it owns the settings
            // fetch, and this is what biases search and frames the empty-state view.
            region = window.googleMapsRegion();

            buildMap();

            // Maps sizes itself from the container, which was display:none until
            // now — without this the tiles render into a zero-height box.
            google.maps.event.trigger(map, 'resize');

            var lat = parseFloat(options.lat);
            var lng = parseFloat(options.lng);

            if (Number.isFinite(lat) && Number.isFinite(lng)) {
                moveTo(lat, lng, 15);
                reverseGeocode(lat, lng);
            } else if (!openAtDefault()) {
                marker.setVisible(false);
                focusRegion();
            }

            // Only when the caller HAS a query: an empty one must not wipe the default
            // address `openAtDefault()` has just put in the box.
            if (options.query) {
                el('lp-search').value = options.query;
            }

            toggleSearchClear();

            el('lp-search').focus();

            return new Promise(function (resolve) {
                settled = resolve;
            });
        }).catch(function (error) {
            // Keep the dialog up so the message is readable, and resolve to null
            // so the caller is never left waiting.
            showError(error.message);

            return new Promise(function (resolve) {
                settled = resolve;
            });
        });
    };

    function bind() {
        var dialog = root();

        if (!dialog) {
            return;
        }

        /*
         * One tap to empty the search box, the way the mobile app has it (client,
         * 2026-09-08).
         *
         * The TEXT only: the pin, and the coordinates behind it, are left where they
         * are. Someone clearing this box is retyping a search, not undoing the place
         * they have already dragged the pin to — and "Use this location" reads the
         * coordinates, not this field.
         */
        var searchClear = dialog.querySelector('[data-lp-search-clear]');

        if (searchClear) {
            searchClear.addEventListener('click', function () {
                el('lp-search').value = '';
                el('lp-results').classList.add('hidden');
                el('lp-search').focus();
                toggleSearchClear();
            });

            el('lp-search').addEventListener('input', toggleSearchClear);
        }

        dialog.querySelectorAll('[data-lp-cancel], [data-lp-backdrop]').forEach(function (element) {
            element.addEventListener('click', function () {
                close(null);
            });
        });

        dialog.querySelector('[data-lp-use]').addEventListener('click', function () {
            var lat = parseFloat(el('lp-lat').value);
            var lng = parseFloat(el('lp-lng').value);

            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                showError(window.locationPickerStrings.pickFirst);

                return;
            }

            close({
                lat: lat,
                lng: lng,
                name: current.name,
                city: current.city,
                address: current.address,
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && settled) {
                close(null);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();

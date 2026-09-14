/**
 * Address fields that know where they are, and the driving distance between them.
 *
 *   const pickup = window.initAddressField(input, { button, onChange });
 *   pickup.point();                    // { latitude, longitude } or null
 *   pickup.setPoint(lat, lng);         // restore a saved booking
 *
 *   await window.routeDistanceKm({ origin, destination, waypoints });  // km or null
 *
 * Two halves of one job — an admin taking a booking over the phone types "Ubud
 * Palace", and the screen has to turn that into coordinates and then into a
 * distance the price is calculated from.
 *
 * WHY A TYPED ADDRESS IS NOT ENOUGH, and why the point is cleared on every
 * keystroke: the distance is computed from coordinates, so text the admin typed but
 * never picked from the dropdown has no coordinates behind it. Keeping the previous
 * pin while the text says somewhere else is how a booking ends up priced for a trip
 * nobody is taking. Blank is honest; stale is not.
 *
 * The Maps API is loaded through google-maps-loader.js — the only place it may be
 * injected — using the key from settings/mapSettings.mapAPIkey.
 *
 * DIRECTIONS IS A SEPARATE GOOGLE API from Places and Maps, billed separately and
 * enabled separately. routeDistanceKm() resolves to null rather than throwing when
 * it is unavailable, so the screens fall back to a hand-typed distance instead of
 * breaking.
 */
(function () {
    'use strict';

    var directions = null;

    /**
     * A coordinate, or null.
     *
     * The empty checks are load-bearing and must not be simplified away:
     * `Number(null)` is 0, `Number('')` is 0, and `Number(false)` is 0 — all finite.
     * Without them, clearing a point produced { latitude: 0, longitude: 0 } instead
     * of null, which is a real place in the Gulf of Guinea. The field kept its
     * "picked" marker, routes were measured from the middle of the Atlantic, and the
     * booking would have saved those coordinates as if the customer chose them.
     */
    function toNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        var parsed = Number(value);

        return isFinite(parsed) ? parsed : null;
    }

    /**
     * Turns an input into an address field with a Google typeahead and, when a
     * button is supplied, a pick-on-map dialog.
     *
     * @param {HTMLInputElement} input
     * @param {object} [options]
     * @param {HTMLElement} [options.button]  opens the map picker
     * @param {Function} [options.onChange]   called whenever the point changes
     * @param {boolean} [options.clearOnType] drop the pin when the text is edited.
     *        TRUE on a new booking, where text and pin must agree or the price is
     *        wrong. FALSE when editing a saved booking, which already has a pin —
     *        correcting a typo in the address should not throw the route away.
     * @returns {{point: Function, setPoint: Function, clear: Function, input: HTMLElement}}
     */
    window.initAddressField = function (input, options) {
        var settings = options || {};
        var clearOnType = settings.clearOnType !== false;
        var point = null;

        function announce() {
            if (typeof settings.onChange === 'function') {
                settings.onChange(point);
            }
        }

        function set(lat, lng) {
            var latitude = toNumber(lat);
            var longitude = toNumber(lng);

            point = (latitude === null || longitude === null)
                ? null
                : { latitude: latitude, longitude: longitude };

            // The marker the admin can see: a field with coordinates behind it is
            // the difference between a priced trip and a guess.
            input.classList.toggle('has-point', point !== null);
            announce();
        }

        // Typing invalidates the pin — see the note at the top of this file.
        if (clearOnType) {
            input.addEventListener('input', function () {
                if (point) {
                    set(null, null);
                }
            });
        }

        if (window.loadGoogleMaps) {
            window.loadGoogleMaps().then(function () {
                if (!window.google || !google.maps.places) {
                    return;
                }

                var autocomplete = new google.maps.places.Autocomplete(input, {
                    fields: ['geometry', 'formatted_address', 'name'],
                });

                var region = window.googleMapsRegion ? window.googleMapsRegion() : '';
                if (region) {
                    autocomplete.setComponentRestrictions({ country: region.toLowerCase() });
                }

                autocomplete.addListener('place_changed', function () {
                    var place = autocomplete.getPlace();

                    if (!place || !place.geometry || !place.geometry.location) {
                        return;
                    }

                    input.value = place.formatted_address || place.name || input.value;
                    set(place.geometry.location.lat(), place.geometry.location.lng());
                });

                // Enter inside a typeahead would otherwise submit the surrounding form
                // and lose everything the admin has entered.
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                    }
                });
            }).catch(function () {
                // No key, no network, no Maps: the field stays a plain text box and
                // the distance is typed by hand. Nothing on the screen breaks.
            });
        }

        if (settings.button && window.openLocationPicker) {
            settings.button.addEventListener('click', function () {
                window.openLocationPicker({
                    lat: point ? point.latitude : null,
                    lng: point ? point.longitude : null,
                    query: input.value,
                }).then(function (place) {
                    if (!place) {
                        return;
                    }

                    input.value = place.address || place.name || input.value;
                    set(place.lat, place.lng);
                });
            });
        }

        return {
            input: input,
            point: function () { return point; },
            setPoint: function (lat, lng) { set(lat, lng); },
            clear: function () { input.value = ''; set(null, null); },
        };
    };

    /**
     * Driving distance for a route, in kilometres.
     *
     * Directions rather than Distance Matrix: a charter trip can have stops, and
     * Directions returns one route through them whose legs sum to the distance
     * actually driven. Distance Matrix would only give point-to-point pairs.
     *
     * @param {object} options
     * @param {{latitude: number, longitude: number}} options.origin
     * @param {{latitude: number, longitude: number}} options.destination
     * @param {Array} [options.waypoints]  intermediate stops, in order
     * @returns {Promise<number|null>} kilometres, or null when it cannot be computed
     */
    window.routeDistanceKm = function (options) {
        var settings = options || {};
        var origin = settings.origin;
        var destination = settings.destination;

        if (!origin || !destination || !window.loadGoogleMaps) {
            return Promise.resolve(null);
        }

        var toLatLng = function (p) {
            return { lat: Number(p.latitude), lng: Number(p.longitude) };
        };

        return window.loadGoogleMaps().then(function () {
            if (!window.google || !google.maps.DirectionsService) {
                return null;
            }

            directions = directions || new google.maps.DirectionsService();

            var request = {
                origin: toLatLng(origin),
                destination: toLatLng(destination),
                waypoints: (settings.waypoints || []).map(function (stop) {
                    return { location: toLatLng(stop), stopover: true };
                }),
                travelMode: 'DRIVING',
            };

            return new Promise(function (resolve) {
                directions.route(request, function (result, status) {
                    if (status !== 'OK' || !result || !result.routes || !result.routes.length) {
                        // ZERO_RESULTS, REQUEST_DENIED (the Directions API is not
                        // enabled on the key), OVER_QUERY_LIMIT — all mean "no
                        // distance", and the admin types it instead.
                        resolve(null);

                        return;
                    }

                    var metres = result.routes[0].legs.reduce(function (total, leg) {
                        return total + ((leg.distance && leg.distance.value) || 0);
                    }, 0);

                    // One decimal: Google returns metres, and a charter quote does not
                    // turn on a hundred of them.
                    resolve(Math.round(metres / 100) / 10);
                });
            });
        }).catch(function () {
            return null;
        });
    };
})();

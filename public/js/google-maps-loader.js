/**
 * Loads the Google Maps JS API once per page.
 *
 *   await loadGoogleMaps();      // resolves when window.google.maps is usable
 *   googleMapsRegion();          // ISO2 from settings, or ''
 *
 * The Maps API can only be loaded once — a second <script> for it throws "You have
 * included the Google Maps JavaScript API multiple times" and leaves the page with a
 * half-initialised API. So every map on the site goes through here rather than
 * injecting its own tag.
 *
 * DIFFERENT FROM THE ADMIN PANEL'S COPY, deliberately. The panel reads the key from
 * Firestore in the browser, which it can do because an operator is signed in. A visitor
 * here is not, and handing the public site a Firestore handle to fetch one string would
 * be a far larger door than the string is worth — and it would only work while the
 * `settings` collection stays readable by anyone, which is the door the pending rules
 * deployment is meant to shut.
 *
 * So the key is read on the SERVER and fetched from `/maps-key` (2026-09-08, at the
 * client's request). It used to be printed into the page as JSON; it is fetched now so
 * that it does not appear in view-source or the Elements panel.
 *
 * THAT IS NOT SECURITY, and nothing here should be built as though it were. The key is
 * public either way: it travels in the script URL below, in plain sight in the Network
 * tab, as it does on every site that draws a Google map. What limits it is the
 * HTTP-referrer and API restrictions set on the key in the Google Cloud console — which,
 * as of 2026-09-08, this key did not have.
 */
(function () {
    'use strict';

    var CALLBACK = '__googleMapsReady';
    var MAPS_KEY_URL = '/maps-key';

    var promise = null;
    var region = '';
    var defaultPlace = null;

    /** ISO2 region from settings, available once loadGoogleMaps() has resolved. */
    window.googleMapsRegion = function () {
        return region;
    };

    /**
     * Where the picker should open when it has no coordinates to show — `{ address,
     * lat, lng }`, or null when none is configured. Available once loadGoogleMaps()
     * has resolved, same as the region.
     */
    window.googleMapsDefaultPlace = function () {
        return defaultPlace;
    };

    /**
     * Asks the server for the key. Resolves to empty strings rather than rejecting, so
     * the "no key configured" path below stays the single place that decides what an
     * absent key means.
     */
    function settings() {
        return fetch(MAPS_KEY_URL, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.json() : {};
            })
            .then(function (parsed) {
                var place = (parsed || {}).defaultPlace || null;

                return {
                    key: ((parsed || {}).key || '').trim(),
                    region: ((parsed || {}).region || '').trim(),
                    // Only usable with BOTH coordinates: a default with an address and
                    // no point could not put the pin anywhere, which is the whole job.
                    defaultPlace: (place && place.lat && place.lng) ? place : null,
                };
            })
            .catch(function () {
                // Offline, or the endpoint is unreachable. Same answer as no key: the
                // address fields stay plain text and the booking still completes.
                return { key: '', region: '', defaultPlace: null };
            });
    }

    window.loadGoogleMaps = function () {
        if (promise) {
            return promise;
        }

        promise = settings().then(function (config) {
            if (!config.key) {
                /*
                 * No key on Global Settings, or the endpoint could not be reached.
                 * Rejected rather than left hanging, so the caller can fall back — every
                 * screen that uses a map here degrades to plain text fields, and nothing
                 * on the page breaks.
                 */
                throw new Error(window.googleMapsStrings.noKey);
            }

            region = config.region;
            defaultPlace = config.defaultPlace;

            return loadScript(config);
        }).catch(function (error) {
            // Not cached: a failed load should be retried once the key is fixed.
            promise = null;

            throw error;
        });

        return promise;
    };

    /** Injects the API tag itself, once the key is known. */
    function loadScript(config) {
        return new Promise(function (resolve, reject) {
            if (window.google && window.google.maps) {
                resolve();

                return;
            }

            var params = [
                'key=' + encodeURIComponent(config.key),
                // `places` is only needed by the typeahead, but the API takes its
                // library list once — asking for it here means a screen that loads the
                // map first does not lock the typeahead out of it.
                'libraries=places',
                'loading=async',
                'callback=' + CALLBACK,
            ];

            if (region) {
                params.push('region=' + encodeURIComponent(region));
            }

            window[CALLBACK] = function () {
                delete window[CALLBACK];
                resolve();
            };

            var script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js?' + params.join('&');
            script.async = true;
            script.onerror = function () {
                reject(new Error(window.googleMapsStrings.loadFailed));
            };

            document.head.appendChild(script);
        });
    }
})();

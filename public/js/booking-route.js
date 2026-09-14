/**
 * The charter trip step: where from, where to, and how far.
 *
 * Three jobs:
 *
 *   1. turn the pickup and drop-off boxes into address fields with Google's typeahead
 *      and a pick-on-the-map button (`initAddressField`, shared with the panel);
 *   2. let the customer add more than one drop-off, in order — a charter is an
 *      itinerary, not a single hop;
 *   3. measure the driving distance through every stop and put it in the read-only
 *      distance field.
 *
 * THE DISTANCE IS NEVER TYPED. It is measured from the coordinates behind the fields,
 * which is why an address that was typed but never picked from the dropdown counts for
 * nothing: text with no pin behind it has no coordinates, and a distance guessed from
 * it would price a trip nobody is taking. The field shows what was measured, and the
 * hidden input beside it is what the form actually posts.
 *
 * WITHOUT MAPS — no key, no network, the Directions API not enabled on the key — every
 * one of these degrades rather than breaks. The boxes stay plain text, the pin buttons
 * hide, and the distance field becomes typeable so the customer can give an estimate.
 * The booking still completes; an operator confirms the route either way.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-trip-form]');

    if (!form) {
        return;
    }

    var stopsList = form.querySelector('[data-stops]');
    var stopTemplate = form.querySelector('[data-stop-template]');
    var addStop = form.querySelector('[data-add-stop]');
    var measureButton = form.querySelector('[data-measure]');
    var distanceShown = form.querySelector('[data-distance-display]');
    var distanceValue = form.querySelector('[data-distance-value]');
    var roundTripValue = form.querySelector('[data-round-trip-value]');
    var distanceNote = form.querySelector('[data-distance-note]');

    var pickup = null;
    var mapsReady = false;

    var strings = window.bookingRouteStrings || {};

    function setDistance(km) {
        distanceValue.value = km === null ? '' : String(km);
        distanceShown.value = km === null ? '' : String(km);
    }

    /**
     * The SECOND distance: the same stops, then home again from the last one.
     *
     * A round trip is A → B → C → A. It is NOT the outbound distance doubled, which
     * would drive back through every stop — the client caught that on 2026-09-09 and
     * they are right: going home via B when B is behind you is road nobody covers.
     *
     * It has to be measured HERE, on step 1, even though one-way or round trip is not
     * asked until step 2. Step 2 has no addresses on it and no Directions call of its
     * own, and the price shown there has to match the price the booking is written
     * with. So both figures are measured together and the server picks.
     *
     * Empty is a valid state and the server knows what to do with it: a hand-typed
     * distance has no return leg to measure, and `CharterQuote` falls back to doubling
     * for that case alone.
     */
    function setRoundTrip(km) {
        if (roundTripValue) {
            roundTripValue.value = km === null ? '' : String(km);
        }
    }

    function note(message, tone) {
        distanceNote.textContent = message || '';
        distanceNote.className = 'form-help' + (tone === 'error' ? ' form-error' : '');
    }

    /**
     * The stop fields, IN THE ORDER THEY APPEAR ON SCREEN.
     *
     * Read from the DOM every time rather than kept in an array alongside it. The
     * customer can reorder the stops, and an array that has to be resorted in step with
     * the rows is an array that will one day disagree with them — at which point the
     * route is measured through the stops in an order nobody asked for.
     */
    function stopFields() {
        return Array.prototype.slice.call(stopsList.querySelectorAll('[data-stop]'))
            .map(function (row) { return row.addressField; })
            .filter(Boolean);
    }

    /** Every field that has a pin behind it, pickup first, in the order they appear. */
    function points() {
        return [pickup].concat(stopFields()).filter(Boolean).map(function (field) {
            return field.point();
        });
    }

    function ready() {
        var all = points();

        return all.length >= 2 && all.every(function (point) {
            return point !== null;
        });
    }

    function measure() {
        if (!mapsReady || !window.routeDistanceKm) {
            return;
        }

        if (!ready()) {
            setDistance(null);
            note(strings.needPoints);

            return;
        }

        var all = points();

        note(strings.measuring);

        /*
         * Two routes, one pass:
         *
         *   one way    A -> B -> C          what a one-way booking is billed
         *   round trip A -> B -> C -> A     what a return booking is billed
         *
         * Asked for together so the two can never disagree about which stops, or in
         * what order — they are built from the same `all` array on the same tick.
         */
        Promise.all([
            window.routeDistanceKm({
                origin: all[0],
                destination: all[all.length - 1],
                // Everything between the first and the last, in the order given: the van
                // drives through them, so the distance has to as well.
                waypoints: all.slice(1, -1),
            }),
            window.routeDistanceKm({
                origin: all[0],
                // Home again. Every stop becomes a waypoint, including the last one,
                // and the destination is where the trip began.
                destination: all[0],
                waypoints: all.slice(1),
            }),
        ]).then(function (results) {
            var km = results[0];
            var roundTripKm = results[1];

            if (km === null) {
                /*
                 * Directions is a separate Google API from Places and Maps, billed and
                 * enabled separately. When it is off the key, this is the branch that
                 * runs — so the field is opened up and the customer types an estimate
                 * rather than being stuck on a screen that cannot proceed.
                 */
                setDistance(null);
                setRoundTrip(null);
                allowTyping();
                note(strings.measureFailed, 'error');

                return;
            }

            setDistance(km);

            /*
             * The return leg may come back null on its own — a route Google will drive
             * one way but not back, which happens around one-way systems and ferries.
             * The outbound figure still stands, and the server doubles for the return
             * as it always did rather than refusing the booking.
             */
            setRoundTrip(roundTripKm);
            note(strings.measured);
        });
    }

    /** The fallback: the distance becomes an ordinary number the customer fills in. */
    function allowTyping() {
        distanceShown.readOnly = false;
        distanceShown.removeAttribute('aria-readonly');
        distanceShown.addEventListener('input', function () {
            distanceValue.value = distanceShown.value;

            // A typed distance has no measured return leg. Clearing it is what tells
            // the server to fall back to doubling rather than to reuse a figure from
            // whichever route was last measured.
            setRoundTrip(null);
        });

        if (measureButton) {
            measureButton.hidden = true;
        }
    }

    /**
     * Turns one box into an address field and KEEPS ITS HIDDEN COORDINATES IN STEP.
     *
     * The hidden inputs are the whole reason the coordinates reach the server:
     * `initAddressField` holds the point in a closure, and without writing it out here
     * the form posted an address with no pin behind it every time. Found by picking a
     * place on the map and watching the hidden fields stay empty.
     *
     * @param {HTMLElement} scope  the row holding the input and its hidden pair
     */
    /**
     * The ✕ inside an address box — one tap to empty it, as the mobile app has (client,
     * 2026-09-08).
     *
     * It goes through the address field's own `clear()` rather than blanking the text,
     * because the text is only half of what an address is here. `clear()` also nulls the
     * hidden latitude/longitude and drops the measured distance, and an address emptied
     * without them would leave the PREVIOUS place's pin behind it — a booking whose
     * written address and map point name two different spots, which is the failure the
     * hidden fields exist to prevent.
     *
     * Wired even when the Maps key is missing and there is no field to clear: the box is
     * an ordinary text input then, and a ✕ that empties it is still the right button.
     */
    function wireClear(scope, input, field) {
        var button = scope.querySelector('[data-address-clear]');

        if (!button || !input) {
            return function () {};
        }

        function toggle() {
            button.hidden = input.value === '';
        }

        button.addEventListener('click', function () {
            if (field) {
                field.clear();
            } else {
                input.value = '';
            }

            // `clear()` empties the box without firing `input`, so the button has to be
            // told to hide itself.
            toggle();
            input.focus();
        });

        input.addEventListener('input', toggle);
        toggle();

        /*
         * Returned, because `input` is not the only way this box gets filled — and the
         * other two are the COMMON ones. Choosing a place from the typeahead, or from
         * the map picker, assigns `input.value` directly, and an assignment fires no
         * `input` event: the ✕ stayed hidden on exactly the addresses a customer had
         * just successfully picked. `attach()` calls this from `onChange`, which both of
         * those paths do go through.
         */
        return toggle;
    }

    function attach(scope, input, button) {
        if (!window.initAddressField) {
            wireClear(scope, input, null);

            return null;
        }

        var lat = scope.querySelector('[data-lat]');
        var lng = scope.querySelector('[data-lng]');
        var toggleClear = function () {};

        var field = window.initAddressField(input, {
            button: button,
            onChange: function (point) {
                if (lat && lng) {
                    lat.value = point ? point.latitude : '';
                    lng.value = point ? point.longitude : '';
                }

                // A place picked from the map or the typeahead lands here, having set
                // the text without an `input` event. See wireClear().
                toggleClear();

                // A changed pin invalidates the measured distance immediately. Leaving
                // the old number on screen next to a new address is how a customer
                // agrees to a price for the wrong trip.
                setDistance(null);
                note(ready() ? '' : strings.needPoints);

                if (ready()) {
                    measure();
                }
            },
        });

        // A draft coming back from the session — or from a failed validation — already
        // has coordinates. Restoring them means the customer is not asked to pick every
        // address again because one field was wrong.
        if (field && lat && lng && lat.value !== '' && lng.value !== '') {
            field.setPoint(lat.value, lng.value);
        }

        toggleClear = wireClear(scope, input, field);
        toggleClear();

        return field;
    }

    function wireStop(row) {
        var input = row.querySelector('[data-stop-input]');
        var button = row.querySelector('[data-stop-pin]');

        // Hung on the row itself, so the row IS the record of that stop — nothing to
        // keep in step when the customer reorders or removes one.
        row.addressField = attach(row, input, button);

        row.querySelector('[data-stop-remove]').addEventListener('click', function () {
            row.remove();
            afterReorder();
        });

        row.querySelector('[data-stop-up]').addEventListener('click', function () {
            move(row, -1);
        });

        row.querySelector('[data-stop-down]').addEventListener('click', function () {
            move(row, 1);
        });
    }

    /**
     * Moves one stop up or down the itinerary.
     *
     * The order is not cosmetic: the van drives the stops in this order, and the
     * distance is measured through them in this order — so moving one changes the
     * price, and the measured figure has to be thrown away and taken again.
     */
    function move(row, direction) {
        var sibling = direction < 0 ? row.previousElementSibling : row.nextElementSibling;

        if (!sibling) {
            return;
        }

        if (direction < 0) {
            stopsList.insertBefore(row, sibling);
        } else {
            stopsList.insertBefore(sibling, row);
        }

        afterReorder();

        // Focus follows the row the customer just moved, so pressing the button again
        // moves the same stop rather than whatever has taken its place.
        var button = row.querySelector(direction < 0 ? '[data-stop-up]' : '[data-stop-down]');

        if (button && !button.disabled) {
            button.focus();
        }
    }

    /** Everything that has to happen after the list changes shape. */
    function afterReorder() {
        renumber();
        setDistance(null);
        measure();
    }

    /** Stops are numbered for the customer, and the numbers must survive a removal. */
    function renumber() {
        var rows = stopsList.querySelectorAll('[data-stop]');

        rows.forEach(function (row, index) {
            var label = row.querySelector('[data-stop-number]');

            if (label) {
                label.textContent = String(index + 1);
            }

            // Only offer to remove a stop when removing one would still leave a trip,
            // and only offer to reorder when there is something to reorder against.
            var alone = rows.length < 2;

            row.querySelector('[data-stop-remove]').hidden = alone;
            row.querySelector('[data-stop-move]').hidden = alone;

            // The first stop cannot go up and the last cannot go down. Disabled rather
            // than hidden, so the buttons do not jump about as rows move.
            row.querySelector('[data-stop-up]').disabled = index === 0;
            row.querySelector('[data-stop-down]').disabled = index === rows.length - 1;
        });
        /*
         * The + follows the LAST stop, immediately after that row's buttons — the
         * client's layout, 2026-09-08, replacing a full-width bar underneath that was
         * the widest control on the screen for the least important action on it.
         *
         * Moved rather than duplicated: one button, one listener, wherever it currently
         * lives. Re-placed from here because `renumber()` already runs after every add,
         * removal and reorder — including the removal of the very row the button was
         * sitting in, which detaches it from the page but not from this variable.
         */
        if (addStop && rows.length) {
            var lastRow = rows[rows.length - 1].querySelector('.address-row');

            if (lastRow) {
                lastRow.appendChild(addStop);
            }
        }

    }

    if (addStop && stopTemplate) {
        addStop.addEventListener('click', function () {
            var row = stopTemplate.content.firstElementChild.cloneNode(true);

            stopsList.appendChild(row);
            wireStop(row);
            renumber();
            row.querySelector('[data-stop-input]').focus();
        });
    }

    /*
     * The Maps API is asked for once, here. Everything above is wired only after it
     * arrives, so a page with no key never grows a pin button that would do nothing
     * when pressed.
     */
    if (window.loadGoogleMaps) {
        window.loadGoogleMaps().then(function () {
            mapsReady = true;

            form.querySelectorAll('[data-pin]').forEach(function (button) {
                button.hidden = false;
            });

            var pickupInput = form.querySelector('[data-pickup-input]');
            pickup = attach(
                pickupInput.closest('[data-pickup]') || form,
                pickupInput,
                form.querySelector('[data-pickup-pin]')
            );

            stopsList.querySelectorAll('[data-stop]').forEach(wireStop);
            renumber();

            if (measureButton) {
                measureButton.hidden = false;
                measureButton.addEventListener('click', measure);
            }

            measure();
        }).catch(function () {
            // No key, no network. Plain boxes and a typed distance — see the header.
            allowTyping();
            note(strings.mapsUnavailable);
            renumber();
        });
    } else {
        allowTyping();
        renumber();
    }
})();

/**
 * Step 1 of a shuttle booking: narrowing a long list of stops.
 *
 *   window.initShuttleRoute({
 *       direction: 'from_airport',
 *       endpoint:  '/book/shuttle/stops',
 *       cities:    [{ id, name, airport }],
 *       terminals: { airportId: ['Terminal 1', 'Terminal 2'] },
 *       labels:    { … },
 *   });
 *
 * Three searchable dropdowns — Select2's behaviour, drawn over three real `<select>`s
 * that stay in the DOM and keep their names. Only the STOP is posted; the airport and the
 * city are filters, and the stop carries its own airport and city, which is why a browser
 * with no JavaScript still produces a valid booking from the same markup.
 *
 * ── THE STOPS ARE FETCHED, AND ALSO RENDERED ────────────────────────────────
 *
 * The page arrives with every stop on the leg already in the select. This asks the server
 * for a narrowed list as the customer picks an airport and a city — which is what keeps
 * the control usable as the client adds cities — but it is a REFRESH, not the source. A
 * failed request falls back to filtering the rendered options in place, so a dropped
 * connection costs the fare and the timetable, not the ability to book.
 *
 * ── THE CITY LIST IS BUILT, NOT FETCHED ─────────────────────────────────────
 *
 * A city reachable from Juanda is not necessarily reachable from anywhere else, so the
 * list is rebuilt from the chosen airport. Offering one that leads to an empty stop list
 * is a dead end the customer has to back out of.
 *
 * ── EACH CHOICE OPENS THE NEXT ──────────────────────────────────────────────
 *
 * City is closed until an airport is chosen, and the stop until a city is. The controls
 * are DISABLED rather than empty, and say what they are waiting for: an enabled dropdown
 * that opens onto nothing reads as a fault, while a disabled one reads as a queue.
 *
 * The `<select>`s underneath stay untouched by any of this — a browser with no JavaScript
 * gets three plain lists and can still book, and the server re-reads the chosen stop
 * either way.
 */
(function () {
    'use strict';

    function option(id, name, note) {
        return { id: id, name: name, note: note || '' };
    }

    window.initShuttleRoute = function (config) {
        var airportSelect = document.getElementById('airportFilter');
        var terminalSelect = document.getElementById('terminal');
        var terminalField = document.getElementById('terminal-field');
        var citySelect = document.getElementById('cityFilter');
        var stopSelect = document.getElementById('rateId');
        var labels = config.labels || {};

        if (!airportSelect || !citySelect || !stopSelect) {
            return null;
        }

        var cities = config.cities || [];
        var terminals = config.terminals || {};
        var pickers = {};
        var pending = 0;

        /* Every stop the page rendered, kept as the fallback the fetch degrades to. */
        var rendered = Array.prototype.filter.call(stopSelect.options, function (o) {
            return !!o.value;
        }).map(function (o) {
            return {
                id: o.value,
                name: o.dataset.stop || o.textContent.trim(),
                city: o.dataset.cityName || '',
                cityId: o.dataset.city || '',
                airport: o.dataset.airport || '',
                fare: o.dataset.fare || null,
                times: (o.dataset.times || '').split(',').filter(Boolean),
            };
        });

        function dropdown(mountId, select, options, onSelect) {
            var mount = document.getElementById(mountId);

            if (!mount || !window.initSearchableSelect) {
                return null;
            }

            select.hidden = true;
            mount.hidden = false;

            return window.initSearchableSelect(mount, {
                options: options,
                value: select.value,
                placeholder: select.options[0] ? select.options[0].textContent.trim() : '',
                searchLabel: labels.search,
                emptyLabel: labels.noMatches,
                onSelect: function (id) {
                    select.value = id;

                    /*
                     * `input`, so the validator's live-clear runs and a message the
                     * customer has just acted on disappears — a picker sets `.value` from
                     * script, which fires nothing on its own, so the error would otherwise
                     * sit there until the next submit.
                     *
                     * Deliberately NOT `change`: the cascade is already wired to that
                     * event, and dispatching it here would run every handler twice.
                     */
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                    onSelect(id);
                },
            });
        }

        // ---- the terminals -----------------------------------------------------

        /**
         * The chosen airport's terminals, or an empty list.
         *
         * Not every airport has any. The admin names them per airport in the panel, and
         * two of the three live ones have exactly one — so the question is "does this
         * airport publish terminals at all", not "does it publish more than one".
         */
        function terminalsHere() {
            return terminals[airportSelect.value] || [];
        }

        /**
         * Fills the terminal dropdown, or takes the field off the page.
         *
         * Hidden with `hidden` on the whole field, not just the select: a lone label over
         * nothing reads as a form that failed to load. And the value is CLEARED when it
         * goes, so a terminal chosen at Juanda cannot be posted with a booking that has
         * since been switched to Solo.
         */
        function refreshTerminals() {
            var here = terminalsHere();
            var options = [option('', labels.chooseTerminal)].concat(
                here.map(function (name) { return option(name, name); }),
            );
            var wanted = terminalSelect.value;
            var stillThere = here.indexOf(wanted) !== -1;

            terminalSelect.innerHTML = '';
            options.forEach(function (o) { terminalSelect.appendChild(new Option(o.name, o.id)); });
            terminalSelect.value = stillThere ? wanted : '';

            if (pickers.terminal) {
                pickers.terminal.setOptions(options);
                pickers.terminal.set(terminalSelect.value);
            }

            if (terminalField) {
                terminalField.hidden = here.length === 0;
            }

            /*
             * `data-required` rather than `required`: the select is hidden behind the
             * searchable dropdown drawn over it, and `form-validate.js` skips hidden
             * fields unless they ask to be checked. Removed outright when the field is
             * gone, or the form would refuse to submit over a control nobody can see.
             */
            if (here.length === 0) {
                terminalSelect.removeAttribute('data-required');
            } else {
                terminalSelect.setAttribute('data-required', '');
            }

            markRequiredStep();
        }

        // ---- the city list -----------------------------------------------------

        function cityOptions() {
            var airport = airportSelect.value;

            if (!airport) {
                return [option('', labels.pickAirportFirst)];
            }

            return [option('', labels.selectCity)].concat(
                cities.filter(function (city) {
                    return city.airport === airport;
                }).map(function (city) {
                    return option(city.id, city.name);
                }),
            );
        }

        /**
         * Closes a dropdown that has nothing to offer yet, and says what it is waiting for.
         *
         * ── ONLY A FILTER MAY BE DISABLED ───────────────────────────────────────
         *
         * The airport and city selects carry no `name`; they narrow the list and nothing
         * more, so disabling them costs the form nothing. The STOP select is
         * `name="rateId"` — it is the field the form posts — and disabling that one:
         *
         *   - drops it from the submission entirely, so the server sees no `rateId` and
         *     answers with its own generic "The rate id field is required";
         *   - hides it from `form-validate.js`, which skips disabled fields, so nothing
         *     is caught in the browser at all.
         *
         * Which is exactly what happened: pressing Continue with nothing chosen made a
         * round trip and came back with the framework's wording instead of ours.
         *
         * The visible control is still closed either way — the customer cannot open a
         * dropdown that has nothing in it — but the field stays part of the form.
         */
        function gate(picker, select, open, waitingFor) {
            if (!select.name) {
                select.disabled = !open;
            }

            if (!picker) {
                return;
            }

            picker.setDisabled(!open, open ? null : waitingFor);
        }

        /**
         * Marks the ONE step the customer can actually take next.
         *
         * The three choices are a queue, so an empty city and an empty stop are not three
         * separate faults — they are one unanswered question and two consequences of it.
         * Reporting all three would put a message on two controls that cannot be used
         * yet, and the earliest of them is the only one worth pointing at.
         *
         * A field behind the queue therefore carries no `data-required` at all: it is not
         * "required and empty", it is not yet askable. `form-validate.js` walks the form
         * in document order, so the one field left marked is also the first it finds, and
         * the error lands on the control the customer has to touch.
         */
        function markRequiredStep() {
            var steps = [
                { select: airportSelect, message: labels.selectAirport },
                // Second, and only when this airport has any: it belongs to the airport,
                // and an airport without terminals must not stall the queue on a field
                // that is not on the page.
                { select: terminalSelect, message: labels.chooseTerminal, skip: terminalsHere().length === 0 },
                { select: citySelect, message: labels.selectCity },
                { select: stopSelect, message: labels.chooseStop },
            ];
            var claimed = false;

            steps.forEach(function (step) {
                if (!step.select || step.skip) {
                    return;
                }

                var isNext = !claimed && !step.select.value;

                if (isNext) {
                    claimed = true;
                    step.select.setAttribute('data-required', '');
                    step.select.dataset.requiredMessage = step.message;

                    return;
                }

                step.select.removeAttribute('data-required');
            });
        }

        function refreshCities() {
            var options = cityOptions();
            var wanted = citySelect.value;
            var stillThere = options.some(function (o) { return o.id === wanted && o.id; });

            citySelect.innerHTML = '';
            options.forEach(function (o) { citySelect.appendChild(new Option(o.name, o.id)); });
            citySelect.value = stillThere ? wanted : '';

            if (pickers.city) {
                pickers.city.setOptions(options);
                pickers.city.set(citySelect.value);
            }

            gate(pickers.city, citySelect, !!airportSelect.value, labels.pickAirportFirst);
            markRequiredStep();
        }

        // ---- the stop list -----------------------------------------------------

        /**
         * A stop, named by ITSELF.
         *
         * The city used to be prefixed — "Ngawi City · Ngawi (Pondok Gontor)" — which on
         * a phone left "Ngawi City · Ngaw…" and hid the one part the customer is choosing
         * between. It was never information either: the city is picked in the field
         * directly above, so every option in the list carries the same prefix.
         * Client's report, 2026-09-02.
         */
        function stopOption(stop) {
            return option(stop.id, stop.name, stop.fare);
        }

        function paintStops(stops) {
            var wanted = stopSelect.value;
            var ready = !!airportSelect.value && !!citySelect.value;
            var options = [option('', ready ? labels.chooseStop : labels.pickCityFirst)]
                .concat(ready ? stops.map(stopOption) : []);

            stopSelect.innerHTML = '';

            options.forEach(function (o) {
                stopSelect.appendChild(new Option(o.name, o.id));
            });

            var stillThere = stops.some(function (stop) { return stop.id === wanted; });

            stopSelect.value = ready && stillThere ? wanted : '';

            if (pickers.stop) {
                pickers.stop.setOptions(options);
                pickers.stop.set(stopSelect.value);
            }

            gate(pickers.stop, stopSelect, ready, labels.pickCityFirst);
            markRequiredStep();
            describe(stops);
        }

        /** The chosen stop's departures, so the timetable is seen before committing. */
        function describe(stops) {
            var note = document.getElementById('stop-note');
            var chosen = stops.filter(function (stop) { return stop.id === stopSelect.value; })[0];

            if (!note) {
                return;
            }

            if (!chosen) {
                note.textContent = labels.chooseStopHint;

                return;
            }

            note.textContent = chosen.times.length
                ? labels.departuresAre.split(':times').join(chosen.times.join(' · '))
                : labels.noDepartures;
        }

        /** Filtering what the page already rendered — the answer when the fetch fails. */
        function localStops() {
            var airport = airportSelect.value;
            var city = citySelect.value;

            return rendered.filter(function (stop) {
                return (!airport || stop.airport === airport) && (!city || stop.cityId === city);
            });
        }

        function refreshStops() {
            if (!airportSelect.value || !citySelect.value) {
                paintStops([]);

                return;
            }

            var url = config.endpoint
                + '?direction=' + encodeURIComponent(config.direction)
                + '&airport=' + encodeURIComponent(airportSelect.value)
                + '&city=' + encodeURIComponent(citySelect.value);
            var token = ++pending;

            if (!window.fetch) {
                paintStops(localStops());

                return;
            }

            fetch(url, { headers: { Accept: 'application/json' } })
                .then(function (response) {
                    return response.ok ? response.json() : Promise.reject(response.status);
                })
                .then(function (data) {
                    // A slower earlier request must not overwrite a faster later one.
                    if (token === pending) {
                        paintStops(data.stops || []);
                    }
                })
                .catch(function () {
                    if (token === pending) {
                        paintStops(localStops());
                    }
                });
        }

        // ---- wiring ------------------------------------------------------------

        pickers.airport = dropdown('airport-picker', airportSelect, [option('', labels.selectAirport)].concat(
            Array.prototype.filter.call(airportSelect.options, function (o) { return !!o.value; })
                .map(function (o) { return option(o.value, o.textContent.trim()); }),
        ), function () {
            refreshTerminals();
            refreshCities();
            refreshStops();
        });

        // Searchable like the airport above it, and for the same reason: it is the same
        // kind of choice, and two controls side by side that behave differently is worse
        // than either behaviour on its own.
        pickers.terminal = dropdown('terminal-picker', terminalSelect, [option('', labels.chooseTerminal)], function () {
            markRequiredStep();
        });

        pickers.city = dropdown('city-picker', citySelect, cityOptions(), refreshStops);

        pickers.stop = dropdown('stop-picker', stopSelect, [option('', labels.chooseStop)].concat(
            rendered.map(stopOption),
        ), function () {
            describe(rendered);
        });

        airportSelect.addEventListener('change', function () {
            refreshTerminals();
            refreshCities();
            refreshStops();
        });

        terminalSelect.addEventListener('change', markRequiredStep);

        citySelect.addEventListener('change', refreshStops);
        stopSelect.addEventListener('change', function () {
            markRequiredStep();
            describe(rendered);
        });

        refreshTerminals();

        /*
         * The terminal the draft already holds, put back after the list exists. Set on
         * the SELECT first and mirrored onto the dropdown, because the select is what
         * posts — a customer sent back by a validation error must not lose it.
         */
        if (config.chosenTerminal && terminalsHere().indexOf(config.chosenTerminal) !== -1) {
            terminalSelect.value = config.chosenTerminal;

            if (pickers.terminal) {
                pickers.terminal.set(config.chosenTerminal);
            }

            markRequiredStep();
        }

        refreshCities();

        // The city is only known from the stop the draft already holds, if there is one.
        var chosen = rendered.filter(function (stop) { return stop.id === stopSelect.value; })[0];

        if (chosen && chosen.cityId) {
            citySelect.value = chosen.cityId;

            if (pickers.city) {
                pickers.city.set(chosen.cityId);
            }
        }

        refreshStops();

        return { refresh: refreshStops };
    };
}());

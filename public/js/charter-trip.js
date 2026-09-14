/**
 * Step 2 of a charter booking: the vehicle, the trip type, the dates and the price.
 *
 * Reads `window.charterTrip` (the counts, the vehicle rates, the measured distance) and
 * `window.charterTripLabels` (every string, already translated), and wires four fields
 * that all answer to each other:
 *
 *   passengers  → which car types may be chosen
 *   car type    → which vans are listed, what the month grid counts, and the daily rate
 *   trip type   → one date or a range, and whether the distance doubles
 *   the dates   → which vans are still free, and how many days are billed
 *   the vehicle → whether the month reads "free / taken" or counts what is left
 *
 * `day-calendar.js` draws the month and owns the colours; everything here is the answer
 * to the one question it asks — what is left on a given day.
 *
 * ── ONE GRID, TWO DATES ─────────────────────────────────────────────────────
 *
 * The app picks a whole trip on a single month: tap a day for a one-way, tap two for a
 * return and the days between fill in. That is what this does, rather than the two
 * calendars the panel shows an operator. The two native date fields are still there
 * underneath — hidden when the grid mounts, and still the fields the form posts.
 *
 * ── THE PRICE IS COMPUTED TWICE, ON PURPOSE ─────────────────────────────────
 *
 * `CharterQuote` on the server is the price. This is an ESTIMATE, drawn as the customer
 * moves things, and it is labelled as one on screen; the server re-derives the real
 * figure for the summary and again for the booking write. The rules are duplicated here
 * only so the number can move without a page load, and the numbers they read — the
 * rates, the 500 km threshold — are handed over by the server rather than written down
 * again in this file.
 */
(function () {
    'use strict';

    function fromKey(key) {
        return new Date(key + 'T12:00:00');
    }

    function dayKey(date) {
        return date.getFullYear()
            + '-' + String(date.getMonth() + 1).padStart(2, '0')
            + '-' + String(date.getDate()).padStart(2, '0');
    }

    /** Every day from one key to another, inclusive — the days a trip actually holds. */
    function range(from, to) {
        var days = [];
        var cursor = fromKey(from);
        var end = fromKey(to);

        while (cursor <= end) {
            days.push(dayKey(cursor));
            cursor.setDate(cursor.getDate() + 1);

            // A hand-edited field cannot be allowed to spin the browser; the booking
            // window is the real ceiling and anything past it is not a trip.
            if (days.length > 400) {
                break;
            }
        }

        return days;
    }

    function fill(template, values) {
        return Object.keys(values).reduce(function (text, key) {
            return text.split(':' + key).join(values[key]);
        }, template || '');
    }

    /**
     * A price, written the way the admin's currency document says.
     *
     * `,` groups thousands and `.` marks decimals, matching `Currency::format()` on the
     * server and the admin panel beyond it, so an estimate, a confirmed price and the
     * operator's own screen never look like three different currencies.
     */
    function money(amount, spec) {
        var fixed = Math.round(amount * Math.pow(10, spec.decimalDigits)) / Math.pow(10, spec.decimalDigits);
        var parts = fixed.toFixed(spec.decimalDigits).split('.');

        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        var number = parts.join('.');

        return spec.symbolAtRight ? number + ' ' + spec.symbol : spec.symbol + ' ' + number;
    }

    /**
     * A distance, rounded to one decimal.
     *
     * `.` is the decimal mark here as it is everywhere else on the site — see `money()`.
     * No thousands separator: a trip long enough to need one does not exist.
     */
    function kilometres(value) {
        return (Math.round(value * 10) / 10).toString();
    }

    /** A date the way a customer reads one: 27/08/2026, as the app writes it. */
    function readable(key) {
        var date = fromKey(key);

        return String(date.getDate()).padStart(2, '0')
            + '/' + String(date.getMonth() + 1).padStart(2, '0')
            + '/' + date.getFullYear();
    }

    window.initCharterTrip = function (config) {
        var data = config.data;
        var labels = config.labels;
        var calendar = data.calendar;
        var mount = document.getElementById('travel-calendar');
        var party = document.getElementById('passengers');
        var typeField = document.getElementById('vehicleTypeId');
        var unitField = document.getElementById('vehicleUnitId');
        var travel = document.getElementById('travelDate');
        var back = document.getElementById('returnDate');
        var time = document.getElementById('pickupTime');
        var grid;

        if (!mount || !party || !typeField || !travel || !back) {
            return null;
        }

        /*
         * The chosen trip, held here rather than read back off the fields.
         *
         * `day-calendar.js` writes the clicked day into the travel field BEFORE handing
         * it to `onPick`, so by then the field can no longer say what the start was —
         * and on a return trip that is exactly the question: is this click the start of
         * a new trip, or the day it comes back?
         */
        var trip = { start: travel.value || '', end: back.value || '' };

        /* The searchable dropdowns drawn over the two selects, once they are mounted. */
        var typePicker = null;
        var unitPicker = null;

        function roundTrip() {
            var chosen = document.querySelector('input[name="tripType"]:checked');

            return !!chosen && chosen.value === 'round_trip';
        }

        function chosenType() {
            var id = typeField.value;

            for (var i = 0; i < data.types.length; i += 1) {
                if (data.types[i].id === id) {
                    return data.types[i];
                }
            }

            return null;
        }

        /**
         * The counts for the chosen car type.
         *
         * Matched on seats rather than carried by id: the payload groups by type and the
         * grid only ever needs the one the customer is looking at.
         */
        function poolFor(type) {
            if (!type) {
                return null;
            }

            for (var i = 0; i < calendar.types.length; i += 1) {
                if (calendar.types[i].seats === type.seats) {
                    return calendar.types[i];
                }
            }

            return null;
        }

        /** How many of the chosen type could do the WHOLE of these days. */
        function freeOver(days) {
            var pool = poolFor(chosenType());
            var survivors = null;

            if (!pool || !days.length) {
                return 0;
            }

            days.forEach(function (day) {
                var free = pool.free[day] || [];

                survivors = survivors === null
                    ? free.slice()
                    : survivors.filter(function (unit) {
                        return free.indexOf(unit) !== -1;
                    });
            });

            return survivors ? survivors.length : 0;
        }

        /** Every van of the chosen type, free or not. */
        function unitsOfType() {
            var pool = poolFor(chosenType());

            return pool ? pool.units : [];
        }

        /**
         * The vans of the chosen type that are free for the WHOLE trip.
         *
         * The same intersection the count is drawn from, kept as vehicles rather than
         * reduced to a number — the dropdown has to name them.
         *
         * With no dates chosen there is no trip to be free for, and the answer is an
         * empty list. That is NOT the same as "no vehicles": see `refreshUnits`, which
         * lists the whole type until the dates narrow it.
         */
        function freeUnits(days) {
            var pool = poolFor(chosenType());
            var survivors = null;

            if (!pool || !days.length) {
                return [];
            }

            days.forEach(function (day) {
                var free = pool.free[day] || [];

                survivors = survivors === null
                    ? free.slice()
                    : survivors.filter(function (unit) {
                        return free.indexOf(unit) !== -1;
                    });
            });

            return (survivors || []).map(function (position) {
                return pool.units[position];
            }).filter(Boolean);
        }

        function chosenUnit() {
            return unitField ? unitField.value : '';
        }

        /** Whether one named van is free on every one of these days. */
        function unitIsFree(unitId, days) {
            return freeUnits(days).some(function (unit) {
                return unit.id === unitId;
            });
        }

        /**
         * What one day looks like on the grid.
         *
         * Once a van has been NAMED, a count is the wrong answer: "1 free" on the van
         * you already chose reads as scarcity when it means yours is available. So the
         * day is free or it is taken, and the colour says which — the panel's rule.
         */
        function dayState(day) {
            var unitId = chosenUnit();

            if (unitId) {
                var free = unitIsFree(unitId, [day]);

                return {
                    kind: free ? 'available' : 'full',
                    label: free ? labels.unitFree : labels.unitTaken,
                };
            }

            var count = freeOver([day]);

            return {
                count: count,
                label: count
                    ? fill(count === 1 ? labels.oneFree : labels.manyFree, { count: count })
                    : labels.noneFree,
            };
        }

        /**
         * Refills the vehicle list from the chosen CAR TYPE, and then from the dates.
         *
         * The type alone fills the list — that is the question the customer just
         * answered, and a dropdown that stays empty until a date is picked looks broken.
         * Until 2026-08-26 it did exactly that.
         *
         * The dates then narrow it. Every van of the type is listed either way, but one
         * that is taken on the chosen dates is shown DISABLED and labelled, rather than
         * removed: a customer who can see their vehicle is booked can move the dates; one
         * who never sees it concludes the business does not own it.
         *
         * Both the real `<select>` — which is what posts — and the dropdown drawn over
         * it. A selection that has become unavailable is dropped rather than kept:
         * leaving it would send the booking against a van that has since been taken.
         */
        function refreshUnits() {
            var note = document.getElementById('vehicle-unit-note');
            var days = chosenDays();
            var all = unitsOfType();
            var free = freeUnits(days);
            var wanted = chosenUnit();
            var options = [{ id: '', name: labels.anyVehicle }];

            if (!unitField) {
                return;
            }

            function isFree(unit) {
                // With no dates yet, nothing is known to be taken.
                return !days.length || free.some(function (candidate) {
                    return candidate.id === unit.id;
                });
            }

            unitField.innerHTML = '';
            unitField.appendChild(new Option(labels.anyVehicle, ''));

            all.forEach(function (unit) {
                var option = new Option(unit.name, unit.id);

                option.disabled = !isFree(unit);
                unitField.appendChild(option);

                options.push({
                    id: unit.id,
                    name: unit.name,
                    note: isFree(unit) ? '' : labels.unitTakenShort,
                    disabled: !isFree(unit),
                });
            });

            // Kept only if it is still on offer.
            unitField.value = all.some(function (unit) {
                return unit.id === wanted && isFree(unit);
            }) ? wanted : '';

            if (unitPicker) {
                unitPicker.setOptions(options);
                unitPicker.set(unitField.value);
            }

            if (note) {
                note.textContent = !all.length
                    ? labels.unitNone
                    : (!days.length ? labels.unitAnyNote : (free.length ? labels.unitAnyNote : labels.unitNone));
            }
        }

        // ---- The party size decides what may be chosen -------------------------

        /**
         * Hides the car types too small for the party.
         *
         * Hidden AND disabled: a hidden option is still selectable from a keyboard in
         * some browsers, and the server refuses it anyway — but a customer should not be
         * able to reach a refusal from a list that was supposed to have taken it away.
         */
        function refreshTypes() {
            var seats = parseInt(party.value, 10);
            var wanted = Number.isFinite(seats) && seats > 0 ? seats : 1;
            var chosen = typeField.value;
            var firstFit = '';

            Array.prototype.forEach.call(typeField.options, function (option) {
                var fits = parseInt(option.getAttribute('data-seats'), 10) >= wanted;

                option.hidden = !fits;
                option.disabled = !fits;

                if (fits && !firstFit) {
                    firstFit = option.value;
                }
            });

            // The chosen car just became too small. Moving to the smallest one that
            // fits is better than leaving a selection the form will refuse.
            var current = typeField.querySelector('option[value="' + chosen + '"]');

            if (!chosen || !current || current.disabled) {
                typeField.value = firstFit;
            }
        }

        // ---- Picking the dates -------------------------------------------------

        /**
         * A click on a day.
         *
         * One way: the day is the trip. Round trip: the first click sets the start and
         * clears any return, the second sets the return, and a third starts again — the
         * behaviour of every date-range picker, and the app's.
         *
         * A click BEFORE the start becomes the new start rather than an error. Someone
         * reaching backwards is telling you where the trip begins.
         */
        /**
         * Can this round trip be there and back in ONE day?
         *
         * The client's rule, 2026-09-07: yes, when the whole round trip is under the
         * distance a day of driving covers. The same arithmetic the price uses — a trip
         * that BILLS as one day is a trip that can be DONE in one day — and the
         * threshold is read off the page rather than written again here, so the calendar
         * and the invoice cannot drift apart. `CharterQuote::sameDayReturnFits()` is the
         * server's copy of this, and the one that actually decides.
         */
        /**
         * What a return trip actually covers: A -> B -> C -> A.
         *
         * The loop step 1 measured, and the outbound doubled only when there is no loop
         * to use — a hand-typed distance has no return leg. Doubling would bill for
         * driving home through every stop, which the client corrected on 2026-09-09.
         *
         * `CharterQuote::returnDistance()` is the server's copy, and the one that
         * decides. This exists so the running estimate on this screen shows the same
         * number the booking is written with.
         */
        function returnKm() {
            if (data.roundTripKm > 0) {
                return Math.round(data.roundTripKm * 10) / 10;
            }

            return Math.round(data.distanceKm * 2 * 10) / 10;
        }

        function sameDayFits() {
            return Math.max(1, Math.ceil(returnKm() / data.distanceDayThresholdKm)) <= 1;
        }

        function pick(day) {
            /*
             * A click BEFORE the start is always a new start — someone reaching
             * backwards is telling you where the trip begins.
             *
             * A click ON the start is the interesting one. It used to mean "start here"
             * unconditionally, which made a same-day return impossible to express: a
             * customer going 180 km each way and home by evening had to name tomorrow
             * and be billed for two days. It now CLOSES the range when the trip is short
             * enough to drive in a day, and still restarts when it is not — there is no
             * point offering a same-day return for a trip that cannot be driven in one.
             */
            var startsAgain = !roundTrip()
                || !trip.start
                || trip.end
                || day < trip.start
                || (day === trip.start && !sameDayFits());

            if (startsAgain) {
                trip = { start: day, end: '' };
            } else {
                trip.end = day;
            }

            travel.value = trip.start;
            back.value = trip.end;

            refreshUnits();
            grid.setValue(trip.start);
            describe();
            estimate();
            availability();
        }

        /**
         * Which days the grid should draw as part of the trip.
         *
         * The calendar highlights the field's own value; the days BETWEEN a start and a
         * return are the trip too, and marking only the ends would leave a customer
         * looking at a three-day booking with two days highlighted.
         */
        function chosenDays() {
            if (!trip.start) {
                return [];
            }

            return trip.end ? range(trip.start, trip.end) : [trip.start];
        }

        /**
         * The red box under the calendar, which only the Continue button fills in.
         *
         * Cleared whenever the dates change, because it is an answer to a press of
         * Continue and not a standing fact. Leaving it up meant a customer who then
         * chose their return date read the SAME sentence twice at once — grey as the
         * instruction and red as the complaint, which is what the client reported on
         * 2026-09-07.
         */
        function clearDatesProblem() {
            var box = document.getElementById('dates-error');

            if (box) {
                box.hidden = true;
                box.innerHTML = '';
            }
        }

        function describe() {
            var note = document.getElementById('dates-note');
            var days = chosenDays();

            clearDatesProblem();

            if (!note) {
                return;
            }

            // Put back whatever the submit guard hid.
            note.hidden = false;

            if (!days.length) {
                note.textContent = labels.pickStart;

                return;
            }

            if (roundTrip() && !trip.end) {
                note.textContent = sameDayFits() ? labels.pickReturnOrSame : labels.pickReturn;

                return;
            }

            note.textContent = days.length === 1
                ? fill(labels.chosenOne, { date: readable(days[0]) })
                : fill(labels.chosenMany, {
                    from: readable(days[0]),
                    to: readable(days[days.length - 1]),
                    count: days.length,
                });
        }

        /** How many of the chosen car are free across the whole trip. */
        function availability() {
            var node = document.getElementById('vehicle-availability');
            var days = chosenDays();

            if (!node) {
                return;
            }

            if (!days.length) {
                node.textContent = labels.availablePickDate;

                return;
            }

            var free = freeOver(days);

            node.textContent = free
                ? fill(labels.available, { count: free })
                : labels.availableNone;
        }

        // ---- The running estimate ----------------------------------------------

        /**
         * `CharterQuote`, in the browser.
         *
         *     billableDays = max(days away, ceil(km / threshold), the type's minimum)
         *     price        = dailyRate × billableDays + perKmRate × km
         *
         * Taking the larger of the day counts rather than adding them: a three-day trip
         * covering 200 km is still three days of a driver's life, and a one-day trip
         * covering 900 km is two days of driving whatever the calendar says.
         */
        function estimate() {
            var value = document.getElementById('estimate-value');
            var note = document.getElementById('estimate-note');
            var type = chosenType();
            var days = chosenDays();

            if (!value || !type) {
                return;
            }

            if (!days.length) {
                value.textContent = '—';
                note.textContent = labels.estimateNeedsDates;

                return;
            }

            var km = roundTrip() ? returnKm() : data.distanceKm;
            var distanceDays = Math.max(1, Math.ceil(km / data.distanceDayThresholdKm));
            var billable = Math.max(days.length, distanceDays, type.minDailyRental);
            var total = (type.dailyRate * billable) + (type.perKmRate * km);

            value.textContent = money(total, data.money);
            note.textContent = fill(roundTrip() ? labels.estimateReturn : labels.estimateOneWay, { km: kilometres(km) })
                + ' · ' + fill(billable === 1 ? labels.estimateDay : labels.estimateDays, { count: billable });
        }

        // ---- Wiring ------------------------------------------------------------

        /** The two trip-type buttons carry their own chosen state for the stylesheet. */
        function markChoice() {
            Array.prototype.forEach.call(document.querySelectorAll('.choice'), function (choice) {
                var radio = choice.querySelector('input[type="radio"]');

                choice.classList.toggle('is-chosen', !!radio && radio.checked);
            });
        }

        function repaint() {
            refreshTypes();

            if (typePicker) {
                typePicker.setOptions(typeOptions());
                typePicker.set(typeField.value);
            }

            // Before the grid repaints: the colours depend on whether a van is named.
            refreshUnits();
            grid.refresh();
            describe();
            estimate();
            availability();
        }

        /** The car types the party fits into, as the dropdown wants them. */
        function typeOptions() {
            return Array.prototype.filter.call(typeField.options, function (option) {
                return !option.disabled;
            }).map(function (option) {
                return { id: option.value, name: option.textContent.trim() };
            });
        }

        grid = window.initDayCalendar(mount, {
            input: travel,
            locale: config.locale,
            limitedUpto: calendar.limitedUpto,
            minKey: calendar.minKey,
            maxKey: calendar.maxKey,
            state: dayState,
            /*
             * The grid writes the day it was clicked straight to the field. This form
             * needs to decide whether that click is a start or a return first, so the
             * pick is intercepted and re-made here.
             */
            onPick: pick,
            labels: {
                // Deliberately empty: the client asked for it off on the website. The
                // day is already greyed and refuses the click, and "Too soon" on today's
                // cell only crowded the number.
                tooSoon: '',
                tooLate: labels.tooLate,
                previousMonth: labels.previousMonth,
                nextMonth: labels.nextMonth,
            },
            legend: function () {
                return [
                    { kind: 'available', text: labels.legendFree },
                    { kind: 'limited', text: fill(labels.legendLimited, { count: calendar.limitedUpto }) },
                    { kind: 'full', text: labels.legendNone },
                ];
            },
            /*
             * The days between the two ends of a return trip. The calendar marks its
             * field's value; this is how it learns about the rest of the trip.
             */
            marked: chosenDays,
        });

        party.addEventListener('input', repaint);
        typeField.addEventListener('change', repaint);

        if (unitField) {
            // Only the grid and the note change: which vans exist has not moved, just
            // which one the customer means. Repainting everything would rebuild the list
            // under the choice that was just made.
            unitField.addEventListener('change', function () {
                grid.refresh();
                availability();
            });
        }

        /*
         * The dropdowns are drawn over the real selects, which stay in the DOM, keep
         * their names and keep posting. `hidden` rather than removed for exactly that
         * reason — and so a script error leaves two working selects behind.
         */
        if (window.initSearchableSelect) {
            var typeMount = document.getElementById('vehicle-type-picker');
            var unitMount = document.getElementById('vehicle-unit-picker');
            var dropdownText = {
                searchLabel: labels.search,
                emptyLabel: labels.noMatches,
            };

            if (typeMount) {
                typeField.hidden = true;
                typeMount.hidden = false;

                typePicker = window.initSearchableSelect(typeMount, Object.assign({
                    options: typeOptions(),
                    value: typeField.value,
                    onSelect: function (id) {
                        typeField.value = id;
                        repaint();
                    },
                }, dropdownText));
            }

            if (unitMount && unitField) {
                unitField.hidden = true;
                unitMount.hidden = false;

                unitPicker = window.initSearchableSelect(unitMount, Object.assign({
                    options: [{ id: '', name: labels.anyVehicle }],
                    value: '',
                    placeholder: labels.anyVehicle,
                    onSelect: function (id) {
                        unitField.value = id;
                        grid.refresh();
                        availability();
                    },
                }, dropdownText));
            }
        }

        Array.prototype.forEach.call(document.querySelectorAll('input[name="tripType"]'), function (radio) {
            radio.addEventListener('change', function () {
                // Leaving a return date on a one-way booking would bill for days the
                // customer just said they did not want.
                if (!roundTrip()) {
                    trip.end = '';
                    back.value = '';
                }

                markChoice();
                repaint();
            });
        });

        /*
         * The date fields are hidden, so their `required` has to come off with them: a
         * required control inside a hidden container blocks the submit with "an invalid
         * form control is not focusable", an error only the console sees. The guard
         * below replaces it, and the server validates the dates either way.
         */
        travel.removeAttribute('required');

        travel.form.addEventListener('submit', function (event) {
            var problem = '';

            if (!trip.start) {
                problem = labels.pickStart;
            } else if (roundTrip() && !trip.end) {
                problem = labels.pickReturn;
            }

            var box = document.getElementById('dates-error');
            var note = document.getElementById('dates-note');

            clearDatesProblem();

            if (problem) {
                event.preventDefault();
                box.hidden = false;
                box.appendChild(document.createElement('li')).textContent = problem;

                /*
                 * The note is saying the same thing — it is the instruction the customer
                 * has not followed yet — so it stands down while the red box says it
                 * louder. `describe()` puts it back the moment they pick a date.
                 */
                if (note) {
                    note.hidden = true;
                }

                mount.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });

        if (time && !time.value) {
            // The app opens on a sensible hour rather than an empty field; 08:00 is when
            // a day charter normally starts.
            time.value = '08:00';
        }

        /*
         * A return date on a one-way trip is thrown away before anything is drawn.
         * A draft can carry one — the trip type was added on 2026-08-26 and drafts made
         * before it have a return date and no answer — and billing three days for a trip
         * the page calls one-way is worse than losing the date.
         */
        if (!roundTrip() && trip.end) {
            trip.end = '';
            back.value = '';
        }

        markChoice();
        repaint();

        return { repaint: repaint };
    };
}());

/**
 * Step 2 of a shuttle booking: the date, the departure, and what it costs.
 *
 * Reads `window.shuttleTrip` (the seat counts for the whole booking window, the fare) and
 * `window.shuttleTripLabels`.
 *
 * ── THE DAY SHOWS THE CHOSEN RUN ────────────────────────────────────────────
 *
 * A day has as many seat counts as the stop has departures, so what a single cell means
 * depends on which bus the customer is asking about. The departure is chosen FIRST — it
 * is above the calendar on the page — and from then on the month counts THAT run, which
 * is the number the booking will actually be measured against.
 *
 * Before a departure is chosen the grid shows the LARGEST count of the day, because the
 * question being asked of a month with nothing picked is "can I travel that day at all";
 * colouring it by the worst run would grey out a date with an empty bus on it. The line
 * above the grid says which of the two is being shown, so a number never changes meaning
 * without saying so.
 *
 * ── THE RUN IS A POSITION, NOT A TIME ───────────────────────────────────────
 *
 * The radios post a time because that is what a customer reads, but the seats are keyed
 * on the run's INDEX in this stop's timetable. Coming back from a city the same bus
 * collects each stop as it reaches it, so 15:15 at one stop and 17:00 at another are the
 * same vehicle. The index is what the server stores.
 *
 * ── NULL IS NOT ZERO ────────────────────────────────────────────────────────
 *
 * A seat count of `null` means the number could not be read, not that the bus is full.
 * Those days are drawn plain and say so, because telling a customer "sold out" when the
 * truth is "we could not check" loses a booking that was available.
 */
(function () {
    'use strict';

    function fill(template, values) {
        return Object.keys(values || {}).reduce(function (text, key) {
            return text.split(':' + key).join(values[key]);
        }, template || '');
    }

    function money(amount, spec) {
        var factor = Math.pow(10, spec.decimalDigits);
        var parts = (Math.round(amount * factor) / factor).toFixed(spec.decimalDigits).split('.');

        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        var number = parts.join('.');

        return spec.symbolAtRight ? number + ' ' + spec.symbol : spec.symbol + ' ' + number;
    }

    /** A date the way a customer reads one: 27/08/2026, as the app writes it. */
    function readable(key) {
        var date = new Date(key + 'T12:00:00');

        return String(date.getDate()).padStart(2, '0')
            + '/' + String(date.getMonth() + 1).padStart(2, '0')
            + '/' + date.getFullYear();
    }

    window.initShuttleTrip = function (config) {
        var data = config.data;
        var labels = config.labels;
        var calendar = data.calendar;
        var mount = document.getElementById('travel-calendar');
        var travel = document.getElementById('travelDate');
        var party = document.getElementById('passengers');
        var runs = Array.prototype.slice.call(document.querySelectorAll('.run'));
        var grid;

        if (!mount || !travel) {
            return null;
        }

        function chosenDay() {
            return travel.value || '';
        }

        function chosenRun() {
            var picked = document.querySelector('input[name="pickupTime"]:checked');

            return picked ? picked.closest('.run') : null;
        }

        /** The index of the chosen departure, or null while none is chosen. */
        function chosenRunIndex() {
            var run = chosenRun();

            return run ? parseInt(run.dataset.run, 10) : null;
        }

        /** The seats free on each run of a day, in run order. `null` means unknown. */
        function seatsOn(day) {
            var byRun = calendar.seats[day];

            if (!byRun) {
                return [];
            }

            return calendar.times.map(function (time, index) {
                var seats = byRun[index];

                return seats === undefined ? null : seats;
            });
        }

        /*
         * What the server sends for a run the admin has called off, in place of a number.
         * `SeatAvailability::CANCELLED` on the PHP side; the two must stay the same word.
         */
        var CANCELLED = 'cancelled';

        function isCancelled(seats) {
            return seats === CANCELLED;
        }

        /**
         * The emptiest run of a day, or null when no run has a number.
         *
         * Cancelled runs are skipped rather than compared. `'cancelled' > 4` is false and
         * would have looked like it worked, right up to a day whose ONLY run is cancelled
         * — where `best === null` is still true and the sentinel becomes the day's count.
         */
        function bestOn(day) {
            var best = null;

            seatsOn(day).forEach(function (seats) {
                if (seats === null || isCancelled(seats)) {
                    return;
                }

                if (best === null || seats > best) {
                    best = seats;
                }
            });

            return best;
        }

        /** Whether this route has any called-off run at all in the booking window. */
        function hasCancellation() {
            return Object.keys(calendar.seats || {}).some(function (day) {
                return seatsOn(day).some(isCancelled);
            });
        }

        /** Whether every run of a day has been called off — the day itself is then off. */
        function allCancelledOn(day) {
            var seats = seatsOn(day);

            return seats.length > 0 && seats.every(isCancelled);
        }

        function seatLabel(seats) {
            if (seats === null) {
                return labels.unknownSeats;
            }

            // Before the `<= 0` test, which a cancelled run also passes. The two look
            // identical in the data and must not look identical on the page.
            if (isCancelled(seats)) {
                return labels.notRunning;
            }

            if (seats <= 0) {
                return labels.soldOut;
            }

            return fill(seats === 1 ? labels.oneSeat : labels.manySeats, { count: seats });
        }

        function dayState(day) {
            var run = chosenRunIndex();
            var seats = run === null ? bestOn(day) : seatsOn(day)[run];

            /*
             * A day with nothing running is CLOSED, not full.
             *
             * The calendar draws `closed` grey and `full` pink, and the difference is the
             * whole point: pink says "everyone else got there first, try tomorrow", grey
             * says "there is no bus". A cancelled 30 August drawn pink sent customers
             * looking for seats that were never for sale — the client's report,
             * 2026-08-27.
             *
             * When no run is chosen this needs EVERY run off, not the best one: one
             * cancelled departure out of two leaves the day perfectly bookable.
             */
            if (isCancelled(seats) || (run === null && allCancelledOn(day))) {
                return { kind: 'closed', label: labels.notRunning };
            }

            // Nothing known: drawn plain, never green. "We have not looked" must not read
            // as "there is room".
            if (seats === null || seats === undefined) {
                return null;
            }

            return { count: seats, label: seatLabel(seats) };
        }

        /**
         * Says which bus the month is counting.
         *
         * Without it the same green cell means "the emptiest of three buses" one moment
         * and "the 20:00" the next, and nothing on screen marks the change.
         */
        function describeScope() {
            var note = document.getElementById('seats-scope');
            var run = chosenRun();

            if (!note) {
                return;
            }

            note.textContent = run
                ? fill(labels.seatsForRun, { time: run.querySelector('.run-time').textContent })
                : labels.seatsBestRun;
        }

        /**
         * Puts each run's own seat count beside it, and disables the ones with no room.
         *
         * A full run is SHOWN, greyed, rather than hidden: a customer who can see the
         * 16:00 is full can take the 20:00, while one who never sees it wonders whether
         * the bus exists.
         */
        function refreshRuns() {
            var day = chosenDay();
            var seats = day ? seatsOn(day) : [];

            runs.forEach(function (run, index) {
                var radio = run.querySelector('input[type="radio"]');
                var note = run.querySelector('[data-run-seats]');
                var left = day ? seats[index] : null;
                var off = day && isCancelled(left);
                // With no date yet nothing is known to be full, so nothing is disabled.
                var full = day && !off && left !== null && left <= 0;

                if (note) {
                    note.textContent = day ? seatLabel(left) : '';
                }

                run.classList.toggle('is-full', !!full);
                run.classList.toggle('is-cancelled', !!off);
                run.classList.toggle('is-chosen', !!radio && radio.checked);

                /*
                 * The sentinel is written through to the dataset rather than blanked.
                 * Blanking it reads back as "not knowable", which is the one state that
                 * lets the booking through — a cancelled run would have been bookable.
                 */
                run.dataset.seats = off
                    ? CANCELLED
                    : ((left === null || left === undefined) ? '' : String(left));

                if (radio) {
                    radio.disabled = !!full || !!off;

                    // A run that has just filled up loses the selection rather than
                    // carrying a choice the form would refuse.
                    if ((full || off) && radio.checked) {
                        radio.checked = false;
                        run.classList.remove('is-chosen');
                    }
                }
            });
        }

        function describe() {
            var note = document.getElementById('dates-note');

            if (!note) {
                return;
            }

            note.textContent = chosenDay()
                ? fill(labels.chosen, { date: readable(chosenDay()) })
                : labels.pickDate;
        }

        function estimate() {
            var value = document.getElementById('estimate-value');
            var note = document.getElementById('estimate-note');
            var seats = parseInt(party ? party.value : '1', 10);

            if (!value) {
                return;
            }

            seats = Number.isFinite(seats) && seats > 0 ? seats : 1;

            if (!data.fare) {
                // A route quoted per booking has no published fare to multiply.
                value.textContent = '—';
                note.textContent = '';

                return;
            }

            value.textContent = money(data.fare * seats, data.money);
            note.textContent = fill(labels.estimateSeats, {
                count: seats,
                fare: money(data.fare, data.money),
            });
        }

        /**
         * The seats on the chosen departure: a number, `CANCELLED`, or null.
         *
         * Null covers three different situations that must all behave the same way — no
         * date yet, no departure yet, and a count that could not be read — because in none
         * of them is there a number to refuse against. A cancelled run is NOT one of them:
         * that is known, and known to be unbookable.
         */
        function seatsAvailable() {
            var run = chosenRun();

            if (!run || !chosenDay()) {
                return null;
            }

            var seats = run.dataset.seats;

            if (isCancelled(seats)) {
                return CANCELLED;
            }

            return seats === '' || seats === undefined ? null : parseInt(seats, 10);
        }

        /**
         * Caps the seat field at what the departure actually has.
         *
         * The client's rule: *"if 3 seats are available, customers cannot book more than
         * 3 seats"*. `max` is set so the number spinner stops there and the customer is
         * steered rather than corrected; the submit guard below is what enforces it,
         * because a typed number ignores the spinner.
         */
        function capSeats() {
            var left = seatsAvailable();

            if (!party) {
                return;
            }

            if (left === null || isCancelled(left)) {
                party.max = 30;
                party.removeAttribute('data-range-message');

                return;
            }

            party.max = Math.max(1, left);
            party.dataset.rangeMessage = left > 0
                ? fill(labels.onlySeatsLeft, { count: left })
                : labels.soldOutDeparture;
        }

        /**
         * The first thing standing between the customer and the next step, or ''.
         *
         * In the order the screen asks for them, so the reason named is the one nearest
         * the top of the page rather than whichever check happens to run first.
         */
        function whatIsMissing() {
            if (!chosenDay()) {
                return labels.pickDate;
            }

            if (runs.length && !chosenRun()) {
                return labels.pickRun;
            }

            var left = seatsAvailable();

            /*
             * Named before the seat count, because both stop the booking and only this
             * one explains itself. "Fully booked" on a bus that is not running sends the
             * customer back tomorrow for the same answer.
             */
            if (isCancelled(left)) {
                return labels.cancelledDeparture;
            }

            var wanted = parseInt(party ? party.value : '1', 10);

            if (left !== null && Number.isFinite(wanted) && wanted > left) {
                return left > 0
                    ? fill(labels.onlySeatsLeft, { count: left })
                    : labels.soldOutDeparture;
            }

            return '';
        }

        /**
         * Closes Continue while the form cannot be sent, and says why beside it.
         *
         * A disabled button on its own is a dead end — the customer is refused with no
         * reason and nothing to act on, which is worse than letting them press it and
         * being told. So the reason is written next to the button and kept current on
         * every change; the button is never dark without a sentence beside it.
         */
        function gateContinue() {
            var button = document.getElementById('shuttle-continue');
            var note = document.getElementById('continue-note');
            var missing = whatIsMissing();

            if (button) {
                button.disabled = !!missing;
            }

            if (note) {
                note.textContent = missing;
                note.hidden = !missing;
            }

            /*
             * And the field says it too. The reason beside a closed button explains the
             * button; it does not point at the control to change, and with Continue
             * disabled the customer can never trigger the field's own check by pressing it.
             *
             * Guarded because the validator is wired up after this script runs — the very
             * first paint happens without it, and every later one has it.
             */
            if (party && party.form.validation) {
                party.form.validation.checkField(party);
            }
        }

        function repaint() {
            refreshRuns();
            capSeats();
            gateContinue();
            describeScope();
            // After the runs: the grid's colours depend on which one is chosen.
            grid.refresh();
            describe();
            estimate();
        }

        grid = window.initDayCalendar(mount, {
            input: travel,
            locale: config.locale,
            limitedUpto: calendar.limitedUpto,
            minKey: calendar.minKey,
            maxKey: calendar.maxKey,
            state: dayState,
            onPick: repaint,
            labels: {
                // Off on the website, at the client's request.
                tooSoon: '',
                tooLate: labels.tooLate,
                previousMonth: labels.previousMonth,
                nextMonth: labels.nextMonth,
            },
            legend: function () {
                var entries = [
                    { kind: 'available', text: labels.legendFree },
                    { kind: 'limited', text: fill(labels.legendLimited, { count: calendar.limitedUpto }) },
                    { kind: 'full', text: labels.legendNone },
                ];

                /*
                 * The grey key earns its place only on a route that actually has a
                 * cancellation somewhere in the window. Most do not, and a fourth swatch
                 * explaining a colour the month never shows is noise.
                 */
                if (hasCancellation()) {
                    entries.push({ kind: 'closed', text: labels.notRunning });
                }

                return entries;
            },
        });

        // Choosing a departure re-colours the whole month, so this is a full repaint
        // rather than just re-marking the cards.
        runs.forEach(function (run) {
            run.addEventListener('change', repaint);
        });

        if (party) {
            party.addEventListener('input', function () {
                estimate();
                gateContinue();
            });
        }


        /*
         * `required` comes off the date field with it: a required control inside a hidden
         * container blocks the submit with "an invalid form control is not focusable", an
         * error only the console sees. The guard below replaces it.
         */
        travel.removeAttribute('required');

        travel.form.addEventListener('submit', function (event) {
            // Continue is already closed while any of this is true; this is the guard for
            // a submit that arrives another way — the Enter key, or a button re-enabled
            // from the console.
            var problem = whatIsMissing();

            var box = document.getElementById('dates-error');

            box.hidden = !problem;
            box.innerHTML = '';

            if (problem) {
                event.preventDefault();
                box.appendChild(document.createElement('li')).textContent = problem;
                mount.scrollIntoView({ block: 'center', behavior: 'smooth' });

                return;
            }

        });

        repaint();

        return { repaint: repaint };
    };
}());

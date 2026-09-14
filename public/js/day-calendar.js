/**
 * A month calendar that picks ONE day and says what is left on each of them.
 *
 * The client's reference is the customer app's "Select departure date" screen: a
 * month grid where every day carries its own seat count and colour, so the person
 * choosing can see the full month at once instead of discovering a date is sold out
 * after typing it. The offline booking forms now ask the same question the same way —
 * an operator on the phone reads out the month, rather than guessing and being refused.
 *
 *   window.initDayCalendar(mount, {
 *       input:  'travelDate',                 // the field the chosen day is written to
 *       locale: 'id',                         // month and weekday names
 *       state:  (dayKey) => ({ count: 7, label: '7 seats left' }) | { kind: 'closed' } | null,
 *       onPick: (dayKey) => {},
 *       legend: [{ kind: 'available', text: 'Seats available' }, ...],
 *       labels: { tooSoon: 'Too soon' },     // `past` is supported and deliberately unused
 *       minKey: '2026-08-26',                 // earliest day that may be chosen
 *       keep:   '2026-08-25',                 // …except this one, already on the record
 *   });
 *
 * Returns { refresh(), value(), setValue(key), destroy() }.
 *
 * ── WHERE THIS FILE CAME FROM ───────────────────────────────────────────────
 *
 * The admin panel's `public/js/day-calendar.js`, copied rather than rewritten so the
 * customer sees the month the operator sees — the same grid, the same colours, the same
 * legend. Two differences, both because this side is a public website:
 *
 *   - `maxKey`, the far end of the booking window. The panel plans no further ahead than
 *     that and a customer offered a date past it would be choosing from nothing.
 *   - the month arrows are inline SVG. The panel has Font Awesome loaded; this site has
 *     no icon font and is not adding one for two chevrons.
 *   - `marked`, a function returning the days that belong to the trip. The panel picks a
 *     start and a return on two separate months, so its field's own value is the whole
 *     answer; the website picks both on ONE month, as the mobile app does, and the days
 *     in between have to be drawn as part of the selection or a three-day booking shows
 *     two highlighted days with a gap.
 *
 * A fix to either copy belongs in both.
 *
 * ── THE VALUE STILL LIVES ON THE INPUT ──────────────────────────────────────
 *
 * The `<input>` named by `input` keeps holding `YYYY-MM-DD` and stays the only place
 * the value is read from, exactly as when it was a native date field. Everything that
 * already does `el('travelDate').value` — validation, the price, the seat guard, the
 * save — is untouched, and a `change` event is dispatched on every pick so listeners
 * fire as they always did. The calendar is a way of TYPING into that field, not a
 * second source of truth for the date.
 *
 * ── WHAT `state` MAY RETURN, AND WHAT EACH MEANS ────────────────────────────
 *
 *   null                      nothing is known yet (no route chosen, say) — a plain day
 *   { kind: 'closed', … }     the day cannot be sold: nothing runs, or it is cancelled
 *   { count: n, label: … }    n places left; the colour follows from n
 *
 * Days before today are drawn `past` and refuse the click without asking `state` —
 * an offline booking is still being TAKEN, and a date that has been and gone is a
 * typo whatever the counts say.
 */
(function () {
    'use strict';

    /* Below this many places left the day is amber rather than green. From the caller
       so the panel's config is the only place the number is written down. */
    var LIMITED_UPTO = 3;

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
    }

    /**
     * A local-time 'YYYY-MM-DD'.
     *
     * Local on purpose, and it is the whole reason `toISOString()` is not used here:
     * that converts to UTC first, so in Indonesia (UTC+7) every date before 07:00
     * would be written as the day before. The seat pools are keyed by this string.
     */
    function dayKey(date) {
        return date.getFullYear()
            + '-' + String(date.getMonth() + 1).padStart(2, '0')
            + '-' + String(date.getDate()).padStart(2, '0');
    }

    /** Noon, so a day never slides across a DST boundary while being counted. */
    function fromKey(key) {
        return new Date(key + 'T12:00:00');
    }

    /**
     * Monday-first weekday initials, in the panel's language.
     *
     * Built from Intl rather than from twenty-one translated strings across the three
     * lang files: month and weekday names are exactly what Intl exists to know, and a
     * hand-written list is a list somebody has to maintain in Arabic.
     */
    function weekdayNames(locale) {
        var names = [];
        // 2024-01-01 was a Monday. Any Monday would do; a fixed one keeps this pure.
        var cursor = new Date(2024, 0, 1, 12, 0, 0);
        // 'narrow' — single letters, as the customer app's screen has them. 'short'
        // renders MON/TUE, which is three times the width for a column that is only
        // ever read as a position in the week.
        var format = new Intl.DateTimeFormat(locale, { weekday: 'narrow' });

        for (var i = 0; i < 7; i += 1) {
            names.push(format.format(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }

        return names;
    }

    function monthName(locale, date) {
        return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(date);
    }

    /**
     * Which colour a day wears, from what the caller said about it.
     *
     * One place, because the calendar and its own legend must agree — a legend that
     * says "1-3 is limited" over a grid that ambers 4 is worse than no legend.
     */
    function kindFor(state, limitedUpto) {
        if (!state) {
            return 'blank';
        }

        if (state.kind) {
            return state.kind;
        }

        var count = parseInt(state.count, 10);

        if (!Number.isFinite(count)) {
            return 'blank';
        }

        if (count <= 0) {
            return 'full';
        }

        return count <= limitedUpto ? 'limited' : 'available';
    }

    window.initDayCalendar = function (mount, options) {
        if (!mount) {
            return null;
        }

        var config = options || {};
        var input = typeof config.input === 'string'
            ? document.getElementById(config.input)
            : config.input;

        if (!input) {
            return null;
        }

        var locale = config.locale || 'en';
        var limitedUpto = Number.isFinite(config.limitedUpto) ? config.limitedUpto : LIMITED_UPTO;
        var labels = config.labels || {};
        var todayKey = dayKey(new Date());

        /*
         * The earliest day that may be chosen. The booking forms pass tomorrow — the
         * client's rule that no service is booked for the same day — and a day below
         * it is drawn like a past one: greyed, labelled, and refusing the click.
         *
         * `keep` is the exception, and it is what lets an EXISTING booking be edited:
         * the day it was already made for stays selectable even when the floor has
         * risen past it, or a booking taken for today could not be corrected at all.
         */
        var minKey = config.minKey || '';
        var keepKey = config.keep || '';

        /*
         * The far end of the booking window. Past it the operator has planned nothing —
         * no shuttle run is materialised and no vehicle is committed either way — so the
         * day is greyed for the same reason a past one is: it is not a date this business
         * can be asked about, whatever the fleet happens to be doing.
         */
        var maxKey = config.maxKey || '';

        // The month on screen. Follows the chosen day when there is one, so reopening a
        // booking made in March does not open the calendar on this month with the
        // selection nowhere in sight.
        var cursor = input.value ? fromKey(input.value) : new Date();

        cursor.setDate(1);

        mount.classList.add('daycal');

        /*
         * A chevron, drawn rather than lettered. `currentColor` so it inherits the
         * button's colour in both themes, and the stylesheet flips it in Arabic — the
         * arrow that means "back" points the other way in a right-to-left month.
         */
        function chevron(direction) {
            var path = direction < 0 ? 'M15 4 L7 12 L15 20' : 'M9 4 L17 12 L9 20';

            return '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"'
                + ' fill="none" stroke="currentColor" stroke-width="2"'
                + ' stroke-linecap="round" stroke-linejoin="round"><path d="' + path + '"/></svg>';
        }

        function head() {
            return '<div class="daycal-head">'
                + '<button type="button" class="daycal-nav" data-daycal-step="-1"'
                + ' aria-label="' + esc(labels.previousMonth || 'Previous month') + '">'
                + chevron(-1) + '</button>'
                + '<span class="daycal-month">' + esc(monthName(locale, cursor)) + '</span>'
                + '<button type="button" class="daycal-nav" data-daycal-step="1"'
                + ' aria-label="' + esc(labels.nextMonth || 'Next month') + '">'
                + chevron(1) + '</button>'
                + '</div>';
        }

        function weekdays() {
            return '<div class="daycal-week">'
                + weekdayNames(locale).map(function (name) {
                    return '<span class="daycal-dow">' + esc(name) + '</span>';
                }).join('')
                + '</div>';
        }

        /**
         * One day cell.
         *
         * The number is always drawn; the note under it only when there is something to
         * say. A cell that reads just "12" is a day nothing is known about yet, which is
         * what the operator sees before a route is chosen — and it is deliberately not
         * green, because "we have not looked" must not read as "there is room".
         */
        function cell(key) {
            var date = fromKey(key);
            var gone = key < todayKey;
            var tooSoon = !gone && minKey && key < minKey && key !== keepKey;
            var tooLate = !gone && maxKey && key > maxKey && key !== keepKey;
            var closed = gone || tooSoon || tooLate;
            var state = closed ? null : (config.state ? config.state(key) : null);
            var kind = closed ? 'past' : kindFor(state, limitedUpto);
            /*
             * A day that has gone gets NO note — the client's call, 2026-08-25, on
             * seeing a month with twenty cells all reading "Past". The grey and the
             * refused click already say it, and the word only crowded the number.
             *
             * `tooSoon` still speaks, because that day is TODAY: the grey says it
             * cannot be picked but not why, and "not today" is a rule rather than the
             * calendar simply having moved on.
             */
            var note = gone
                ? (labels.past || '')
                : (tooSoon
                    ? (labels.tooSoon || '')
                    : (tooLate ? (labels.tooLate || '') : ((state && state.label) || '')));
            var classes = ['daycal-cell', 'is-' + kind];

            if (key === input.value) {
                classes.push('is-selected');
            } else if (markedDays.indexOf(key) !== -1) {
                // Part of the trip, but not the day the field holds — the middle of a
                // return trip, and its far end.
                classes.push('is-marked');
            }

            if (key === todayKey) {
                classes.push('is-today');
            }

            var open = kind !== 'past' && kind !== 'full' && kind !== 'closed';

            return '<button type="button" class="' + classes.join(' ') + '"'
                + ' data-daycal-day="' + esc(key) + '"'
                + (open ? '' : ' disabled')
                + '><span class="daycal-dom">' + date.getDate() + '</span>'
                + (note ? '<span class="daycal-note">' + esc(note) + '</span>' : '')
                + '</button>';
        }

        function grid() {
            var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1, 12, 0, 0);
            var days = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0, 12, 0, 0).getDate();
            // getDay() is Sunday-first; the grid is Monday-first, as the app's is.
            var lead = (first.getDay() + 6) % 7;
            var cells = [];
            var index;

            for (index = 0; index < lead; index += 1) {
                cells.push('<span class="daycal-cell is-empty"></span>');
            }

            for (index = 1; index <= days; index += 1) {
                cells.push(cell(dayKey(new Date(cursor.getFullYear(), cursor.getMonth(), index, 12, 0, 0))));
            }

            return '<div class="daycal-grid">' + cells.join('') + '</div>';
        }

        /*
         * The key under the grid. Accepts a FUNCTION as well as a list, because the
         * booking form's one calendar serves two services: the same cells count seats
         * on a shuttle and vehicles on a charter, and a legend fixed at build time
         * would still be naming seats after the operator switched to Rental + Driver.
         */
        function legend() {
            var entries = typeof config.legend === 'function' ? config.legend() : config.legend;

            if (!Array.isArray(entries) || !entries.length) {
                return '';
            }

            return '<div class="daycal-legend">'
                + entries.map(function (entry) {
                    return '<span class="daycal-key"><span class="daycal-swatch is-'
                        + esc(entry.kind) + '"></span>' + esc(entry.text) + '</span>';
                }).join('')
                + '</div>';
        }

        /* The days belonging to the trip, worked out ONCE per paint rather than per
           cell: a month asking the caller thirty-one times for the same list is
           thirty-one date ranges built to answer one question. */
        var markedDays = [];

        function draw() {
            markedDays = typeof config.marked === 'function' ? (config.marked() || []) : [];

            mount.innerHTML = head() + weekdays() + grid() + legend();
        }

        /**
         * Writes the day to the input and tells the page.
         *
         * `change` is dispatched by hand because setting `.value` from script never
         * fires one. Every listener this screen already had was bound to the native
         * date field's change event, and without this line all of them — the price, the
         * vehicle list, the seat count — would quietly stop firing.
         */
        function pick(key) {
            input.value = key;
            input.dispatchEvent(new Event('change', { bubbles: true }));

            if (config.onPick) {
                config.onPick(key);
            }

            draw();
        }

        function onClick(event) {
            var step = event.target.closest('[data-daycal-step]');

            if (step) {
                cursor.setMonth(cursor.getMonth() + parseInt(step.getAttribute('data-daycal-step'), 10));
                draw();

                return;
            }

            var day = event.target.closest('[data-daycal-day]');

            if (day && !day.disabled) {
                pick(day.getAttribute('data-daycal-day'));
            }
        }

        mount.addEventListener('click', onClick);
        draw();

        return {
            /* Repaint against today's counts — called whenever the pool changes, which
               is every time the route, the direction or the vehicle type moves. */
            refresh: draw,
            value: function () {
                return input.value;
            },
            setValue: function (key) {
                input.value = key || '';

                if (key) {
                    cursor = fromKey(key);
                    cursor.setDate(1);
                }

                draw();
            },
            destroy: function () {
                mount.removeEventListener('click', onClick);
                mount.innerHTML = '';
            },
        };
    };
}());

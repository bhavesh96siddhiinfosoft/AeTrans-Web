/**
 * Field-level validation, in the site's own voice.
 *
 *   window.initFormValidation(form, {
 *       required: 'This field is required.',   // or per field: data-required-message
 *       email:    'Please enter a valid email address.',
 *       number:   'Please enter a number between :min and :max.',
 *       tel:      'Please enter a valid phone number.',
 *       short:    'Use at least :min characters.',
 *       match:    'These do not match.',        // or per field: data-match-message
 *   });
 *
 * ── WHY NOT JUST LET THE BROWSER DO IT ──────────────────────────────────────
 *
 * The browser already refuses to submit a form with an empty `required` field, and says
 * so in a bubble: white, system-font, pointing at one field, gone the moment anything
 * else is clicked, and in whatever language the BROWSER is set to rather than the one
 * the customer chose on this site. It also shows one field at a time, so a customer with
 * three empty fields discovers them one submit at a time.
 *
 * So the form is marked `novalidate` and this takes over: every problem at once, in the
 * page's own language, in the same red the server-side errors already use, and each one
 * sitting under the field it belongs to. The native constraints stay on the elements —
 * `required`, `type`, `min`, `max` — and are still what this reads, so nothing has to be
 * described twice.
 *
 * ── WHAT IT DOES NOT REPLACE ────────────────────────────────────────────────
 *
 * The server validation. Every rule here exists there too, and there it is the one that
 * counts — this is the fast answer, not the authoritative one. A field this misses is a
 * round trip, not a hole.
 *
 * Hidden fields are skipped unless they carry `data-required`: the wizard hides the
 * native date inputs once the calendar is mounted over them, and a `required` field in a
 * hidden container blocks the submit with "an invalid form control is not focusable" —
 * an error only the console sees, leaving a customer pressing Continue on a form that
 * does nothing.
 */
(function () {
    'use strict';

    function fill(template, values) {
        return Object.keys(values || {}).reduce(function (text, key) {
            return text.split(':' + key).join(values[key]);
        }, template || '');
    }

    /**
     * Where a message for this field goes.
     *
     * A stop on the itinerary has its own row, and its message belongs under THAT row
     * rather than under the group — with three stops, one shared slot would put every
     * message under the last one.
     */
    function slotFor(field) {
        return field.closest('.stop-row') || field.closest('.form-field') || field.parentElement;
    }

    function clear(form) {
        form.querySelectorAll('[data-field-error]').forEach(function (node) {
            node.remove();
        });

        form.querySelectorAll('.is-invalid').forEach(function (node) {
            node.classList.remove('is-invalid');
        });
    }

    function complain(field, message) {
        var slot = slotFor(field);
        var box = document.createElement('ul');
        var line = document.createElement('li');

        box.className = 'form-error';
        box.setAttribute('data-field-error', '');
        line.textContent = message;
        box.appendChild(line);

        /*
         * Directly under the control, ABOVE any help text.
         *
         * Appended to the group it would land after the help, which reads as a footnote
         * to advice rather than a fault in the field — and on a group with two lines of
         * help it ends up nowhere near the input it is about.
         */
        var help = slot.querySelector('.form-help');

        if (help) {
            slot.insertBefore(box, help);
        } else {
            slot.appendChild(box);
        }

        var group = field.closest('.form-field');

        if (group) {
            group.classList.add('is-invalid');
        }
    }

    /**
     * The first thing wrong with one field, or nothing.
     *
     * First, not all: a field that is both empty and not an email address is empty, and
     * telling someone their blank box is not a valid email address is noise.
     */
    function problem(field, messages) {
        var value = (field.value || '').trim();

        if (field.required || field.hasAttribute('data-required')) {
            if (!value || (field.type === 'checkbox' && !field.checked)) {
                /*
                 * The field's own wording wins. "Enter the pickup address" tells someone
                 * what to do; "This field is required" tells them what they already know,
                 * and three of them down one form tells them nothing at all. The generic
                 * line stays as the fallback for fields nobody has written one for.
                 */
                return field.getAttribute('data-required-message') || messages.required;
            }
        }

        if (!value) {
            return '';
        }

        if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
            return messages.email;
        }

        /*
         * Digits, spaces and the punctuation Indonesian numbers are written with —
         * `+62 811-2233-4455` and `(0351) 749123` are both ordinary. Deliberately loose:
         * this catches a typed word, not a number that is real but formatted unusually,
         * and refusing a customer's own phone number is worse than accepting an odd one.
         */
        if (field.type === 'tel' && !/^[0-9+()\-.\s]{6,}$/.test(value)) {
            return messages.tel;
        }

        /*
         * Length, from the element's own `minlength`.
         *
         * Read off the attribute rather than configured here, for the same reason
         * `required` and `type` are: the rule belongs on the field, and the server has
         * to agree with it. A password box that says 8 while Laravel says 8 is one fact
         * written once; a number in this file is a second copy waiting to drift.
         *
         * The browser enforces `minlength` on its own, but only on submit and only in a
         * bubble — and the form is `novalidate`, which is what turns that off.
         */
        var minLength = parseInt(field.getAttribute('minlength'), 10);

        if (Number.isFinite(minLength) && value.length < minLength) {
            return fill(field.getAttribute('data-short-message') || messages.short, { min: minLength });
        }

        /*
         * "Must be the same as that other field" — a password confirmation, and nothing
         * else so far. `data-match` holds the id of the field to compare against.
         *
         * The comparison is on the RAW values, not trimmed ones: a password with a
         * trailing space is a password, and telling someone two identical-looking boxes
         * match when the server will disagree is the worst possible answer here.
         */
        var matchId = field.getAttribute('data-match');

        if (matchId) {
            var other = document.getElementById(matchId);

            if (other && other.value !== field.value) {
                return field.getAttribute('data-match-message') || messages.match;
            }
        }

        if (field.type === 'number') {
            var number = parseFloat(value);
            var min = field.min === '' ? -Infinity : parseFloat(field.min);
            var max = field.max === '' ? Infinity : parseFloat(field.max);

            if (!Number.isFinite(number) || number < min || number > max) {
                /*
                 * The field's own wording wins, as it does for `required`. "Please enter a
                 * number between 1 and 2" is true and useless: it describes the bound
                 * without saying what the bound IS — that two seats are left on this bus.
                 *
                 * Having the field carry the sentence also keeps it to ONE message. The
                 * alternative, a second check beside this one, is how the same problem
                 * came to be reported twice in two different places.
                 */
                return field.getAttribute('data-range-message') || fill(messages.number, {
                    min: field.min === '' ? '' : field.min,
                    max: field.max === '' ? '' : field.max,
                });
            }
        }

        return '';
    }

    /** Fields worth checking: visible, enabled, and not the CSRF token. */
    function fieldsOf(form) {
        return Array.prototype.filter.call(
            form.querySelectorAll('input, select, textarea'),
            function (field) {
                if (field.disabled || field.name === '_token') {
                    return false;
                }

                /*
                 * A field that explicitly asks to be checked is checked wherever it is.
                 *
                 * Two cases, and both are fields a customer answers through something
                 * else: the measured distance, which is the value behind a read-only box,
                 * and a `<select>` with a searchable dropdown mounted over it. The select
                 * is hidden but still the field that posts, and without this it would be
                 * skipped — a required control with its validation silently switched off,
                 * which is worse than no validation because the form looks guarded.
                 */
                if (field.hasAttribute('data-required')) {
                    return true;
                }

                if (field.type === 'hidden') {
                    return false;
                }

                return !field.closest('[hidden]');
            },
        );
    }

    window.initFormValidation = function (form, messages) {
        if (!form) {
            return null;
        }

        // The browser's own bubbles are off from here; everything below replaces them.
        form.setAttribute('novalidate', 'novalidate');

        function check() {
            var first = null;

            clear(form);

            fieldsOf(form).forEach(function (field) {
                var wrong = problem(field, messages);

                if (wrong) {
                    complain(field, wrong);
                    first = first || field;
                }
            });

            return first;
        }

        /**
         * Shows or clears ONE field's message, without touching the rest of the form.
         *
         * For a screen that closes its submit button while something is wrong: the
         * customer can no longer press Continue, so a check that only runs on submit never
         * runs at all, and the field itself stays silent while a reason sits by the button.
         * This is how the field gets to speak for itself.
         *
         * Deliberately not a second copy of the rule — it asks `problem()` the same
         * question `check()` does, so the two cannot come to different answers.
         */
        function checkField(field) {
            var slot = field && slotFor(field);

            if (!slot) {
                return '';
            }

            slot.querySelectorAll('[data-field-error]').forEach(function (node) {
                node.remove();
            });

            var group = field.closest('.form-field');
            var wrong = fieldsOf(form).indexOf(field) === -1 ? '' : problem(field, messages);

            if (wrong) {
                complain(field, wrong);
            } else if (group && !group.querySelector('[data-field-error]')) {
                group.classList.remove('is-invalid');
            }

            return wrong;
        }

        form.addEventListener('submit', function (event) {
            var first = check();

            if (!first) {
                return;
            }

            event.preventDefault();

            // Stops any other submit handler on this form from running — the wizard's
            // own date guard, for one, which would scroll somewhere else.
            event.stopImmediatePropagation();

            var group = first.closest('.form-field') || first;

            group.scrollIntoView({ block: 'center', behavior: 'smooth' });

            /*
             * A field nobody can see cannot take focus, and asking it to throws in some
             * browsers. Where a control is drawn over it — a searchable dropdown, say —
             * that control is focused instead, so the customer's cursor lands on the
             * thing they actually answer with. The scroll above is the fallback.
             */
            var visible = first.type === 'hidden' || first.closest('[hidden]') || first.hidden
                ? group.querySelector('.ss-toggle, .form-input:not([hidden])')
                : first;

            if (visible && typeof visible.focus === 'function') {
                visible.focus({ preventScroll: true });
            }
        // Capture, so this runs BEFORE the handlers the page added earlier.
        }, true);

        /*
         * Messages clear as the customer fixes things, rather than surviving until the
         * next submit. Only for the field being edited: re-checking the whole form on
         * every keystroke would light up fields nobody has reached yet.
         */
        form.addEventListener('input', function (event) {
            var field = event.target;
            var slot = field.closest ? slotFor(field) : null;

            if (!slot || problem(field, messages)) {
                return;
            }

            slot.querySelectorAll('[data-field-error]').forEach(function (node) {
                node.remove();
            });

            var group = field.closest('.form-field');

            if (group && !group.querySelector('[data-field-error]')) {
                group.classList.remove('is-invalid');
            }
        });

        var api = { check: check, checkField: checkField, clear: function () { clear(form); } };

        /*
         * Hung on the form so a page's own script can reach it without the two having to
         * be wired together in a particular order — the validation partial is included
         * last, after the screens that need it.
         */
        form.validation = api;

        return api;
    };
}());

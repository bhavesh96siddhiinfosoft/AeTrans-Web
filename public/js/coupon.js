/*
 * Applying and removing a discount code without reloading the page.
 *
 * ── PROGRESSIVE ENHANCEMENT, NOT A REWRITE ──────────────────────────────────
 *
 * The form works on its own: it posts, the server redirects, and the page comes back
 * with the price changed. That is what a browser with no JavaScript gets, and it is the
 * same discount. This file only intercepts the submit and swaps the answer in, which is
 * why there is no separate "apply" endpoint and no second copy of the rules.
 *
 * ── THE SERVER SENDS THE MARKUP, NOT THE NUMBERS ────────────────────────────
 *
 * The response carries the coupon block re-rendered by the same Blade partial the page
 * was built from. So this script never formats a price, never decides what the button
 * should say, and cannot drift away from the no-JavaScript path — it replaces one
 * element with another and stops.
 *
 * Formatting money here would mean teaching JavaScript about the admin's currency
 * document, the separators and the symbol position, and then keeping that in step with
 * `Currency::format()` for ever.
 *
 * ── DELEGATED, BECAUSE THE FORM IS REPLACED ─────────────────────────────────
 *
 * The listener is on the document rather than the form: the form it is watching is
 * destroyed and rebuilt on every apply, and a listener bound to the old one would work
 * exactly once.
 */
(function () {
    'use strict';

    var BLOCK = 'coupon-block';

    function block() {
        return document.getElementById(BLOCK);
    }

    /**
     * The form's fields, plus whichever button was pressed.
     *
     * `FormData` does not include a submit button's own name and value, and the Remove
     * button's `remove=1` is the only thing distinguishing it from Apply with an empty
     * box. Without this the server cannot tell them apart.
     */
    function bodyFor(form, submitter) {
        var data = new FormData(form);

        if (submitter && submitter.name) {
            data.set(submitter.name, submitter.value);
        }

        return new URLSearchParams(data);
    }

    /**
     * Says the box is empty, without asking the server.
     *
     * The server refuses it too, and that is the check that counts — this only saves a
     * round trip to be told to type something. The sentence comes off the input rather
     * than out of this file, because the site is read in three languages.
     */
    function complainEmpty(form) {
        var input = form.querySelector('input[name="code"]');
        var message = input && input.getAttribute('data-empty-message');

        if (!input || !message) {
            return false;
        }

        form.querySelectorAll('.form-error').forEach(function (old) {
            old.remove();
        });

        var list = document.createElement('ul');
        list.className = 'form-error';
        list.innerHTML = '<li></li>';
        list.firstChild.textContent = message;
        form.appendChild(list);

        input.focus();

        return true;
    }

    function busy(form, on) {
        var button = form.querySelector('[data-coupon-submit]');

        if (button) {
            button.disabled = on;
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-coupon-form]');

        if (!form || !window.fetch) {
            return;
        }

        event.preventDefault();

        /*
         * Applying nothing is a mistake worth naming here rather than after a round
         * trip. Removing with an empty box is not — that IS the remove action, and the
         * button pressed says which this is.
         */
        var removing = event.submitter && event.submitter.name === 'remove';
        var typed = (form.querySelector('input[name="code"]') || {}).value || '';

        if (!removing && typed.trim() === '' && complainEmpty(form)) {
            return;
        }

        busy(form, true);

        fetch(form.action, {
            method: 'POST',
            body: bodyFor(form, event.submitter),
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                // What tells Laravel to answer with the block rather than a redirect.
                Accept: 'application/json',
            },
            // The draft lives in the session, so the cookie has to travel.
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                return response.json();
            })
            .then(function (answer) {
                var current = block();

                if (!current || !answer.html) {
                    throw new Error('no block to swap');
                }

                current.outerHTML = answer.html;

                /*
                 * Focus back into the box after a refusal, so a mistyped code can be
                 * corrected without hunting for the field again. Not on success: the
                 * customer is finished with it and the next thing they want is the
                 * Confirm button.
                 */
                if (!answer.ok) {
                    var input = block() && block().querySelector('input[name="code"]');

                    if (input) {
                        input.focus();
                        input.select();
                    }
                }
            })
            .catch(function () {
                /*
                 * A dropped connection, or an answer this cannot use. Falling back to a
                 * plain submit means the customer still gets their discount — the page
                 * reloads, which is what would have happened anyway without this file.
                 */
                busy(form, false);
                form.submit();
            });
    });
}());

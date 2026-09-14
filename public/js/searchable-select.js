/**
 * Searchable dropdown — Select2's behaviour, without jQuery or Select2 itself.
 *
 * Copied from the admin panel's `public/js/searchable-select.js`, with two changes,
 * both because this side is a public website with no shared script bundle:
 *
 *   - `placeDropdown` and `trackDropdownScroll` live in `dropdown-position.js`, which
 *     must be loaded first. The panel keeps them in its `app.js`; this site has no such
 *     bundle, so they have a small file of their own — shared with the country picker.
 *   - the chevron is an inline SVG. The panel has Font Awesome loaded; this site has no
 *     icon font.
 *   - an option may be `disabled`. The vehicle list shows every van of the chosen type,
 *     including the ones already booked on those dates — shown and labelled rather than
 *     hidden, because a customer who can see their van is taken can move the dates, and
 *     one who never sees it concludes the business does not own it.
 *
 * A fix to either copy belongs in both.
 *
 *   initSearchableSelect(mount, {
 *       inputId:  'airportId',            // id given to the hidden input
 *       options:  [{ id, name, note }],   // note is an optional right-aligned hint
 *       value:    'abc',
 *       onSelect: (id) => {},
 *   });
 *
 * Returns { set(id), value(), setOptions(list) }.
 *
 * The panel is positioned with `position: fixed` when opened (see place()), so it
 * is never clipped by a scrolling or overflow-hidden ancestor.
 *
 * The panel has no jQuery outside the CMS editor, and Select2 needs it plus its own
 * CSS. This is the same interaction — click to open, type to filter, click to
 * choose — in a fraction of the weight, and it inherits the panel's own form
 * styling rather than fighting Select2's.
 *
 * `value` is mirrored onto a hidden input inside the mount, so surrounding code can
 * keep reading element.value exactly as it would from a <select>.
 */
(function () {
    'use strict';

    function esc(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Closes every open panel — only one dropdown should be open at a time. */
    function closeAll() {
        document.querySelectorAll('[data-ss-panel]').forEach(function (panel) {
            panel.classList.add('hidden');
        });
    }

    function place(toggle, panel) {
        window.placeDropdown(toggle, panel);
    }

    window.trackDropdownScroll('[data-ss-panel]');

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-searchable-select]')) {
            closeAll();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll();
        }
    });

    window.initSearchableSelect = function (mount, options) {
        var settings = options || {};
        var list = settings.options || [];
        var value = settings.value || '';

        mount.setAttribute('data-searchable-select', '');

        mount.innerHTML =
            '<input type="hidden" data-ss-value' + (settings.inputId ? ' id="' + esc(settings.inputId) + '"' : '') + '>'
            + '<button type="button" data-ss-toggle ' + (settings.disabled ? 'disabled' : '')
            + ' class="ss-toggle">'
            + '<span data-ss-label class="ss-label"></span>'
            + '<svg class="ss-chevron" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"'
            + ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            + ' stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>'
            + '</button>'
            + '<div data-ss-panel class="ss-panel hidden">'
            + '<div class="ss-search">'
            + '<input type="search" data-ss-search autocomplete="off" placeholder="' + esc(settings.searchLabel || 'Search') + '"'
            + ' class="form-input">'
            + '</div>'
            + '<ul data-ss-list class="ss-list"></ul>'
            + '</div>';

        var hidden = mount.querySelector('[data-ss-value]');
        var toggle = mount.querySelector('[data-ss-toggle]');
        var label = mount.querySelector('[data-ss-label]');
        var panel = mount.querySelector('[data-ss-panel]');
        var search = mount.querySelector('[data-ss-search]');
        var items = mount.querySelector('[data-ss-list]');

        function paintLabel() {
            var chosen = list.find(function (option) { return option.id === value; });

            label.textContent = chosen ? chosen.name : (settings.placeholder || '—');
            label.classList.toggle('is-placeholder', !chosen);
            hidden.value = value;
        }

        function paintList() {
            var query = search.value.trim().toLowerCase();
            var shown = query
                ? list.filter(function (option) {
                    return String(option.name).toLowerCase().indexOf(query) !== -1
                        || String(option.note || '').toLowerCase().indexOf(query) !== -1;
                })
                : list;

            items.innerHTML = shown.length
                ? shown.map(function (option) {
                    return '<li><button type="button" data-id="' + esc(option.id) + '"'
                        + (option.disabled ? ' disabled' : '')
                        + ' class="ss-option' + (option.id === value ? ' is-current' : '') + '">'
                        + '<span class="ss-option-name">' + esc(option.name) + '</span>'
                        + (option.note ? '<span class="ss-option-note">' + esc(option.note) + '</span>' : '')
                        + '</button></li>';
                }).join('')
                : '<li class="ss-empty">' + esc(settings.emptyLabel || 'No matches') + '</li>';

            items.querySelectorAll('button[data-id]:not(:disabled)').forEach(function (button) {
                button.addEventListener('click', function () {
                    value = button.dataset.id;
                    paintLabel();
                    panel.classList.add('hidden');
                    search.value = '';

                    // Fired so callers can react the way they would to a <select>.
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));

                    if (settings.onSelect) {
                        settings.onSelect(value);
                    }
                });
            });
        }

        toggle.addEventListener('click', function () {
            var opening = panel.classList.contains('hidden');

            closeAll();

            if (opening) {
                panel.classList.remove('hidden');
                place(toggle, panel);
                paintList();
                search.focus();
            }
        });

        search.addEventListener('input', paintList);

        paintLabel();

        return {
            set: function (next) { value = next || ''; paintLabel(); },
            value: function () { return value; },

            /**
             * Closes the control, optionally saying what it is waiting for.
             *
             * For a dropdown whose choices depend on an earlier one: an ENABLED control
             * that opens onto an empty list reads as a fault, while a disabled one reads
             * as a queue. The panel closed when it is disabled, because a panel left open
             * over a control nobody can use is a panel that cannot be dismissed.
             */
            setDisabled: function (off, waitingFor) {
                toggle.disabled = !!off;

                if (off) {
                    panel.classList.add('hidden');
                }

                if (waitingFor) {
                    settings.placeholder = waitingFor;
                }

                paintLabel();
            },

            /**
             * Swaps the option list, for a dropdown whose choices depend on another
             * field — the free vehicles for a date range, the drop points for an
             * airport.
             *
             * A selection that is no longer in the list is dropped rather than kept:
             * keeping it would leave the control showing a vehicle that has since been
             * taken, and the booking would be saved against it.
             */
            setOptions: function (next) {
                list = next || [];

                // A selection that is gone — or that has become unavailable — is dropped
                // rather than kept, or the control would show a vehicle the form refuses.
                if (!list.some(function (option) { return option.id === value && !option.disabled; })) {
                    value = '';
                }

                paintLabel();

                if (!panel.classList.contains('hidden')) {
                    paintList();
                }
            },
        };
    };
})();

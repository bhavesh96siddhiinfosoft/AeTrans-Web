/**
 * Searchable country picker with flags, and the phone variant that puts the dial
 * code in front of a national number.
 *
 * Copied from the admin panel's `public/js/country-select.js`. Two changes, both because
 * this side is a public website with no shared script bundle:
 *
 *   - `placeDropdown` and `trackDropdownScroll` come from `dropdown-position.js`, which
 *     must be loaded first. The panel keeps them in its `app.js`.
 *   - the chevron is an inline SVG. The panel has Font Awesome loaded; this site has no
 *     icon font.
 *
 * A fix to either copy belongs in both.
 *
 * Both keep their real value in a hidden input so surrounding form code reads
 * element.value as it would from a <select>. The chosen country's name is mirrored
 * onto dataset.name, which the settings screen writes to regionCountry so the code
 * and the display name cannot drift apart.
 *
 * To set a value from outside (e.g. after loading from Firestore) assign the
 * input's value and dispatch 'country-select:set' (or 'phone-input:set') on it —
 * assigning .value alone leaves the button showing the previous country.
 */
(function () {
    'use strict';

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function closeAll() {
        document.querySelectorAll('[data-picker-panel]').forEach(function (panel) {
            panel.classList.add('hidden');
        });
    }

    /*
     * Opens one panel and positions it through the SHARED placer in app.js.
     *
     * `.card` sets `overflow: hidden`, so an absolutely positioned 243-country panel is
     * clipped to a few visible pixels inside a short card — a search box and then
     * nothing. Both pickers share one placer instead of one having it and the other not.
     */
    function openPanel(refs) {
        refs.panel.classList.remove('hidden');
        window.placeDropdown(refs.toggle, refs.panel);
    }

    // Follows the button rather than closing — and a scroll inside the panel's own
    // country list is left alone, which closing on capture used to break.
    window.trackDropdownScroll('[data-picker-panel]');

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-picker]')) {
            closeAll();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll();
        }
    });

    function panelMarkup(searchLabel, emptyLabel) {
        return '<div data-picker-panel class="ss-panel is-anchored hidden">'
            + '<div class="ss-search">'
            + '<input type="search" data-picker-search autocomplete="off" placeholder="' + esc(searchLabel) + '"'
            + ' class="form-input">'
            + '</div>'
            + '<ul data-picker-list class="ss-list"></ul>'
            + '</div>';
    }

    function flagUrl(base, code) {
        return base + '/' + String(code).toLowerCase() + '.png';
    }

    /** Shared list rendering for both pickers. */
    function wireList(refs, options, getValue, onChoose) {
        function paint() {
            var query = refs.search.value.trim().toLowerCase();
            var shown = query
                ? options.countries.filter(function (c) {
                    return c.countryName.toLowerCase().indexOf(query) !== -1
                        || c.code.toLowerCase().indexOf(query) !== -1
                        || String(c.phoneCode).indexOf(query) !== -1;
                })
                : options.countries;

            refs.list.innerHTML = shown.length
                ? shown.map(function (c) {
                    var label = options.showDialCode === false
                        ? c.countryName
                        : c.countryName + ' (+' + c.phoneCode + ')';

                    return '<li><button type="button" data-code="' + esc(c.code) + '"'
                        + ' class="ss-option' + (c.code === getValue() ? ' is-current' : '') + '">'
                        + '<img src="' + esc(flagUrl(options.flagBase, c.code)) + '" alt="' + esc(c.code) + '"'
                        + ' class="ss-flag">'
                        + '<span class="ss-option-name">' + esc(label) + '</span>'
                        + '</button></li>';
                }).join('')
                : '<li class="ss-empty">' + esc(options.emptyLabel) + '</li>';

            refs.list.querySelectorAll('button[data-code]').forEach(function (item) {
                item.addEventListener('click', function () {
                    onChoose(item.dataset.code);
                    refs.panel.classList.add('hidden');
                    refs.search.value = '';
                });
            });
        }

        refs.search.addEventListener('input', paint);

        return paint;
    }

    window.initCountrySelect = function (mount, options) {
        var input = document.getElementById(options.inputId);

        mount.setAttribute('data-picker', '');
        mount.classList.add('picker-wrap');
        mount.innerHTML =
            '<button type="button" data-picker-toggle class="ss-toggle">'
            + '<span data-picker-label class="ss-label row gap-sm"></span>'
            + '<span data-picker-clear class="hidden ss-clear" role="button" tabindex="0">&times;</span>'
            + '<svg class="ss-chevron" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"'
            + ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            + ' stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>'
            + '</button>'
            + panelMarkup(options.searchLabel, options.emptyLabel);

        var refs = {
            toggle: mount.querySelector('[data-picker-toggle]'),
            label: mount.querySelector('[data-picker-label]'),
            clear: mount.querySelector('[data-picker-clear]'),
            panel: mount.querySelector('[data-picker-panel]'),
            search: mount.querySelector('[data-picker-search]'),
            list: mount.querySelector('[data-picker-list]'),
        };

        var value = input.value || '';

        function selected() {
            return options.countries.find(function (c) { return c.code === value; }) || null;
        }

        function sync() {
            var chosen = selected();

            input.value = value;
            input.dataset.name = chosen ? chosen.countryName : '';

            refs.label.innerHTML = chosen
                ? '<img src="' + esc(flagUrl(options.flagBase, chosen.code)) + '" alt="" class="ss-flag">'
                    + '<span class="ss-option-name">' + esc(options.showDialCode === false
                        ? chosen.countryName
                        : chosen.countryName + ' (+' + chosen.phoneCode + ')') + '</span>'
                : '<span class="text-muted">' + esc(options.placeholder || '—') + '</span>';

            refs.clear.classList.toggle('hidden', !chosen);
        }

        var paint = wireList(refs, options, function () { return value; }, function (code) {
            value = code;
            sync();
        });

        refs.toggle.addEventListener('click', function () {
            var opening = refs.panel.classList.contains('hidden');
            closeAll();

            if (opening) {
                // paint() BEFORE placing: the panel has to hold its rows before its
                // height can be measured, or it is positioned as an empty box.
                paint();
                openPanel(refs);
                refs.search.focus();
            }
        });

        refs.clear.addEventListener('click', function (event) {
            event.stopPropagation();
            value = '';
            sync();
        });

        input.addEventListener('country-select:set', function (event) {
            value = event.detail || '';
            sync();
        });

        sync();
    };

    window.initPhoneInput = function (mount, options) {
        var input = document.getElementById(options.inputId);

        // Which country to show when several share a dial code. Without this the
        // first match alphabetically wins and +44 renders as Guernsey, not the UK.
        var preferred = { 1: 'US', 7: 'RU', 44: 'GB', 61: 'AU', 212: 'MA', 262: 'RE', 590: 'GP', 596: 'MQ', 599: 'CW' };

        mount.setAttribute('data-picker', '');
        mount.classList.add('picker-wrap');
        mount.innerHTML =
            '<div class="phone-field">'
            + '<button type="button" data-picker-toggle class="dial">'
            + '<span data-picker-flag class="row"></span><span data-picker-dial>—</span>'
            + '<svg class="ss-chevron" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"'
            + ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            + ' stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>'
            + '</button>'
            + '<input type="text" inputmode="numeric" autocomplete="off" data-picker-national'
            + ' class="form-input">'
            + '</div>'
            + panelMarkup(options.searchLabel, options.emptyLabel);

        var refs = {
            toggle: mount.querySelector('[data-picker-toggle]'),
            flag: mount.querySelector('[data-picker-flag]'),
            dial: mount.querySelector('[data-picker-dial]'),
            national: mount.querySelector('[data-picker-national]'),
            panel: mount.querySelector('[data-picker-panel]'),
            search: mount.querySelector('[data-picker-search]'),
            list: mount.querySelector('[data-picker-list]'),
        };

        var value = '';
        var national = '';

        function selected() {
            return options.countries.find(function (c) { return c.code === value; }) || null;
        }

        function dialCode() {
            var chosen = selected();

            return chosen ? String(chosen.phoneCode) : '';
        }

        function sync() {
            var chosen = selected();

            refs.flag.innerHTML = chosen
                ? '<img src="' + esc(flagUrl(options.flagBase, chosen.code)) + '" alt="" class="ss-flag">'
                : '';
            refs.dial.textContent = dialCode() ? '+' + dialCode() : '—';
            refs.national.value = national;

            // An empty number stores an empty string rather than a bare "+62", so a
            // blank field never round-trips as a dial code with no subscriber.
            input.value = national && dialCode() ? '+' + dialCode() + national : national;
        }

        /**
         * Matches the LONGEST dial code the number starts with: a plain prefix search
         * would resolve +62 (Indonesia) to +6 and leave a mangled national number.
         */
        function setFromFull(full) {
            var digits = String(full == null ? '' : full).replace(/\D/g, '');

            if (!digits) {
                value = options.defaultCountry || '';
                national = '';
                sync();

                return;
            }

            var match = null;

            options.countries.forEach(function (country) {
                var dial = String(country.phoneCode);

                if (digits.indexOf(dial) === 0 && (!match || dial.length > String(match.phoneCode).length)) {
                    match = country;
                }
            });

            if (match) {
                var better = preferred[String(match.phoneCode)];

                if (better) {
                    match = options.countries.find(function (c) { return c.code === better; }) || match;
                }

                value = match.code;
                national = digits.slice(String(match.phoneCode).length);
            } else {
                value = options.defaultCountry || '';
                national = digits;
            }

            sync();
        }

        var paint = wireList(refs, options, function () { return value; }, function (code) {
            value = code;
            sync();
        });

        refs.toggle.addEventListener('click', function () {
            var opening = refs.panel.classList.contains('hidden');
            closeAll();

            if (opening) {
                // paint() BEFORE placing: the panel has to hold its rows before its
                // height can be measured, or it is positioned as an empty box.
                paint();
                openPanel(refs);
                refs.search.focus();
            }
        });

        refs.national.addEventListener('input', function () {
            var raw = refs.national.value.trim();

            // Pasting a full international number is the common case — people copy
            // "+62816955959" out of a contact card. Stripping it to digits and treating
            // it as a national number would store the dial code twice.
            if (raw.indexOf('+') === 0) {
                setFromFull(raw);

                return;
            }

            national = raw.replace(/\D/g, '');
            sync();
        });

        input.addEventListener('phone-input:set', function (event) {
            setFromFull(event.detail);
        });

        setFromFull(input.value);
    };
})();

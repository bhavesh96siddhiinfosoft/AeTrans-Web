/**
 * Where a dropdown panel goes, and how it keeps up with the page.
 *
 * Lifted out of `searchable-select.js` on 2026-08-26, when the country picker needed the
 * same two functions — which is exactly how the admin panel has it, in its `app.js`. Both
 * pickers open a `position: fixed` panel, because the booking form is full of scrolling
 * and `overflow: hidden` ancestors and an absolutely positioned panel is clipped by them.
 *
 *   window.placeDropdown(toggleButton, panelElement)
 *   window.trackDropdownScroll('[data-ss-panel]')
 */
(function () {
    'use strict';

    /**
     * Puts an open panel under its button and keeps it on screen.
     *
     * `position: fixed`, so the panel is never clipped by a scrolling or
     * overflow-hidden ancestor — the booking form is full of both.
     */
    window.placeDropdown = function (toggle, panel) {
        // Remembered so the scroll tracker can re-place the panel without the caller
        // having to hand it the button again.
        panel.dropdownToggle = toggle;

        var box = toggle.getBoundingClientRect();
        var width = Math.max(box.width, 240);
        var below = window.innerHeight - box.bottom;
        var start = box.left;

        panel.style.position = 'fixed';
        panel.style.width = width + 'px';
        panel.style.insetInlineStart = 'auto';

        // Pulled back from the edge of the screen rather than allowed to spill off it.
        if (start + width > window.innerWidth - 8) {
            start = Math.max(8, window.innerWidth - width - 8);
        }

        panel.style.left = start + 'px';

        // Flipped above the field when the space beneath is too small to be useful, and
        // capped either way so it shrinks into the room it has instead of running off.
        if (below < 240 && box.top > below) {
            panel.style.top = 'auto';
            panel.style.bottom = (window.innerHeight - box.top + 4) + 'px';
            panel.style.maxHeight = (box.top - 16) + 'px';
        } else {
            panel.style.top = (box.bottom + 4) + 'px';
            panel.style.bottom = 'auto';
            panel.style.maxHeight = (below - 16) + 'px';
        }
    };

    /**
     * Keeps open panels matching `selector` glued to their buttons while the page moves.
     *
     * A fixed panel does not move with its anchor, so something has to react to
     * scrolling. A scroll that starts INSIDE a panel is ignored — otherwise scrolling the
     * option list, the whole reason it scrolls, would dismiss the list.
     */
    window.trackDropdownScroll = function (selector) {
        function follow(event) {
            var target = event && event.target;

            // `event.target` is `document` for a page-level scroll, which has no
            // closest() — hence the guard rather than a bare call.
            if (target && target.closest && target.closest(selector)) {
                return;
            }

            document.querySelectorAll(selector + ':not(.hidden)').forEach(function (panel) {
                if (panel.dropdownToggle) {
                    window.placeDropdown(panel.dropdownToggle, panel);
                }
            });
        }

        window.addEventListener('scroll', follow, true);
        window.addEventListener('resize', follow);
    };

}());

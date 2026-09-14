{{--
    Light / dark switch.

    The button only WRITES the choice; the stamp that decides how the first paint looks
    is an inline script in the <head> (partials/theme-stamp), because a class applied
    after the stylesheet has already painted is the white flash the spec forbids.

    Three states, not two: light, dark, and no choice at all — which follows the
    operating system. Cycling through all three is why this is a button and not a
    checkbox; a checkbox cannot express "I have not decided".
--}}
<button type="button" class="theme-toggle" data-theme-toggle
        aria-label="{{ __('lang.switch_between_light_dark') }}" title="{{ __('lang.switch_between_light_dark') }}">
    <svg class="theme-icon theme-icon-light" width="18" height="18" viewBox="0 0 20 20" fill="none"
         stroke="currentColor" stroke-width="1.6" aria-hidden="true">
        <circle cx="10" cy="10" r="3.6"/>
        <path d="M10 1.6v2M10 16.4v2M1.6 10h2M16.4 10h2M4.1 4.1l1.4 1.4M14.5 14.5l1.4 1.4M15.9 4.1l-1.4 1.4M5.5 14.5l-1.4 1.4" stroke-linecap="round"/>
    </svg>

    <svg class="theme-icon theme-icon-dark" width="18" height="18" viewBox="0 0 20 20" fill="none"
         stroke="currentColor" stroke-width="1.6" aria-hidden="true">
        <path d="M17 11.7A7.4 7.4 0 0 1 8.3 3a7.4 7.4 0 1 0 8.7 8.7z" stroke-linejoin="round"/>
    </svg>
</button>

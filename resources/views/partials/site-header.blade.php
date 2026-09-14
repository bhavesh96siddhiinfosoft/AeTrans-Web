{{--
    The site header: logo, the two services, the theme switch, and the way in.

    ONE piece of markup for both widths — laid out as a row on a wide screen, and
    behind a toggle on a narrow one. Duplicating it into a desktop nav and a mobile
    drawer is how the two end up saying different things.

    The toggle is a checkbox and a label, NOT a <details>. This was written with
    <details> first and the whole menu vanished on desktop: a browser hides the content
    of a closed <details> itself, and `display` on a child does not override that. The
    checkbox has no such rule — the panel is laid out normally on desktop and revealed
    by `:checked` on mobile — and it still needs no JavaScript, which matters on a site
    with no build step.

    A visitor is never pushed at the sign-in page: signing in is an option in the
    corner, and the landing page is the default route.
--}}
<header class="site-header">
    <div class="site-header-inner">
        @include('partials.brand')

        {{-- Visually hidden but still focusable, so the menu opens from the keyboard.
             `hidden` would take it out of the tab order entirely. --}}
        <input type="checkbox" id="site-menu" class="site-menu-input">

        <label for="site-menu" class="site-menu-toggle" aria-label="{{ __('lang.menu') }}">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M3 5h14M3 10h14M3 15h14" stroke-linecap="round"/>
            </svg>
        </label>

        <div class="site-menu-panel">
            <nav class="site-nav">
                {{-- A service the admin has switched off loses its link here too, not
                     just its card. The panel's Services screen promises "switch one off
                     to take it off the website", and a nav link pointing at an anchor
                     that no longer renders is exactly the half-measure that promise
                     rules out. --}}
                @if ($services->offersCharter())
                    <a href="{{ route('home') }}#charter">{{ $services->charter() }}</a>
                @endif
                @if ($services->offersShuttle())
                    <a href="{{ route('home') }}#shuttle">{{ $services->shuttle() }}</a>
                @endif
            </nav>

            <div class="site-header-actions">
                @include('partials.language-switcher')
                @include('partials.theme-toggle')

                @auth
                    @include('partials.account-menu')
                @else
                    <a href="{{ route('login') }}" class="btn btn-ghost">{{ __('lang.log_in') }}</a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="btn btn-primary">{{ __('lang.sign_up') }}</a>
                    @endif
                @endauth
            </div>
        </div>
    </div>
</header>

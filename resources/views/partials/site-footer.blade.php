{{--
    The footer, entirely from Global Settings.

    Every block is conditional. The admin fills these in over time, and a footer that
    prints "Email:" with nothing after it, or a social icon that goes nowhere, reads as
    a broken site rather than an unfinished one — so an empty setting means the block is
    simply absent.

    Nothing here is invented: no fake address, no placeholder hours, no "24/7 support"
    the client never promised.
--}}
@php
    $socials = $site->socialLinks();
    // tel: and wa.me want digits only — a space or a dash in the href makes the link
    // dead on some Android dialers.
    $dial = fn (?string $number) => $number ? preg_replace('/[^0-9+]/', '', $number) : null;
@endphp

<footer class="site-footer">
    <div class="site-footer-inner">
        <div class="site-footer-top">
            <div class="site-footer-brand">
                @include('partials.brand', ['variant' => 'footer'])

                @if ($tagline = $site->tagline())
                    <p class="site-footer-tagline">{{ $tagline }}</p>
                @endif

                @if ($playStore = $site->playStoreUrl())
                    <a href="{{ $playStore }}" class="btn btn-secondary" rel="noopener">
                        {{ __('lang.get_the_app') }}
                    </a>
                @endif
            </div>

            <nav class="site-footer-nav" aria-label="{{ __('lang.services') }}">
                <h2 class="site-footer-heading">{{ __('lang.services') }}</h2>
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

            {{-- The admin's own pages, in the order they set on the CMS list screen. The
                 block is absent when there are none: a heading over nothing reads as a
                 broken footer, the same rule the contact block follows. --}}
            @php($pages = $cmsPages->menu())
            @if ($pages)
                <nav class="site-footer-nav" aria-label="{{ __('lang.information') }}">
                    <h2 class="site-footer-heading">{{ __('lang.information') }}</h2>

                    @foreach ($pages as $page)
                        <a href="{{ url($page['path'] ?? '') }}">{{ $page['name'] ?? '' }}</a>
                    @endforeach
                </nav>
            @endif

            @if ($site->email() || $site->phone() || $site->whatsapp() || $site->address() || $site->supportUrl())
                <div class="site-footer-contact">
                    <h2 class="site-footer-heading">{{ __('lang.contact') }}</h2>

                    @if ($email = $site->email())
                        <a href="mailto:{{ $email }}">{{ $email }}</a>
                    @endif

                    @if ($phone = $site->phone())
                        <a href="tel:{{ $dial($phone) }}">{{ $phone }}</a>
                    @endif

                    @if ($whatsapp = $site->whatsapp())
                        {{-- wa.me takes the number without its leading +. --}}
                        <a href="https://wa.me/{{ ltrim($dial($whatsapp), '+') }}" rel="noopener">
                            {{ __('lang.whatsapp') }}
                        </a>
                    @endif

                    @if ($support = $site->supportUrl())
                        <a href="{{ $support }}" rel="noopener">{{ __('lang.help_support') }}</a>
                    @endif

                    @if ($address = $site->address())
                        <address class="site-footer-address">{{ $address }}</address>
                    @endif
                </div>
            @endif
        </div>

        <div class="site-footer-bottom">
            <span>&copy; {{ date('Y') }} {{ $site->siteName() }}</span>

            @if ($socials)
                <ul class="site-social" aria-label="{{ __('lang.social_links') }}">
                    @foreach ($socials as $platform => $url)
                        <li>
                            <a href="{{ $url }}" rel="noopener" aria-label="{{ ucfirst($platform) }}" title="{{ ucfirst($platform) }}">
                                @include('partials.social-icon', ['platform' => $platform])
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</footer>

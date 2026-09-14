{{--
    The logo, from Global Settings — one partial so the header, the footer and the
    signed-out card cannot drift apart.

    Three things worth knowing:

    * When the admin has uploaded NO logo, the site's name is printed as words. A brand
      is not missing just because a file is; an empty header is.
    * A separate dark logo is optional. Both marks are rendered and CSS picks one, so
      the swap survives a theme change with no script and no flash. When there is only
      one, it is shown on both grounds rather than hiding the logo on dark.
    * `$variant` picks the footer's mark. It falls back to the header's.

    Pass `$linked = false` for a place that is already inside a link.
--}}
@php
    $variant = $variant ?? 'header';
    $light = $variant === 'footer' ? $site->footerLogo() : $site->logo();
    $dark = $site->logoDark() ?: null;
    $name = $site->siteName();
    $linked = $linked ?? true;
    $tag = $linked ? 'a' : 'span';
@endphp

<{{ $tag }} @if ($linked) href="{{ route('home') }}" @endif class="brand brand-{{ $variant }}">
    @if ($light)
        <img src="{{ $light }}" alt="{{ $name }}" class="brand-logo {{ $dark ? 'brand-logo-light' : '' }}" loading="eager" decoding="async">

        @if ($dark)
            <img src="{{ $dark }}" alt="{{ $name }}" class="brand-logo brand-logo-dark" loading="eager" decoding="async">
        @endif
    @else
        <span class="brand-name">{{ $name }}</span>
    @endif
</{{ $tag }}>

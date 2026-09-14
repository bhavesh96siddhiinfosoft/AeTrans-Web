{{--
    Signed-in shell. No @vite — this project has no build step; see layouts/guest.

    It carries the same header and footer as the public pages: a customer who has just
    signed in has not left the site, and a different shell either side of the login
    would read as two products.

    `noindex` is deliberate. These pages are a customer's own bookings and profile —
    there is nothing here for a search engine, and the meta tags are only present so a
    shared link does not render as a bare URL.
--}}
@php
    $asset = function (string $path) {
        $file = public_path($path);

        return asset($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
    };
@endphp
<!DOCTYPE html>
{{-- `dir` comes from the language's own `isRtl` flag, so Arabic works the day the
     admin enables it — the stylesheet already uses logical properties throughout. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $languages->direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    @include('partials.theme-stamp')
    @include('partials.seo', ['title' => trim(\Illuminate\Support\Facades\View::yieldContent('title')) ?: null])

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <link href="{{ $asset('css/style.css') }}" rel="stylesheet">
</head>
<body>
    <div class="site">
        {{-- The SAME header as every public page. It was Breeze's own nav until
             2026-08-26, which carried a second, different account dropdown — so the
             menu changed shape depending on which page you were on. --}}
        @include('partials.site-header')

        <main class="site-main">
            <div class="page">
                @hasSection('header')
                    <div class="page-head">@yield('header')</div>
                @endif

                @yield('content')
            </div>
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>

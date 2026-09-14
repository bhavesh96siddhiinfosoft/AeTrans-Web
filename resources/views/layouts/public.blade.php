{{--
    Public shell — the pages a visitor sees before signing in, and the landing page
    is the first of them.

    Separate from layouts/app (signed-in) and layouts/guest (the auth cards) because
    it has a different job: it sells, so it carries the full header, the service nav
    and a real footer, and it must render for a visitor with no account at all.

    The logo, the site name, the meta tags and the whole footer come from Global
    Settings in Firestore — the admin owns them, and this file only decides where they
    go. `$site` is put here by the view composer in AppServiceProvider.

    No @vite: this project has no build step. See public/css/style.css.
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

    {{-- First, before the stylesheet: the theme has to be decided before anything is
         painted, or a dark phone gets a white flash. --}}
    @include('partials.theme-stamp')

    {{--
        The page's own title, from `@section('title')`, falling back to the admin's meta
        title when a page does not set one.

        This is why the layouts were converted from components on 2026-08-26: the title
        used to arrive as an `x-public-layout` attribute, and since the component class
        had no `$title` property it landed in `$attributes` instead — so `partials.seo`
        fell back on EVERY page and the whole site served one `<title>`.
    --}}
    @include('partials.seo', ['title' => trim(\Illuminate\Support\Facades\View::yieldContent('title')) ?: null])

    {{-- Anything a page needs BEFORE it paints. The booking wizard uses it to catch a
         refresh and start over without the old answers flashing up first. --}}
    @yield('head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    {{-- Font Awesome, the same copy the admin panel serves, so an icon means the same
         thing on both. Local rather than a CDN: this is a booking page, and a third
         party being slow must not be able to hold up the form.

         Only the `.woff2` files were copied — 320KB instead of 2.9MB. The `.eot`,
         `.ttf`, `.svg` and `.woff` variants in the panel's copy are for browsers that
         cannot run this site anyway. --}}
    <link href="{{ $asset('css/icons/font-awesome/css/all.css') }}" rel="stylesheet">
    <link href="{{ $asset('css/style.css') }}" rel="stylesheet">
</head>
<body>
    <div class="site">
        @include('partials.site-header')

        <main class="site-main">
            @yield('content')
        </main>

        @include('partials.site-footer')
    </div>
</body>
</html>

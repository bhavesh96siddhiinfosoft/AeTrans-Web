{{--
    Signed-out shell — login, register, password reset, email verification.

    The admin's logo sits above the card, and the meta tags are the same ones the
    public pages carry: these URLs get shared and linked to as well.

    No @vite: this project has NO build step. The stylesheet is a hand-written file
    served straight from public/, the same as the admin panel. See public/css/style.css.
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

    @include('partials.theme-stamp')
    @include('partials.seo', ['title' => trim(\Illuminate\Support\Facades\View::yieldContent('title')) ?: null])

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">
    <link href="{{ $asset('css/style.css') }}" rel="stylesheet">
</head>
<body>
    <div class="auth-shell">
        @include('partials.brand')

        <div class="auth-card">
            @yield('content')
        </div>

        <div class="auth-shell-foot">
            <a href="{{ route('home') }}">{{ __('lang.back_to_site') }}</a>
            @include('partials.theme-toggle')
        </div>
    </div>
</body>
</html>

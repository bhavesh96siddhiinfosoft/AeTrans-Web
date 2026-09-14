{{--
    Create an account.

    Plain HTML, no Blade components — see the note in login.blade.php.

    Every rule below is one the SERVER also enforces, and is written on the element so
    both read the same fact: `required`, `type=email`, `minlength=8` (Laravel's
    `Password::defaults()` is `min:8` and nothing else), and `data-match` on the
    confirmation. If the server's rules change, these have to change with them — a
    client-side rule the server does not share refuses input that would have been fine.
--}}
@extends('layouts.guest')

@section('title', __('lang.create_an_account'))

@section('content')
<div class="auth-card-head">
    <h1>{{ __('lang.create_your_account') }}</h1>
    <p>{{ __('lang.site_description') }}</p>
</div>

<form method="POST" action="{{ route('register') }}" class="form">
    @csrf

    <div class="form-field">
        <label for="name" class="form-label">{{ __('lang.name') }}</label>

        <input id="name" class="form-input" type="text" name="name" value="{{ old('name') }}"
               required autofocus autocomplete="name" maxlength="255"
               data-required-message="{{ __('lang.enter_your_name') }}">

        @if ($errors->has('name'))
            <ul class="form-error">
                @foreach ($errors->get('name') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="form-field">
        <label for="email" class="form-label">{{ __('lang.email') }}</label>

        <input id="email" class="form-input" type="email" name="email" value="{{ old('email') }}"
               required autocomplete="username" maxlength="255"
               data-required-message="{{ __('lang.enter_your_email') }}">

        @if ($errors->has('email'))
            <ul class="form-error">
                @foreach ($errors->get('email') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="form-field">
        <label for="password" class="form-label">{{ __('lang.password') }}</label>

        <input id="password" class="form-input" type="password" name="password"
               required autocomplete="new-password" minlength="8"
               data-required-message="{{ __('lang.choose_a_password') }}">

        @if ($errors->has('password'))
            <ul class="form-error">
                @foreach ($errors->get('password') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        {{-- Said BEFORE it is broken, not only after. A rule a customer meets first
             time is not an error message. --}}
        <p class="form-help">{{ __('lang.use_at_least_characters', ['min' => 8]) }}</p>
    </div>

    <div class="form-field">
        <label for="password_confirmation" class="form-label">{{ __('lang.confirm_password') }}</label>

        <input id="password_confirmation" class="form-input" type="password" name="password_confirmation"
               required autocomplete="new-password"
               data-match="password"
               data-required-message="{{ __('lang.repeat_your_password') }}"
               data-match-message="{{ __('lang.passwords_do_not_match') }}">

        @if ($errors->has('password_confirmation'))
            <ul class="form-error">
                @foreach ($errors->get('password_confirmation') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <button type="submit" class="btn btn-primary is-block">{{ __('lang.create_account') }}</button>
</form>

@include('auth.partials.google-button')
@include('partials.form-validation')

<div class="auth-foot">
    <span>{{ __('lang.already_registered') }}</span>
    <a href="{{ route('login') }}">{{ __('lang.log_in') }}</a>
</div>
@endsection

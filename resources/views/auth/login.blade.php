{{--
    Sign in.

    Plain HTML, no Blade components. `<x-text-input>` renders `<input class="form-input">`
    and nothing else — the abstraction hid the markup without saving anything, and the
    file next to this one (verify-email) already wrote its alert out by hand, so the two
    did not even agree with each other.

    The fields carry their own required messages rather than the generic line: "Enter
    your email address" says what to do, and two copies of "This field is required" down
    one short form says nothing at all.

    NO `minlength` on the password here, deliberately — unlike the register page. This
    box holds an EXISTING password, and an account made before the rule was introduced
    may be shorter than 8. Refusing to submit it would lock that customer out of their
    own account with a message about a rule they never agreed to; the server checks the
    password against Firebase, which is the only thing that can actually say.
--}}
@extends('layouts.guest')

@section('title', __('lang.log_in'))

@section('content')
<div class="auth-card-head">
    <h1>{{ __('lang.welcome_back') }}</h1>
    <p>{{ __('lang.sign_manage_bookings') }}</p>
</div>

{{-- "We have sent you a reset link", after the forgot-password form. --}}
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('login') }}" class="form">
    @csrf

    <div class="form-field">
        <label for="email" class="form-label">{{ __('lang.email') }}</label>

        <input id="email" class="form-input" type="email" name="email" value="{{ old('email') }}"
               required autofocus autocomplete="username"
               data-required-message="{{ __('lang.enter_your_email') }}">

        {{-- A list, not a paragraph: one field can fail more than one rule, and the
             server returns all of them. --}}
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
               required autocomplete="current-password"
               data-required-message="{{ __('lang.enter_your_password') }}">

        @if ($errors->has('password'))
            <ul class="form-error">
                @foreach ($errors->get('password') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- No "remember me". Laravel's version signs a customer in from a cookie
         without asking Firebase, so it would outlive a password change made in the
         mobile app and a suspension made in the panel. Session lifetime is the
         honest control — see LoginRequest. --}}
    @if (Route::has('password.request'))
        <div class="row is-end">
            <a href="{{ route('password.request') }}" class="text-sm">{{ __('lang.forgot_password') }}</a>
        </div>
    @endif

    <button type="submit" class="btn btn-primary is-block">{{ __('lang.log_in') }}</button>
</form>

@include('auth.partials.google-button')
@include('partials.form-validation')

@if (Route::has('register'))
    <div class="auth-foot">
        <span>{{ __('lang.no_account_yet') }}</span>
        <a href="{{ route('register') }}">{{ __('lang.create_one') }}</a>
    </div>
@endif
@endsection

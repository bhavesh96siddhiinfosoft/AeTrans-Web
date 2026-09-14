{{--
    Ask for a password reset link.

    Plain HTML, no Blade components — see the note in login.blade.php.
--}}
@extends('layouts.guest')

@section('title', __('lang.reset_password'))

@section('content')
<div class="auth-card-head">
    <h1>{{ __('lang.reset_password') }}</h1>
    <p>{{ __('lang.forgot_password_lead') }}</p>
</div>

{{-- "Check your email" — the same message whether or not the address is registered,
     which is deliberate: telling a stranger which addresses have accounts is a way of
     enumerating customers. --}}
@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

<form method="POST" action="{{ route('password.email') }}" class="form">
    @csrf

    <div class="form-field">
        <label for="email" class="form-label">{{ __('lang.email') }}</label>

        <input id="email" class="form-input" type="email" name="email" value="{{ old('email') }}"
               required autofocus autocomplete="username"
               data-required-message="{{ __('lang.enter_your_email') }}">

        @if ($errors->has('email'))
            <ul class="form-error">
                @foreach ($errors->get('email') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <button type="submit" class="btn btn-primary is-block">{{ __('lang.email_password_reset_link') }}</button>
</form>

@include('partials.form-validation')

<div class="auth-foot">
    <a href="{{ route('login') }}">{{ __('lang.back_to_sign_in') }}</a>
</div>
@endsection

@extends('layouts.guest')

@section('title', __('lang.verify_email'))

@section('content')
<div class="auth-card-head">
    <h1>{{ __('lang.verify_email') }}</h1>
    <p>{{ __('lang.verification_email_sent') }}</p>
</div>

@if (session('status') == 'verification-link-sent')
    <div class="alert alert-success">
        {{ __('lang.new_verification_link_been_sent') }}
    </div>
@endif

<form method="POST" action="{{ route('verification.send') }}" class="form">
    @csrf
    <button type="submit" class="btn btn-primary is-block">{{ __('lang.resend_verification_email') }}</button>
</form>

<div class="auth-foot">
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="btn btn-ghost">{{ __('lang.log_out') }}</button>
    </form>
</div>
@endsection

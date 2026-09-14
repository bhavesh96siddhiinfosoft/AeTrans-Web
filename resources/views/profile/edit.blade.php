@extends('layouts.app')

@section('title', __('lang.profile'))

@section('header')
    <h1 class="page-title">{{ __('lang.profile') }}</h1>
    <p class="page-subtitle">{{ __('lang.account_details_password') }}</p>
@endsection

@section('content')
<div class="stack">
    <div class="card">
        <div class="card-body">
            @include('profile.partials.update-profile-information-form')
        </div>
    </div>

    {{-- Password screens are only offered to accounts that HAVE a password of
         ours. A Google or phone account is authenticated by Firebase, so there is
         nothing here to change and the form would fail on submit. --}}
    @if (auth()->user()->hasPassword())
        <div class="card">
            <div class="card-body">
                @include('profile.partials.update-password-form')
            </div>
        </div>
    @endif

    {{-- No "delete account" card. Deleting the mirror row would leave the Firebase
         account, the Firestore profile and every past booking behind — see
         ProfileController. --}}
</div>
@endsection

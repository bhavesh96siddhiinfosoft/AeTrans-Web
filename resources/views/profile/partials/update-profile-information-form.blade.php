<div class="card-head">
    <div class="card-title">{{ __('lang.profile_information') }}</div>
    <div class="card-subtitle">{{ __('lang.update_name_email_address') }}</div>
</div>

<form id="send-verification" method="POST" action="{{ route('verification.send') }}">
    @csrf
</form>

<form method="POST" action="{{ route('profile.update') }}" class="form">
    @csrf
    @method('patch')

    <div class="form-field">
        <label for="name" class="form-label">{{ __('lang.name') }}</label>
        <input class="form-input" id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name">
        @if ($errors->has('name'))
            <ul class="form-error">
                @foreach ($errors->get('name') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    {{-- Shown, not editable. The address identifies the Firebase account that the
         mobile app signs into as well, so changing it is a re-authentication and a
         re-verification, not a text edit — see ProfileController::update(). --}}
    <div class="form-field">
        <label for="email" class="form-label">{{ __('lang.email') }}</label>
        <input class="form-input" id="email" type="email" value="{{ $user->email }}">
        @if ($errors->has('email'))
            <ul class="form-error">
                @foreach ($errors->get('email') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif

        @if (! $user->email_verified_at)
            <p class="form-help">
                {{ __('lang.email_address_unverified') }}
                <button form="send-verification" class="btn btn-ghost">{{ __('lang.resend_verification_email') }}</button>
            </p>

            @if (session('status') === 'verification-link-sent')
                <p class="form-help">{{ __('lang.new_verification_link_been_sent') }}</p>
            @endif
        @endif

        <p class="form-help">
            {{ __('lang.signed_in_with', ['provider' => $user->providerLabel()]) }}
        </p>
    </div>

    <div class="row is-wrap">
        <button type="submit" class="btn btn-primary">{{ __('lang.save') }}</button>

        @if (session('status') === 'profile-updated')
            <span class="text-sm text-muted">{{ __('lang.saved') }}</span>
        @endif
    </div>
</form>

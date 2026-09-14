<div class="card-head">
    <div class="card-title">{{ __('lang.update_password') }}</div>
    <div class="card-subtitle">{{ __('lang.password_advice') }}</div>
</div>

<form method="POST" action="{{ route('password.update') }}" class="form">
    @csrf
    @method('put')

    <div class="form-field">
        <label for="update_password_current_password" class="form-label">{{ __('lang.current_password') }}</label>
        <input class="form-input" id="update_password_current_password" name="current_password" type="password" autocomplete="current-password">
        @if ($errors->updatePassword->has('current_password'))
            <ul class="form-error">
                @foreach ($errors->updatePassword->get('current_password') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="form-field">
        <label for="update_password_password" class="form-label">{{ __('lang.new_password') }}</label>
        <input class="form-input" id="update_password_password" name="password" type="password" autocomplete="new-password">
        @if ($errors->updatePassword->has('password'))
            <ul class="form-error">
                @foreach ($errors->updatePassword->get('password') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="form-field">
        <label for="update_password_password_confirmation" class="form-label">{{ __('lang.confirm_password') }}</label>
        <input class="form-input" id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password">
        @if ($errors->updatePassword->has('password_confirmation'))
            <ul class="form-error">
                @foreach ($errors->updatePassword->get('password_confirmation') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="row is-wrap">
        <button type="submit" class="btn btn-primary">{{ __('lang.save') }}</button>

        @if (session('status') === 'password-updated')
            <span class="text-sm text-muted">{{ __('lang.saved') }}</span>
        @endif
    </div>
</form>

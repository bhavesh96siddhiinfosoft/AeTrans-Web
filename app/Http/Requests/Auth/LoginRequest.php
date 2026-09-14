<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Firebase\AccountBlockedException;
use App\Services\Firebase\CustomerAccounts;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\IdentityToolkitException;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Check the credentials with Firebase and sign the customer in here.
     *
     * `Auth::attempt()` is gone from this method on purpose. It checks a password
     * column that this site no longer fills: the account lives in Firebase, shared
     * with the mobile app, and a customer who changes their password in the app must
     * be able to use the new one here in the same second.
     *
     * The throttle stays. Firebase has its own abuse limits, but they are Google's to
     * tune, they answer with an opaque TOO_MANY_ATTEMPTS, and they do not stop someone
     * hammering this server.
     *
     * @throws ValidationException
     */
    public function authenticate(
        IdentityToolkit $auth,
        CustomerAccounts $accounts,
        FirebaseSession $firebase,
    ): void {
        $this->ensureIsNotRateLimited();

        try {
            $identity = $auth->signIn($this->string('email'), $this->string('password'));
            $user = $accounts->sync($identity);
        } catch (IdentityToolkitException|AccountBlockedException $e) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => $e->message()]);
        }

        RateLimiter::clear($this->throttleKey());

        $firebase->start($identity);

        $this->signIn($user);
    }

    /**
     * `remember` is accepted and ignored, and the checkbox has been taken off the form.
     *
     * Laravel's remember-me works by matching a `remember_token` against the password
     * column's owner — this application. Firebase owns the credential now, so a
     * remembered cookie would sign someone in without Firebase ever being asked, and
     * would keep working after the account was suspended or its password changed in the
     * app. Session lifetime is the honest control (SESSION_LIFETIME).
     */
    private function signIn(User $user): void
    {
        Auth::login($user);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}

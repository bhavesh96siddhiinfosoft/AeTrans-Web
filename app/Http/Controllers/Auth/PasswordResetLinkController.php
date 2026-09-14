<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\IdentityToolkitException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password reset, run by Firebase from end to end.
 *
 * Laravel's `Password` broker is not used and its `password_reset_tokens` table stays
 * empty: the password being reset lives in Firebase, shared with the mobile app, so a
 * token issued here could not change it. Firebase emails the link and hosts the page
 * where the new password is chosen — which is why there is no `reset-password` route
 * on this site any more.
 */
class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request, IdentityToolkit $auth): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $throttleKey = 'password-reset|'.Str::lower((string) $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds = RateLimiter::availableIn($throttleKey),
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        RateLimiter::hit($throttleKey, 60 * 15);

        try {
            $auth->sendPasswordResetEmail($request->string('email'));
        } catch (IdentityToolkitException $e) {
            /*
             * EMAIL_NOT_FOUND is swallowed on purpose. Telling a visitor that an
             * address has no account turns this form into a way to test which of a
             * list of addresses are customers. Everything else the customer can act
             * on — a rate limit, an outage — is shown.
             */
            if ($e->errorCode !== 'EMAIL_NOT_FOUND') {
                Log::warning('Firebase refused a password reset request.', ['code' => $e->errorCode]);

                return back()->withInput($request->only('email'))
                    ->withErrors(['email' => $e->message()]);
            }
        }

        // The same answer whether or not the address exists, for the reason above.
        return back()->with('status', __('lang.password_reset_sent'));
    }
}

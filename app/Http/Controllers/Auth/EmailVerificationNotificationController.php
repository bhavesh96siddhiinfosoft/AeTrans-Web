<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\IdentityToolkitException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Ask Firebase to send the verification email again.
     *
     * Not `sendEmailVerificationNotification()`: that is Laravel's own flow, with a
     * signed URL back to this site and a `email_verified_at` column it sets itself.
     * The address belongs to a Firebase account shared with the mobile app, so Firebase
     * has to be the one to verify it — otherwise a customer could be verified here and
     * unverified in the app.
     */
    public function store(Request $request, IdentityToolkit $auth, FirebaseSession $firebase): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('bookings', absolute: false));
        }

        $idToken = $firebase->idToken();

        if (! $idToken) {
            // The Firebase session is gone even though the Laravel one is not — the
            // refresh token was revoked, or expired. Signing in again fixes it, and is
            // a truer answer than a "sent" message for an email that never left.
            return redirect()->route('login')
                ->withErrors(['email' => __('lang.please_sign_again_continue')]);
        }

        try {
            $auth->sendVerificationEmail($idToken);
        } catch (IdentityToolkitException $e) {
            return back()->withErrors(['email' => $e->message()]);
        }

        return back()->with('status', 'verification-link-sent');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Firebase\AccountBlockedException;
use App\Services\Firebase\CustomerAccounts;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\IdentityToolkitException;
use App\Services\Site\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Google sign-in — the one flow that needs the browser.
 *
 * Google's consent screen has to run in a window, so the Firebase JS SDK opens it,
 * and what arrives here is the ID token it minted (public/js/firebase-google.js).
 * The token is not trusted on sight: `verifyIdToken()` asks Google about it, which
 * also rules out a token minted for a different Firebase project or an account an
 * operator has since disabled.
 *
 * There is no Google client id or secret in this application. Firebase holds them and
 * performs the OAuth exchange; this endpoint never sees them.
 *
 * It is a normal form POST, not a fetch — so the CSRF token, the validation errors and
 * the redirect all work exactly as they do on the password form, with no second error
 * path to maintain.
 */
class GoogleSessionController extends Controller
{
    public function store(
        Request $request,
        IdentityToolkit $auth,
        CustomerAccounts $accounts,
        FirebaseSession $firebase,
        SiteSettings $site,
    ): RedirectResponse {
        // The admin's switch (`web_google_login`), not ours. Hiding the button while
        // leaving the endpoint open would make the setting decoration.
        abort_unless($site->googleLoginEnabled(), 404);

        $request->validate([
            'id_token' => ['required', 'string'],
            // The browser SDK hands this over with the user object. It is what lets the
            // server keep reading Firestore as this customer after the ID token's hour
            // is up; without it the session still works, it just cannot refresh.
            'refresh_token' => ['nullable', 'string'],
        ]);

        try {
            $identity = $auth->verifyIdToken(
                $request->string('id_token'),
                $request->filled('refresh_token') ? $request->string('refresh_token') : null,
            );

            $user = $accounts->sync($identity);
        } catch (IdentityToolkitException|AccountBlockedException $e) {
            throw ValidationException::withMessages(['email' => $e->message()]);
        }

        $firebase->start($identity);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended(route('bookings', absolute: false));
    }
}

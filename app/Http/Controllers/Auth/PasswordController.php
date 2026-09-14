<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\IdentityToolkitException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /**
     * Update the user's password — in Firebase, where it actually lives.
     *
     * The `current_password` validation rule is gone: it compares against
     * `users.password`, which this application no longer writes. The old password is
     * checked by signing in with it, which is the same check Firebase would make and
     * the only one the mobile app would agree with.
     */
    public function update(Request $request, IdentityToolkit $auth, FirebaseSession $firebase): RedirectResponse
    {
        $user = $request->user();

        // Google and phone accounts have no password of ours to change; the form is
        // not shown to them, and this is the matching refusal for a direct POST.
        abort_unless($user->hasPassword(), 403);

        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'string'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        try {
            $identity = $auth->signIn($user->email, $validated['current_password']);
        } catch (IdentityToolkitException $e) {
            throw ValidationException::withMessages([
                'current_password' => $e->isBadCredentials()
                    ? __('lang.wrong_current_password')
                    : $e->message(),
            ])->errorBag('updatePassword');
        }

        try {
            $identity = $auth->changePassword($identity, $validated['password']);
        } catch (IdentityToolkitException $e) {
            throw ValidationException::withMessages([
                'password' => $e->message(),
            ])->errorBag('updatePassword');
        }

        // Firebase revoked the old tokens when the password changed, this session's
        // included. Store the fresh pair it handed back, or the next Firestore read
        // would fail on a session the customer never left.
        $firebase->start($identity);

        return back()->with('status', 'password-updated');
    }
}

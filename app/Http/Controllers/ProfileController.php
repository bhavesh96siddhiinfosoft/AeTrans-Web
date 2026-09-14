<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\IdentityToolkitException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * The name is written to BOTH places: our mirror row, and the Firebase account —
     * where the mobile app reads it. Firebase first, so a failure there stops the two
     * from disagreeing.
     *
     * The email address is deliberately not editable here. Changing it means changing
     * the Firebase account's sign-in identifier, which needs a re-authentication and a
     * verification round trip of its own; a form that appeared to change it and only
     * changed our copy would leave the customer unable to sign in with what the page
     * shows them.
     */
    public function update(ProfileUpdateRequest $request, IdentityToolkit $auth, FirebaseSession $firebase): RedirectResponse
    {
        $name = $request->validated()['name'];
        $idToken = $firebase->idToken();

        if ($idToken) {
            try {
                $auth->updateDisplayName($idToken, $name);
            } catch (IdentityToolkitException $e) {
                return Redirect::route('profile.edit')->withErrors(['name' => $e->message()]);
            }
        }

        $request->user()->forceFill(['name' => $name])->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /*
     * `destroy()` is gone, and with it the delete-account form.
     *
     * Breeze's version deleted the local row after checking a local password. Neither
     * half applies: the password lives in Firebase, and the row is a mirror — deleting
     * it would leave the Firebase account, the Firestore profile and every booking the
     * customer has made, while making the person invisible to this site.
     *
     * Closing an account is an operator action in the admin panel (which is what
     * `blocked` is for). If self-service deletion is wanted, it has to delete the
     * Firebase account and decide what happens to past bookings first — a client
     * question, not a code change.
     */
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Firebase\AccountBlockedException;
use App\Services\Firebase\CustomerAccounts;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\IdentityToolkit;
use App\Services\Firebase\IdentityToolkitException;
use App\Services\Mail\CustomerMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * The account is created in FIREBASE, not here. The row written to our `users`
     * table afterwards is a mirror — it carries the UID, the email and the provider so
     * the customer joins up with the admin panel and the mobile app, and it carries no
     * password at all.
     *
     * @throws ValidationException
     */
    public function store(
        Request $request,
        IdentityToolkit $auth,
        CustomerAccounts $accounts,
        FirebaseSession $firebase,
        CustomerMail $mail,
    ): RedirectResponse {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            /*
             * No `unique:users` rule. Firebase decides whether the address is already
             * taken, because Firebase is where the account lives — a local row can
             * exist without a Firebase account (the users migration allows a null UID),
             * and refusing on that row would block a customer Firebase would accept.
             * The answer comes back as EMAIL_EXISTS below.
             */
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $this->ensureIsNotRateLimited($request);

        try {
            $identity = $auth->register($request->string('email'), $request->string('password'), $request->string('name'));
        } catch (IdentityToolkitException $e) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                $e->isEmailTaken() ? 'email' : 'name' => $e->message(),
            ]);
        }

        try {
            $user = $accounts->sync($identity);
        } catch (AccountBlockedException $e) {
            // Reachable: the operator can have created a blocked profile document for
            // this person before they ever signed up.
            throw ValidationException::withMessages(['email' => $e->message()]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $firebase->start($identity);

        /*
         * Firebase sends the verification email, using the template in the Firebase
         * console — not Laravel's mail stack. Best effort: an account that exists but
         * has no verification email yet is recoverable from the profile page, whereas
         * failing the registration here would leave a Firebase account with no local
         * mirror and no session.
         */
        try {
            $auth->sendVerificationEmail($identity->idToken);
        } catch (IdentityToolkitException) {
            // Intentionally ignored; see above.
        }

        /*
         * The welcome email, which is NOT the verification email — Firebase sent that
         * one just above, from its own console template. Two emails arrive together and
         * only one of them asks to be clicked, deliberately: a welcome that also carried
         * a link is how a real verification link gets reported as phishing.
         *
         * Best effort, like the verification send: `CustomerMail` swallows and logs, so
         * a refusing SMTP host cannot cost this customer the account Firebase has
         * already created.
         */
        $mail->welcome((string) $user->email, (string) $user->name);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect(route('bookings', absolute: false));
    }

    /** @throws ValidationException */
    private function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds = RateLimiter::availableIn($this->throttleKey($request)),
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Throttled by IP alone, not by email.
     *
     * Registration failures are how an attacker probes which addresses already have
     * accounts, and each probe uses a different address — a per-email key would never
     * count past one.
     */
    private function throttleKey(Request $request): string
    {
        return 'register|'.Str::lower((string) $request->ip());
    }
}

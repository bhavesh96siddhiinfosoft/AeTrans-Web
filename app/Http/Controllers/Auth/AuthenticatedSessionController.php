<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Firebase\CustomerAccounts;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\IdentityToolkit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        IdentityToolkit $auth,
        CustomerAccounts $accounts,
        FirebaseSession $firebase,
    ): RedirectResponse {
        $request->authenticate($auth, $accounts, $firebase);

        $request->session()->regenerate();

        return redirect()->intended(route('bookings', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, FirebaseSession $firebase): RedirectResponse
    {
        // Drop the Firebase tokens before the session is invalidated. Order is not
        // strictly required — invalidate() clears the lot — but it keeps the intent
        // readable, and it is the line to keep if this ever stops invalidating.
        $firebase->forget();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // `route('home')`, not `/`: the bare domain only redirects again, and the
        // customer would lose the language they were reading in on the way.
        return redirect()->route('home');
    }
}

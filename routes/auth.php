<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\GoogleSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer accounts
|--------------------------------------------------------------------------
|
| Firebase Auth holds the accounts — the same Firebase project as the mobile app, so
| one account works on both (spec §9). This site keeps a mirror row in `users`, keyed
| by the Firebase UID, and no password of its own.
|
| Two routes that Breeze ships are deliberately absent:
|
|   `password.reset`  — Firebase emails the reset link and hosts the page that takes
|                       the new password. A reset form here could not change a
|                       credential that lives in Firebase.
|   `verification.verify` — same reasoning: Firebase's verification link is handled by
|                       Firebase, and `emailVerified` is copied down at sign-in.
|
| `password.confirm` is gone too. It re-checked a local password hash that no longer
| exists, and nothing on this site uses the `password.confirm` middleware.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    /*
     * Where the Firebase JS SDK posts the ID token it got from Google's popup. Both
     * the login and the register page submit to it — signing in and signing up with
     * Google are the same act, and Firebase decides which one it turns out to be.
     */
    Route::post('auth/google', [GoogleSessionController::class, 'store'])
        ->name('auth.google');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});

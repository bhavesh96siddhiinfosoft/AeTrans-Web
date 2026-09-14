<?php

use App\Http\Controllers\Booking\BookingDoneController;
use App\Http\Controllers\MapsKeyController;
use App\Http\Controllers\Booking\BookingHistoryController;
use App\Http\Controllers\Booking\CharterBookingController;
use App\Http\Controllers\Booking\CouponController;
use App\Http\Controllers\Booking\ShuttleBookingController;
use App\Http\Controllers\Booking\ShuttleStopsController;
use App\Http\Controllers\CmsPageController;
use App\Http\Controllers\Internal\BookingStatusNotificationController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The site
|--------------------------------------------------------------------------
|
| One URL per page, in every language. The language is carried by a cookie and set by
| the SetLocale middleware, which runs on the whole `web` group — see that class for
| what this choice costs in search visibility, and why it was made anyway.
|
| These paths were briefly prefixed with the locale (`/id/login`) per spec §3. The
| client asked on 2026-08-20 for the language to change without altering the address,
| so the prefix, the root redirect and the unprefixed-path fallback were all removed.
|
*/

/*
 * The landing page is the DEFAULT route and is fully public — a visitor is never
 * bounced to a sign-in screen to see what the business sells.
 */
Route::view('/', 'home')->name('home');

/*
 * Changing language. A POST, so nothing about it can appear in the address bar or be
 * followed by a crawler — see LanguageController.
 */
Route::post('/language', [LanguageController::class, 'update'])->name('language.update');

/*
|--------------------------------------------------------------------------
| Booking
|--------------------------------------------------------------------------
|
| A visitor with NO ACCOUNT can fill in every step. Only the review — the screen with
| the Confirm button on it — carries `auth`, so the sign-in is asked for once, at the
| last moment, and Laravel's `intended` redirect brings the customer straight back to
| it with the draft still in their session.
|
| This is the client's instruction of 2026-08-21 and it narrows spec §9, which allowed
| guest checkout outright. An order now always belongs to an account.
|
*/
Route::prefix('book')->name('book.')->group(function () {
    /*
     * Refreshing any step starts the booking over — the client's rule of 2026-08-21.
     * The browser detects the reload (the server cannot: a refresh is byte-for-byte the
     * same GET as a link) and sends the customer here. See CharterBookingController.
     */
    /*
     * A service the admin has switched off is not bookable, not merely unadvertised.
     * `/book/charter` is a URL that gets bookmarked and shared on WhatsApp, so hiding
     * the card and the nav link would still leave the flow open to anyone holding the
     * link — and the order it took would be one an operator has to ring somebody up to
     * cancel. 404 when it is off; see EnsureServiceIsOffered.
     */
    Route::middleware('service:charter')->group(function () {
        Route::get('/charter/restart', [CharterBookingController::class, 'restart'])->name('charter.restart');

        /*
         * Route, then vehicle and dates, then the customer. Reordered on 2026-08-26 to
         * follow the mobile app — the distance has to be known before a price can be shown,
         * and the price is what the customer came for.
         */
        Route::get('/charter', [CharterBookingController::class, 'trip'])->name('charter');
        Route::post('/charter', [CharterBookingController::class, 'saveTrip']);
        Route::get('/charter/vehicle', [CharterBookingController::class, 'vehicle'])->name('charter.vehicle');
        Route::post('/charter/vehicle', [CharterBookingController::class, 'saveVehicle']);

        /*
         * NOT behind `auth`, unlike the shuttle's review. A visitor reaches this step and
         * sees the summary, the price and a sign-in button where the details form would be;
         * the POST below is what actually requires an account. Middleware here would bounce
         * them to the login screen without ever showing them what they were signing in for.
         */
        Route::get('/charter/details', [CharterBookingController::class, 'details'])->name('charter.details');
        Route::post('/charter/details', [CharterBookingController::class, 'confirm'])->name('charter.confirm');
    });

    Route::middleware('service:airport-shuttle')->group(function () {
        Route::get('/shuttle/restart', [ShuttleBookingController::class, 'restart'])->name('shuttle.restart');

        /*
         * The stop list, for the route step's dropdown as it narrows by airport and city.
         * Catalogue only — what the business sells, already printed on the page — so it holds
         * nothing a booking document does. See the controller.
         */
        Route::get('/shuttle/stops', ShuttleStopsController::class)->name('shuttle.stops');

        Route::get('/shuttle', [ShuttleBookingController::class, 'route'])->name('shuttle');
        Route::post('/shuttle', [ShuttleBookingController::class, 'saveRoute']);
        Route::get('/shuttle/trip', [ShuttleBookingController::class, 'trip'])->name('shuttle.trip');
        Route::post('/shuttle/trip', [ShuttleBookingController::class, 'saveTrip']);

        /*
         * Not behind `auth`, for the same reason the charter details step is not: a visitor
         * sees the summary and the fare with a sign-in button where the form would be, and
         * the POST is what actually requires an account.
         */
        Route::get('/shuttle/details', [ShuttleBookingController::class, 'details'])->name('shuttle.details');
        Route::post('/shuttle/details', [ShuttleBookingController::class, 'confirm'])->name('shuttle.confirm');

        /*
         * A discount code, applied to the booking in progress and taken off again.
         *
         * Its own request rather than a field carried to Confirm: a customer types a code to
         * SEE what it does, and finding out it did not work by being charged the full amount
         * is not an answer. Open, like the rest of the steps — the code is checked again at
         * confirm, where there is a signed-in customer to check the per-person limit against.
         */
    });

    Route::post('/{type}/coupon', [CouponController::class, 'store'])
        ->whereIn('type', ['charter', 'shuttle'])
        ->name('coupon');

    // The gate. Everything above is open; nothing below is.
    Route::middleware('auth')->group(function () {
        Route::get('/done/{booking}', BookingDoneController::class)->name('done');
    });
});

/*
 * "My bookings".
 *
 * Breeze called this `/dashboard` and every one of its redirects pointed at that name.
 * It was renamed on 2026-08-26 because the page is not a dashboard and never was — it is
 * the customer's own orders, and the address bar should say so when they land there from
 * a sign-in or a confirmation screen.
 *
 * Not `verified`: a customer who has just booked and not yet opened their email must
 * still be able to find their reference and the bank details.
 */
Route::get('/my-bookings', BookingHistoryController::class)
    ->middleware('auth')
    ->name('bookings');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    // No `profile.destroy`: see ProfileController for why self-service deletion is not
    // a code change but a client decision about what happens to past bookings.
});

/*
|--------------------------------------------------------------------------
| Internal
|--------------------------------------------------------------------------
|
| Server-to-server, from the ADMIN PANEL. Not a page, and never reached by a browser:
| no session, no CSRF token, no `auth` — a shared secret in a header is the whole of
| the authentication. See the controller for what bounds a leak.
|
| Throttled hard. A status change is a human pressing Save; nothing legitimate needs
| this endpoint more than a few times a minute, and the limit is what stops a leaked
| secret being used to mail real customers in a loop.
|
*/
Route::post('/internal/booking-status', BookingStatusNotificationController::class)
    ->middleware('throttle:30,1')
    ->name('internal.booking-status');

/*
|--------------------------------------------------------------------------
| The Google Maps browser key
|--------------------------------------------------------------------------
|
| Handed to the page's JavaScript on request instead of being printed into the HTML
| — the client's instruction, 2026-09-08, so the key is not sitting in view-source or
| the Elements panel.
|
| WHAT THIS DOES AND DOES NOT DO, so nobody later mistakes it for a secret store: the
| key still reaches the browser, and is still visible in the Network tab. A Maps
| BROWSER key is public by design — it travels in the script URL of every site that
| draws a map. The only thing that limits its use is the HTTP-referrer restriction set
| on it in the Google Cloud console, which is a separate job and the one that matters.
|
| It is served from here rather than read out of Firestore by the browser (the admin
| panel's approach) for two reasons: the booking pages carry no Firebase handle and
| would have to load the Firestore SDK to fetch one string, and that read only works
| while `settings` is readable by anyone — which is the door the rules deployment is
| meant to close.
|
| Throttled because it is an unauthenticated GET, and cached briefly by the browser so
| a customer stepping through the booking flow asks once rather than once per screen.
|
*/
Route::get('/maps-key', MapsKeyController::class)
    ->middleware('throttle:60,1')
    ->name('maps.key');

require __DIR__.'/auth.php';

/*
|--------------------------------------------------------------------------
| CMS pages
|--------------------------------------------------------------------------
|
| LAST, and it must stay last. The pattern matches any path, so anything declared
| below it would never be reached — Laravel takes the first route that matches, not
| the most specific one.
|
| The address is the page's own `path` field in Firestore, which the admin controls:
| `/about-us` today, and `/legal/terms` the moment somebody nests one. Nothing here
| knows any slug by name, which is the point of the feature.
|
| A path with no page throws a 404 as usual — see CmsPageController for why this
| catch-all must not answer for the site's real misses.
|
*/
Route::get('/{path}', CmsPageController::class)
    ->where('path', '[A-Za-z0-9][A-Za-z0-9\-_/]*')
    ->name('cms.page');

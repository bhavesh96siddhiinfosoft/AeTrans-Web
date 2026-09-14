<?php

/*
|--------------------------------------------------------------------------
| Firebase
|--------------------------------------------------------------------------
|
| The same project the admin panel and the mobile app use, so a customer has one
| account across all three.
|
| The env KEY NAMES match the panel's exactly — including `FIREBASE_MESSAAGING_SENDER_ID`,
| which carries a typo. It is kept rather than corrected: both projects read the same
| .env values on the same server, and renaming it here would silently blank the sender
| id on whichever project was not updated. Fix it in both or in neither.
|
| `web` is the browser SDK config and is PUBLIC by design — it identifies the project,
| it does not authorise anything. What protects the data is the Firestore security
| rules, which are still open and must be tightened before this site is reachable.
|
*/

return [

    'web' => [
        'apiKey' => env('FIREBASE_APIKEY'),
        'authDomain' => env('FIREBASE_AUTH_DOMAIN'),
        'databaseURL' => env('FIREBASE_DATABASE_URL'),
        'projectId' => env('FIREBASE_PROJECT_ID'),
        'storageBucket' => env('FIREBASE_STORAGE_BUCKET'),
        'messagingSenderId' => env('FIREBASE_MESSAAGING_SENDER_ID'),
        'appId' => env('FIREBASE_APP_ID'),
        'measurementId' => env('FIREBASE_MEASUREMENT_ID'),
    ],

    /*
     * Server-side reads go through the Firestore REST API with a service-account
     * token — NOT the PHP SDK, because `grpc` is unavailable on this host. Nothing
     * reads this yet; it is here so the credentials path has one home when the
     * Firestore reader is built (see docs/website-spec.md §2).
     */
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/firebase/credentials.json')),

    /*
     * Seconds to wait on Firebase. Short on purpose: these calls sit inside a form
     * submission, and a customer staring at a spinner is worse than a retry.
     */
    'timeout' => (int) env('FIREBASE_HTTP_TIMEOUT', 10),

    /*
     * How long Global Settings are held before Firestore is asked again.
     *
     * Every page draws the logo, the site name and the footer from them, so without a
     * cache each visit would make a round trip to Google before it could render — the
     * opposite of the under-2.5s LCP the spec asks for. Five minutes is the compromise:
     * an admin's change appears without a deploy, and a busy hour costs twelve reads.
     */
    'settings_cache_seconds' => (int) env('FIREBASE_SETTINGS_CACHE_SECONDS', 300),

    /*
     * The `services` collection alone, held for much less.
     *
     * It is two documents, and one of the two fields on them is the switch that takes a
     * service off the website. An admin flips that switch and looks straight at the site
     * — so five minutes of "it is still there" reads as a broken feature, and was
     * reported as one on 2026-09-09 within a minute of the switch being flipped.
     *
     * Thirty seconds rather than zero: this is read on EVERY page to name the two
     * services in the header, and reading it live would put a Firestore round trip in
     * front of every render. Thirty seconds costs at most two reads a minute across all
     * visitors and puts the delay below the time it takes an admin to change tab.
     *
     * The rest of the catalogue — vehicle types, units, airports, city groups, shuttle
     * rates — keeps the longer life above. Those change weekly and are not switches
     * anybody watches.
     */
    'services_cache_seconds' => (int) env('FIREBASE_SERVICES_CACHE_SECONDS', 30),

    /*
     * Google sign-in.
     *
     * The real switch is Global Settings `web_google_login` in Firestore, and the site
     * now reads it — see SiteSettings::googleLoginEnabled(). This value is only the
     * fallback for a project where that setting has never been saved, and a local
     * override for turning the button off during development.
     *
     * No Google OAuth credential is read here — neither the client id nor the secret.
     * Both live in the Firebase console, under Authentication → Sign-in method →
     * Google, and Firebase performs the whole exchange; this site only ever sees the
     * resulting ID token.
     *
     * `GOOGLE_CLIENT_ID` is still in .env and is currently unused. It is what Google
     * Identity Services needs in the page, the way the Website Panel does it — kept
     * because switching back to that button would need it again, and because an
     * unexplained empty key invites someone to delete the wrong one.
     */
    'google_login' => filter_var(env('FIREBASE_GOOGLE_LOGIN', true), FILTER_VALIDATE_BOOLEAN),

    'profile' => [

        // The Firestore collection holding customers, shared with the panel and app.
        'collection' => env('FIREBASE_USERS_COLLECTION', 'users'),

        /*
         * Whether a sign-in writes the customer's profile document to Firestore.
         *
         * ON: without it, a customer who registers on the website never appears in the
         * admin panel's customer list. The field names are the panel's own — see
         * CustomerAccounts::writeProfileDocument().
         */
        'sync' => filter_var(env('FIREBASE_SYNC_PROFILE', true), FILTER_VALIDATE_BOOLEAN),

        /*
         * What to do when the `blocked` check cannot be made. Default is to allow the
         * sign-in and log it; true refuses instead. See CustomerAccounts.
         */
        'block_check_strict' => filter_var(env('FIREBASE_BLOCK_CHECK_STRICT', false), FILTER_VALIDATE_BOOLEAN),

    ],

];

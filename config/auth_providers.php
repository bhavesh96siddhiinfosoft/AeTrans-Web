<?php

/*
|--------------------------------------------------------------------------
| Sign-in providers
|--------------------------------------------------------------------------
|
| How a customer account signs in. The KEY is what is stored in `users.provider`
| here and in the Firestore `users` document, so changing a key orphans every
| existing account that holds the old value — relabel freely, rekey never.
|
| The keys are FIREBASE'S OWN provider ids, not words of our own. This file used to
| key on `email` / `google`; the admin panel keys on `password` / `google.com` /
| `apple.com` / `phone` (its mapping is in resources/views/users/_actions.blade.php,
| `window.providerLabel`), and the panel is the authority. Two vocabularies for one
| shared field means the panel prints a raw `email` where it expects `password`, and
| the website prints a raw `google.com` where it expects `google` — each side looking
| correct on its own screen and wrong on the other's.
|
| `apple.com` is listed because the panel labels it and Firebase may already hold
| accounts using it from the mobile app. Nothing on this site OFFERS Apple sign-in —
| `settings.web_apple_login` exists but nothing implements it — so an Apple customer
| can arrive here, be labelled correctly, and simply have no Apple button to press.
|
| `google.com` is switched on from Global Settings (`web_google_login`).
|
*/

return [

    'providers' => [

        'password' => [
            // Firebase's own word for email + password.
            'label' => 'Email & password',
            // The only provider with a password to change — and it lives in Firebase,
            // not in our `users` table. See Auth\PasswordController.
            'has_password' => true,
        ],

        'phone' => [
            'label' => 'Phone',
            // Firebase sends the OTP; there is no password involved.
            'has_password' => false,
        ],

        'google.com' => [
            'label' => 'Google',
            'has_password' => false,
        ],

        'apple.com' => [
            'label' => 'Apple',
            'has_password' => false,
        ],

    ],

    'default' => 'password',

];

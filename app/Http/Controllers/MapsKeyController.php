<?php

namespace App\Http\Controllers;

use App\Services\Site\SiteSettings;
use Illuminate\Http\JsonResponse;

/**
 * The Google Maps browser key, fetched by the page rather than printed into it.
 *
 * The client asked on 2026-09-08 that the key not appear in view-source or the Elements
 * panel. It used to be rendered as a `<script type="application/json">` block in
 * `partials/google-maps.blade.php`; `google-maps-loader.js` now asks for it here.
 *
 * ── WHAT THIS IS NOT ────────────────────────────────────────────────────────
 *
 * It is NOT a way of keeping the key secret, and must not be relied on as one. The key
 * still reaches the browser — it has to, because the Maps JS API runs there — and it is
 * plainly visible in the Network tab and in the script URL the loader builds. Every site
 * that draws a Google map ships its browser key this way.
 *
 * What actually limits the key is the HTTP-referrer and API restrictions set on it in
 * the Google Cloud console. As of 2026-09-08 this key had NEITHER: an unauthenticated
 * server-side Geocoding request carrying no referer at all returned live results, which
 * means anyone holding the key can spend the client's Google billing. Setting those
 * restrictions is the real fix and is still outstanding.
 *
 * ── WHY NOT READ IT FROM FIRESTORE IN THE BROWSER ───────────────────────────
 *
 * The admin panel does that, and it can: an operator is signed in. Copying it here would
 * mean loading the Firestore SDK onto booking pages that carry no Firebase handle today,
 * and it would only work while the `settings` collection is readable by anyone — which
 * is exactly the door the pending Firestore rules deployment is meant to shut.
 */
class MapsKeyController extends Controller
{
    public function __invoke(SiteSettings $site): JsonResponse
    {
        $key = $site->mapKey();

        return response()
            ->json([
                // An empty key is a valid answer, not an error: the address fields fall
                // back to plain text boxes and the booking still completes. The loader
                // rejects on it, which is what every caller already handles.
                'key' => $key ?? '',
                'region' => $site->regionCode() ?? '',
                /*
                 * Where the picker opens when the field it was launched from has no
                 * coordinates yet (client, 2026-09-08). Sent with the key because the
                 * page already makes this one request and the picker needs both before
                 * it can draw anything; it is configuration, not a secret.
                 */
                'defaultPlace' => array_filter([
                    'address' => (string) config('bookings.default_address.address', ''),
                    'lat' => (string) config('bookings.default_address.lat', ''),
                    'lng' => (string) config('bookings.default_address.lng', ''),
                ], fn (string $value) => $value !== ''),
            ])
            /*
             * A short private cache. A customer stepping through four booking screens
             * would otherwise ask four times for a string that changes about never, and
             * `SiteSettings` is only cached on the SERVER — the round trip is the cost
             * being saved here, not the Firestore read.
             *
             * Private, so a shared proxy never holds the client's key for someone else.
             */
            ->header('Cache-Control', 'private, max-age=300');
    }
}

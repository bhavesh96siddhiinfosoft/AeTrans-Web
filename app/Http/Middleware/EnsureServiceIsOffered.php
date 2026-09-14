<?php

namespace App\Http\Middleware;

use App\Services\Site\SiteServices;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a booking flow for a service the admin has switched off.
 *
 * Hiding the card and the nav link is not enough on its own. `/book/charter` is a URL a
 * customer may have bookmarked, been sent on WhatsApp, or reached from an old email —
 * and a booking taken for a withdrawn service is one an operator has to phone somebody
 * up to cancel. The panel's Services screen promises the switch takes the service off
 * the website; that has to include the part of the website that sells it.
 *
 * A 404 rather than a redirect with a message, because that is what the URL now is:
 * nothing. There is no page explaining that a service used to exist here, and inventing
 * one would need copy in three languages to say something no customer asked about.
 *
 * `SiteServices::offers()` answers TRUE when Firestore cannot be read, so an outage
 * does not close the shop — see the reasoning there.
 */
class EnsureServiceIsOffered
{
    public function __construct(private readonly SiteServices $services) {}

    public function handle(Request $request, Closure $next, string $slug): Response
    {
        abort_unless($this->services->offers($slug), 404);

        return $next($request);
    }
}

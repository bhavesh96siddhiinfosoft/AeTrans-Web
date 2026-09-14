<?php

namespace App\Services\Site;

/**
 * What the admin panel says about the two services: what they are CALLED, and whether
 * they are still SOLD.
 *
 * The admin names the two services on the panel's Services screen, and that screen tells
 * them the name is "shown in each enabled language". Until 2026-09-07 the website ignored
 * it completely: "Rental + Driver" and "Travel Shuttle" were baked into nineteen views,
 * two mailables and the language files, so an operator renaming a service in the panel
 * changed nothing a customer could see. The panel is the authority for what the business
 * calls the things it sells.
 *
 * ── WHY THE LANGUAGE FILES ARE STILL HERE ───────────────────────────────────
 *
 * `lang.rental_driver` / `lang.travel_shuttle` are the FALLBACK, not dead weight:
 *
 *   - the `services` document may be missing, unnamed, or named only with spaces;
 *   - Firestore may be unreachable, and these names sit in the header and footer of
 *     EVERY page. A read that throws here would take the whole site down over a nav
 *     link, so `for()` swallows the failure and answers with the site's own wording.
 *
 * A page rendered during an outage therefore still says "Travel Shuttle" rather than
 * nothing at all, which is the right way round.
 *
 * ── WHERE THIS DOES *NOT* APPLY ─────────────────────────────────────────────
 *
 * Only where the service is NAMED — nav links, card headings, page titles, the service
 * row on a booking summary or an email. Prose that happens to mention a charter or a
 * shuttle stays in the language files: the home page's lede and the service blurbs are
 * sentences a translator wrote, not labels, and pushing an admin's name through them
 * ("A seat on a scheduled Travel Juanda & Tj Perak…") would read as a mistake.
 */
class SiteServices
{
    /** @var array<string, string> Resolved once per request, per locale. */
    private array $memo = [];

    public function __construct(private readonly Catalog $catalog) {}

    public function charter(): string
    {
        return $this->for('charter');
    }

    public function shuttle(): string
    {
        return $this->for('airport-shuttle');
    }

    /** The booking-type flag the views and mailables already carry, as a name. */
    public function label(bool $isCharter): string
    {
        return $isCharter ? $this->charter() : $this->shuttle();
    }

    /**
     * Is this service still on sale?
     *
     * The panel's Services screen says, in as many words, "switch one off to take it off
     * the website" — and until 2026-09-09 the website did no such thing. A switched-off
     * service kept its card on the home page, its link in the header and footer, and a
     * working booking flow.
     *
     * ── WHY IT LOOKED LIKE IT WAS WORKING ───────────────────────────────────
     *
     * `Catalog::service()` filters on `enable`, so a disabled service comes back as
     * null — and `for()` below reads null as "the panel has no name for this" and falls
     * back to the site's own wording. The fallback that exists for a Firestore outage
     * was printing a perfectly ordinary "Rental + Driver" over a service the admin had
     * just turned off. Nothing looked broken, which is why it survived a whole day of
     * being reported.
     *
     * ── DISABLED AND UNREADABLE ARE NOT THE SAME THING ──────────────────────
     *
     * They have to be told apart, and it is harder than it looks.
     * `Firestore::collection()` answers an unreachable Google with an EMPTY ARRAY rather
     * than an exception, so "the admin switched this off" and "we could not read the
     * catalogue" both arrive here as `service()` returning null. The first pass caught
     * exceptions and was simply wrong; the site's own outage tests failed it, which is
     * what those tests are for.
     *
     * So the question asked first is whether the `services` collection came back with
     * ANYTHING — switched on or off. If it did not, we cannot tell, and the answer is
     * **true**: keep selling. Hiding both services over a bad second at Google would
     * turn a brief outage into a shut shop, and the booking flow re-reads the catalogue
     * at every step, so it cannot complete an order for a service that is genuinely
     * gone.
     *
     * The panel calls both services core and un-removable, so an empty `services`
     * collection is never a real state — only a failed read looks like that.
     */
    public function offers(string $slug): bool
    {
        try {
            if (! $this->catalog->published('services')) {
                return true;
            }

            return $this->catalog->service($slug) !== null;
        } catch (\Throwable) {
            return true;
        }
    }

    public function offersCharter(): bool
    {
        return $this->offers('charter');
    }

    public function offersShuttle(): bool
    {
        return $this->offers('airport-shuttle');
    }

    /** True when the admin has switched BOTH off, which the home page has to survive. */
    public function offersNothing(): bool
    {
        return ! $this->offersCharter() && ! $this->offersShuttle();
    }

    public function for(string $slug): string
    {
        $key = $slug.'.'.app()->getLocale();

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $fallback = $this->fallback($slug);

        try {
            $name = $this->catalog->localised($this->catalog->service($slug)['name'] ?? '');
        } catch (\Throwable) {
            /*
             * Deliberately silent, and deliberately not cached as a failure: the header
             * renders on every page including the error pages, so the one thing this
             * must never do is turn a Firestore hiccup into a broken site. The next
             * request tries again.
             */
            return $fallback;
        }

        return $this->memo[$key] = $name !== '' ? $name : $fallback;
    }

    /**
     * The site's own wording, for when the panel has none.
     *
     * Written as literal `__('lang.…')` calls on purpose. A map of slug to key string
     * would read more tidily and would be INVISIBLE to
     * `TranslationCoverageTest::keysUsed()`, which scans the source for that exact call
     * shape — the two lines would have been reported as dead and deleted by someone
     * tidying up, taking the outage fallback with them.
     *
     * The slugs are the panel's (`charter`, `airport-shuttle`), not the website's
     * internal booking type (`charter`, `shuttle`) — which is why `label()` exists,
     * rather than callers passing whichever of the two they happen to be holding.
     */
    private function fallback(string $slug): string
    {
        return match ($slug) {
            'charter' => (string) __('lang.rental_driver'),
            'airport-shuttle' => (string) __('lang.travel_shuttle'),
            default => $slug,
        };
    }
}

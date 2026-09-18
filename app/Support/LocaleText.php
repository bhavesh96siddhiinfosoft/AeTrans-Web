<?php

namespace App\Support;

/**
 * A value the panel writes in more than one language.
 *
 * The panel stores translated text as a LOCALE MAP — `{"en": "About Us", "id":
 * "Tentang Kami"}` — and everything written before it did so is a plain string. Both
 * shapes are live and will stay live, so both are read here, in one place, rather than
 * in each of the three services that needs it.
 *
 * `services.name` and the banner copy have used maps for a while; `cms_pages` joined
 * them on 2026-09-18, which is what pulled this out of `Catalog`.
 */
class LocaleText
{
    /**
     * The text in the reader's language, or the nearest thing to it.
     *
     * The order — this locale, then ANY language that has words — is deliberate.
     * Something the admin typed in one language only must still appear everywhere,
     * because the point of writing it in the panel is that it shows on the site. A
     * caller wanting the site's own translated wording instead handles the empty case
     * itself; see `SiteServices`.
     *
     * AN EMPTY VALUE COUNTS AS ABSENT. `{"en": "About Us", "id": ""}` is a page nobody
     * has translated yet, not a page with no Indonesian heading, and returning the
     * empty string would print a blank where the English belongs. The panel now omits
     * a language rather than writing it empty, but documents written before it did are
     * still out there.
     */
    public static function pick(mixed $value, ?string $locale = null): string
    {
        if (! is_array($value)) {
            return trim((string) $value);
        }

        $locale ??= app()->getLocale();
        $wanted = trim((string) ($value[$locale] ?? ''));

        if ($wanted !== '') {
            return $wanted;
        }

        foreach ($value as $candidate) {
            // Nested arrays are not text. Skipping rather than stringifying them keeps
            // a malformed document from rendering "Array" on a live page.
            if (is_array($candidate)) {
                continue;
            }

            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}

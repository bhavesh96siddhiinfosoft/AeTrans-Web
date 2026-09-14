<?php

namespace App\Mail;

use App\Services\Site\Languages;
use App\Services\Site\SiteServices;
use App\Services\Site\SiteSettings;
use Illuminate\Mail\Mailable;

/**
 * What every email this site sends has in common.
 *
 * The brand — logo, name, contact details, bank account — lives in Global Settings in
 * Firestore, which is the same place the website's own header and footer read it from.
 * An email that hardcoded any of it would drift the day the admin changed a phone
 * number, and would do it silently, because nobody reads their own transactional mail.
 *
 * `$site` and `$languages` are resolved here rather than injected, because a Mailable is
 * serialised when queued and a live service with a Guzzle client inside it does not
 * survive that. Both are cached singletons, so this costs nothing.
 */
abstract class BrandedMail extends Mailable
{
    protected function brand(): array
    {
        return [
            'site' => app(SiteSettings::class),
            'languages' => app(Languages::class),
            /*
             * The admin's own name for each service, for the same reason as the rest of
             * the brand: an email calling it something the panel no longer calls it is a
             * drift nobody notices, because nobody reads their own transactional mail.
             */
            'services' => app(SiteServices::class),
        ];
    }

    /**
     * A WhatsApp deep link to the operator, or null when no number is configured.
     *
     * `wa.me` takes digits only: a space or a dash in the href makes the link dead on
     * some Android dialers, which is the same rule the site's footer follows.
     */
    protected function whatsappLink(?string $reference = null): ?string
    {
        $number = app(SiteSettings::class)->whatsapp();

        if (! $number) {
            return null;
        }

        $link = 'https://wa.me/'.ltrim(preg_replace('/[^0-9+]/', '', $number), '+');

        return $reference
            ? $link.'?text='.urlencode(__('lang.whatsapp_booking_message', ['reference' => $reference]))
            : $link;
    }

    /**
     * The name to greet somebody by.
     *
     * First word only — "Budi", not "Budi Santoso Wijaya" — and the whole string when
     * there is only one word. An empty name falls back to a greeting with no name in it
     * rather than to "Hello ,".
     */
    protected function firstName(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : (string) explode(' ', $name)[0];
    }
}

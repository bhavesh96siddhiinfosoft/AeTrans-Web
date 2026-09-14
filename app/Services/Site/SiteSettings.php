<?php

namespace App\Services\Site;

use App\Services\Firebase\Firestore;
use Illuminate\Support\Facades\Cache;

/**
 * Global Settings, as the admin panel writes them.
 *
 * The panel's field map, copied from its own settings screen
 * (aetranse-id/resources/views/settings/global.blade.php) rather than from the website
 * spec — where the two disagree the panel is right, and they DO disagree here: the
 * spec calls the maintenance flag `settings.maintenance_customer_web`; the panel keeps
 * it at `settings/maintenance_settings.customerWeb`.
 *
 *   settings/globalValue           web_site_name, web_tagline, web_meta_title,
 *                                  web_meta_description, web_meta_keywords,
 *                                  web_play_store_url, web_logo, web_logo_dark,
 *                                  web_favicon, web_footer_logo, web_og_image,
 *                                  web_google_login, web_apple_login
 *   settings/logo                  appLogo, appFavIconLogo   (the MOBILE APP's marks)
 *   settings/contact_us            subject, email, phone, whatsapp, address, supportURL
 *   settings/socialLinks           whatsapp, facebook, tiktok, telegram
 *   settings/maintenance_settings  customerWeb
 *
 * The image fields hold Firebase Storage download URLs, not file names — they are
 * printed into `src` as they stand.
 *
 * Everything is read in ONE call (the whole `settings` collection is a handful of
 * documents) and cached, because the header, the footer and the meta tags all want it
 * and a page must not make five round trips to Google to draw its own logo.
 */
class SiteSettings
{
    private const CACHE_KEY = 'site.settings';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $documents = null;

    public function __construct(
        private readonly Firestore $firestore,
        private readonly int $cacheSeconds = 300,
    ) {}

    /**
     * Drops the cached copy — for after an admin changes something, and for tests.
     */
    public function forget(): void
    {
        $this->documents = null;

        Cache::forget(self::CACHE_KEY);
    }

    // ---- Branding -------------------------------------------------------------

    /** The site's name. Falls back to APP_NAME so a heading is never blank. */
    public function siteName(): string
    {
        return $this->string('globalValue', 'web_site_name')
            ?: (string) config('app.name', 'Aetranse');
    }

    public function tagline(): ?string
    {
        return $this->string('globalValue', 'web_tagline');
    }

    /**
     * The header logo. Null means the admin has not uploaded one, and the header
     * prints the site's name as words instead — which is a reasonable logo.
     */
    public function logo(): ?string
    {
        return $this->string('globalValue', 'web_logo');
    }

    /**
     * The dark-mode logo, if a separate one was uploaded.
     *
     * Null is not a fault: one mark often works on both grounds. The header shows the
     * light one in that case rather than hiding the logo.
     */
    public function logoDark(): ?string
    {
        return $this->string('globalValue', 'web_logo_dark');
    }

    /** The footer's own mark. Falls back to the header logo. */
    public function footerLogo(): ?string
    {
        return $this->string('globalValue', 'web_footer_logo') ?: $this->logo();
    }

    public function favicon(): ?string
    {
        return $this->string('globalValue', 'web_favicon');
    }

    // ---- SEO ------------------------------------------------------------------

    /**
     * The meta title. Falls back to the site name — never to a blank tag, which is
     * worse than a plain one: a search result with no title is not clicked.
     */
    public function metaTitle(): string
    {
        return $this->string('globalValue', 'web_meta_title') ?: $this->siteName();
    }

    public function metaDescription(): ?string
    {
        return $this->string('globalValue', 'web_meta_description') ?: $this->tagline();
    }

    public function metaKeywords(): ?string
    {
        return $this->string('globalValue', 'web_meta_keywords');
    }

    /**
     * The image a shared link shows. Falls back to the logo, because a WhatsApp card
     * with no picture is the flat grey one nobody taps.
     */
    public function ogImage(): ?string
    {
        return $this->string('globalValue', 'web_og_image') ?: $this->logo();
    }

    // ---- Contact and social ---------------------------------------------------

    public function email(): ?string
    {
        return $this->string('contact_us', 'email');
    }

    public function phone(): ?string
    {
        return $this->string('contact_us', 'phone');
    }

    /**
     * The WhatsApp number from the contact block — the one that drives a chat link.
     *
     * NOT `socialLinks.whatsapp`, which is a URL for the social icon row. The panel
     * keeps them apart deliberately and says so on its settings screen.
     */
    public function whatsapp(): ?string
    {
        return $this->string('contact_us', 'whatsapp');
    }

    public function address(): ?string
    {
        return $this->string('contact_us', 'address');
    }

    public function supportUrl(): ?string
    {
        return $this->string('contact_us', 'supportURL');
    }

    /**
     * The Play Store link, or null when there is nothing real to link to.
     *
     * A bare `#` is filtered out because that is what the field actually holds today —
     * a placeholder saved to get past the form. Rendering it would put a button on
     * every page that reloads the page.
     */
    public function playStoreUrl(): ?string
    {
        $url = $this->string('globalValue', 'web_play_store_url');

        return $url === '#' ? null : $url;
    }

    /**
     * Social links the admin actually filled in, in a fixed order.
     *
     * Blank entries are dropped rather than rendered as dead icons — an icon that goes
     * nowhere is worse than an icon that is not there.
     *
     * @return array<string, string> platform => url
     */
    public function socialLinks(): array
    {
        return collect(['whatsapp', 'facebook', 'tiktok', 'telegram'])
            ->mapWithKeys(fn (string $key) => [$key => $this->string('socialLinks', $key)])
            ->filter()
            ->all();
    }

    // ---- Maps -----------------------------------------------------------------

    /**
     * The Google Maps browser key, from `settings/mapSettings.mapAPIkey`.
     *
     * Read on the SERVER and printed into the page, where the admin panel reads it in
     * the browser through the Firebase SDK. The panel can do that because an operator
     * is signed in; a visitor here is not, and giving the public site a Firestore
     * handle just to fetch one string would be a much larger door than the string is
     * worth.
     *
     * A Maps browser key is public by nature — it travels in the script URL of every
     * site that uses one. What limits it is the HTTP-referrer restriction set on the
     * key in the Google Cloud console, which is where the client's domains must be
     * listed before launch.
     */
    public function mapKey(): ?string
    {
        return $this->string('mapSettings', 'mapAPIkey');
    }

    /**
     * The country the site trades in — `ID`. Biases the address typeahead so that
     * "Ngawi" offers the town in East Java rather than a street on another continent.
     */
    public function regionCode(): ?string
    {
        return $this->string('globalValue', 'regionCode');
    }

    // ---- Switches -------------------------------------------------------------

    /**
     * Whether the whole customer site is switched off.
     *
     * `settings/maintenance_settings.customerWeb` — the panel's name for it.
     */
    public function inMaintenance(): bool
    {
        return $this->bool('maintenance_settings', 'customerWeb');
    }

    /**
     * Whether to offer Google sign-in.
     *
     * This is the REAL switch — the admin's own — and it now outranks the
     * FIREBASE_GOOGLE_LOGIN env value, which stood in only while nothing could read
     * Firestore. The env value survives as a local override for when the setting has
     * never been saved.
     */
    public function googleLoginEnabled(): bool
    {
        $documents = $this->all();

        if (! array_key_exists('web_google_login', $documents['globalValue'] ?? [])) {
            return (bool) config('firebase.google_login');
        }

        return $this->bool('globalValue', 'web_google_login');
    }

    /**
     * The account a customer transfers the fare to.
     *
     * The client's answer to how a booking is paid for, seen in the app on 2026-08-26:
     * the order is taken, then the customer transfers and sends the proof on WhatsApp.
     * There is no gateway, and this is what stands in for one.
     *
     * Only the fields that are filled in come back, so the page prints an account with a
     * branch and one without it equally well — and an unconfigured `bankAccountDetails`
     * document yields an empty array rather than a card of blank rows.
     *
     * @return array<string, string>
     */
    public function bankAccount(): array
    {
        $fields = ['bankName', 'holderName', 'accountNumber', 'bankCode', 'branchName', 'swiftCode'];
        $account = [];

        foreach ($fields as $field) {
            if ($value = $this->string('bankAccountDetails', $field)) {
                $account[$field] = $value;
            }
        }

        return $account;
    }

    // ---- Reading --------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->documents !== null) {
            return $this->documents;
        }

        return $this->documents = Cache::remember(
            self::CACHE_KEY,
            $this->cacheSeconds,
            fn () => $this->firestore->collection('settings'),
        );
    }

    private function string(string $document, string $field): ?string
    {
        $value = $this->all()[$document][$field] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function bool(string $document, string $field): bool
    {
        return ($this->all()[$document][$field] ?? null) === true;
    }
}

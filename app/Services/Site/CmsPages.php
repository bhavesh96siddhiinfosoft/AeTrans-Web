<?php

namespace App\Services\Site;

use App\Services\Firebase\Firestore;
use App\Support\LocaleText;
use Illuminate\Support\Facades\Cache;

/**
 * The pages the admin writes in the panel's CMS, served on the website.
 *
 * `cms_pages` in Firestore. Two documents live today, `about-us` and `contact-us`, and
 * the admin can add more without anybody touching this repository — which is the whole
 * point of the feature and the reason nothing here is hardcoded to those two slugs.
 *
 * ── THE FIELDS, READ OFF THE LIVE DATA ──────────────────────────────────────
 *
 *   name              the heading and the menu label — a LOCALE MAP
 *   slug              the admin's handle for the page
 *   path              WHAT THE URL IS — `about-us`, and nested pages would be `a/b`
 *   description       the body: rich-text HTML from the panel's editor — a LOCALE MAP
 *   excerpt           one-line summary, meta description fallback — a LOCALE MAP
 *   status/publish    both must say published before a visitor sees it
 *   menuOrder         where it sits in the footer
 *   parentId          nesting; null on both live documents
 *   featuredImage     used for Open Graph when the page's own SEO gives no image
 *   seo               a nested map — see `seo()` below
 *
 * `path` is the address and `slug` is not. They are equal on both live documents, which
 * is exactly the sort of coincidence that hides a bug for a month: the first nested page
 * the admin creates would have `slug: "terms"` and `path: "legal/terms"`.
 *
 * ── WHAT "PUBLISHED" MEANS ──────────────────────────────────────────────────
 *
 * BOTH `status === 'publish'` and `publish === true`. The panel writes the two together
 * and the website trusts neither alone: a draft leaking onto a public URL is the one
 * failure here that cannot be taken back, and requiring both means a half-written
 * document is invisible rather than live.
 *
 * ── LANGUAGES ───────────────────────────────────────────────────────────────
 *
 * ONE PAGE IS ONE DOCUMENT, and the words inside it are LOCALE MAPS:
 * `{"en": "About Us", "id": "Tentang Kami"}`. The client's decision of 2026-09-18,
 * and the shape `services.name` and the banner copy have used all along — which is
 * why `App\Support\LocaleText` reads all three.
 *
 * So one page has ONE URL, and switching language swaps the words at that address
 * rather than moving the reader. A language the admin has not written falls back to
 * one that has words, because a missing translation should show the original rather
 * than a blank page — and a blank page is what an empty heading would be.
 *
 * Everything leaves this class ALREADY IN THE READER'S LANGUAGE. The views take a
 * page and print it; none of them knows a locale map exists, and none of them should
 * have to.
 *
 * (Briefly, in September 2026, each language was its own document tied by a
 * `translationGroup`. Both fields are ignored here now, and the panel removes them
 * from a document the first time it is saved.)
 */
class CmsPages
{
    private const CACHE_KEY = 'site.cms-pages';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $pages = null;

    public function __construct(
        private readonly Firestore $firestore,
        private readonly int $cacheSeconds = 300,
    ) {}

    public function forget(): void
    {
        $this->pages = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The page at one URL path, in the reader's language, or null.
     *
     * @return array<string, mixed>|null
     */
    public function atPath(string $path, ?string $locale = null): ?array
    {
        $path = $this->normalise($path);

        if ($path === '') {
            return null;
        }

        $match = null;

        foreach ($this->published() as $page) {
            if ($this->normalise((string) ($page['path'] ?? '')) === $path) {
                $match = $page;

                break;
            }
        }

        if ($match === null) {
            return null;
        }

        /*
         * The address named the page; the reader's language decides which WORDS come
         * back. A visitor reading in Indonesian who follows an English link to
         * `/about-us` gets the Indonesian text at that same address, because the site
         * has one URL per page and the language lives in a cookie.
         */
        return $this->localise($match, $locale ?? app()->getLocale());
    }

    /**
     * The published pages for the footer menu, in the admin's own order.
     *
     * One entry per page, already in the reader's language.
     *
     * @return array<int, array<string, mixed>>
     */
    public function menu(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        $menu = array_map(
            fn (array $page) => $this->localise($page, $locale),
            $this->published(),
        );

        usort($menu, function (array $a, array $b) {
            // `menuOrder` first, then the name, so two pages the admin left at 0 come
            // out in a stable order rather than Firestore's.
            return [(int) ($a['menuOrder'] ?? 0), (string) ($a['name'] ?? '')]
                <=> [(int) ($b['menuOrder'] ?? 0), (string) ($b['name'] ?? '')];
        });

        return $menu;
    }

    /**
     * One page's SEO block.
     *
     * Already in the reader's language: the page came through `localise()`, which
     * flattens the words inside `seo` along with the rest. `partials/seo.blade.php`
     * therefore reads plain strings and never learns that a locale map exists.
     *
     * The panel saves every field whether or not it was filled in, so `title: ""` means
     * "not set" and not "an empty title" — which is why that partial treats an empty
     * string as absent and falls through to the site default.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    public function seo(array $page): array
    {
        $seo = $page['seo'] ?? [];

        return is_array($seo) ? $seo : [];
    }

    // ---- Reading --------------------------------------------------------------

    /**
     * Every page a visitor is allowed to see.
     *
     * @return array<int, array<string, mixed>>
     */
    private function published(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $page) => ($page['status'] ?? null) === 'publish' && ($page['publish'] ?? false) === true,
        ));
    }

    /**
     * One page with its words resolved to the reader's language.
     *
     * The ONE place a locale map becomes a string, so every view downstream — the page
     * itself, the footer link, the `<head>` — prints what it is given.
     *
     * A language the admin has not written falls back to one that has words rather
     * than coming back empty. That is deliberate: a site with an English About page
     * and no Indonesian one should show the English text to an Indonesian reader, not
     * a blank heading over an empty body. The gap stays visible to visitors and to the
     * admin, which is how it gets filled.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function localise(array $page, string $locale): array
    {
        foreach (['name', 'excerpt', 'description'] as $field) {
            $page[$field] = LocaleText::pick($page[$field] ?? '', $locale);
        }

        $seo = $page['seo'] ?? [];

        if (is_array($seo)) {
            foreach (['title', 'metaDescription', 'focusKeyphrase', 'keywords', 'breadcrumbTitle'] as $field) {
                if (array_key_exists($field, $seo)) {
                    $seo[$field] = LocaleText::pick($seo[$field], $locale);
                }
            }

            /*
             * The social block, one level down. `image` and `type` are not words and
             * are left exactly as they are — running a URL through the picker would
             * work by accident today and mangle it the day somebody stores a map.
             */
            foreach (['openGraph', 'twitter'] as $block) {
                if (! is_array($seo[$block] ?? null)) {
                    continue;
                }

                foreach (['title', 'description', 'imageAlt'] as $field) {
                    if (array_key_exists($field, $seo[$block])) {
                        $seo[$block][$field] = LocaleText::pick($seo[$block][$field], $locale);
                    }
                }
            }

            $page['seo'] = $seo;
        }

        return $page;
    }

    /** Leading and trailing slashes off, so `/about-us/` and `about-us` are one page. */
    private function normalise(string $path): string
    {
        return trim($path, '/');
    }

    /**
     * The whole collection, briefly cached.
     *
     * Read whole rather than queried by path: it is a handful of documents, a query on
     * `path` would need an index somebody has to create in the console first, and the
     * footer menu needs all of them on every page anyway.
     *
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }

        return $this->pages = Cache::remember(
            self::CACHE_KEY,
            $this->cacheSeconds,
            fn () => $this->firestore->collection('cms_pages'),
        );
    }
}

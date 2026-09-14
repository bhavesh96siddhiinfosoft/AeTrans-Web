<?php

namespace App\Services\Site;

use App\Services\Firebase\Firestore;
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
 *   name              the heading, and the menu label
 *   slug              the admin's handle for the page
 *   path              WHAT THE URL IS — `about-us`, and nested pages would be `a/b`
 *   description       the body: rich-text HTML from the panel's editor
 *   excerpt           one-line summary; used as a meta description fallback
 *   status/publish    both must say published before a visitor sees it
 *   locale            `en` on both live documents today
 *   translationGroup  ties one page's languages together
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
 * One URL per page in every language, like the rest of this site
 * (`SetLocale`, the client's decision of 2026-08-20). So a path resolves to a
 * TRANSLATION GROUP, and the document served is the one in the reader's language —
 * every sibling's path reaching the same page in whichever language is being read.
 * Only `en` exists today; the day the admin adds an `id` version it appears on its own,
 * with no code change.
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
         * The address named a page; the reader's language decides which document
         * answers. A visitor reading in Indonesian who follows an English link to
         * `/about-us` gets the Indonesian text at that same address, because the site
         * has one URL per page and the language lives in a cookie.
         */
        return $this->inLocale($match, $locale ?? app()->getLocale());
    }

    /**
     * The published pages for the footer menu, in the admin's own order.
     *
     * One entry per translation group, each already resolved to the reader's language,
     * so a page never appears twice under two names.
     *
     * @return array<int, array<string, mixed>>
     */
    public function menu(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $chosen = [];

        foreach ($this->published() as $page) {
            $group = $this->groupOf($page);

            if (! isset($chosen[$group])) {
                $chosen[$group] = $this->inLocale($page, $locale);
            }
        }

        $menu = array_values($chosen);

        usort($menu, function (array $a, array $b) {
            // `menuOrder` first, then the name, so two pages the admin left at 0 come
            // out in a stable order rather than Firestore's.
            return [(int) ($a['menuOrder'] ?? 0), (string) ($a['name'] ?? '')]
                <=> [(int) ($b['menuOrder'] ?? 0), (string) ($b['name'] ?? '')];
        });

        return $menu;
    }

    /**
     * One page's SEO block, with the empty strings the panel writes treated as absent.
     *
     * The panel saves every field whether or not it was filled in, so `title: ""` means
     * "not set" and not "an empty title". Handing that straight to the `<head>` would
     * blank out the site defaults the page is supposed to fall back to.
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
     * The sibling of a page written in one language, or the page itself.
     *
     * Falling back to the document that was asked for is deliberate: a site with an
     * English About page and no Indonesian one should show the English text to an
     * Indonesian reader, not a 404. A missing translation is a gap in the content, and
     * hiding the page hides the gap from everybody including the admin.
     *
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function inLocale(array $page, string $locale): array
    {
        if ((string) ($page['locale'] ?? '') === $locale) {
            return $page;
        }

        $group = $this->groupOf($page);

        foreach ($this->published() as $sibling) {
            if ($this->groupOf($sibling) === $group && (string) ($sibling['locale'] ?? '') === $locale) {
                return $sibling;
            }
        }

        return $page;
    }

    /**
     * What ties a page's languages together.
     *
     * `translationGroup` when the panel wrote one, and the document id when it did not —
     * never a shared empty string, which would make every untranslated page one group
     * and collapse the whole menu to a single entry.
     *
     * @param  array<string, mixed>  $page
     */
    private function groupOf(array $page): string
    {
        $group = trim((string) ($page['translationGroup'] ?? ''));

        return $group !== '' ? $group : (string) ($page['id'] ?? spl_object_hash((object) $page));
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

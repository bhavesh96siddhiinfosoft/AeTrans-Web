<?php

namespace App\Services\Site;

use App\Services\Firebase\Firestore;
use Illuminate\Support\Facades\Cache;

/**
 * The languages the admin has switched on, from the `languages` collection.
 *
 * Documents carry `code`, `name`, `image` (a flag, in Storage), `isRtl`, `enable` and
 * `isDefault`. Today the project holds Bahasa Indonesia — the default — and English.
 *
 * Cached like Global Settings: every page needs the list to draw the switcher and to
 * decide its own `lang` and `dir`, and a round trip to Google for that on each request
 * is the opposite of the under-2.5s target.
 *
 * FALLBACK MATTERS HERE more than anywhere else in the site. If Firestore is
 * unreachable this returns a single hard-coded English entry rather than an empty list:
 * with no languages there is no default to fall back on, and `defaultCode()` would have
 * nothing to return for a visitor who has never chosen one.
 */
class Languages
{
    private const CACHE_KEY = 'site.languages';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $languages = null;

    public function __construct(
        private readonly Firestore $firestore,
        private readonly int $cacheSeconds = 300,
    ) {}

    public function forget(): void
    {
        $this->languages = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The enabled languages, keyed by code, in a stable order: the default first, then
     * the rest alphabetically by name. Firestore returns documents in no useful order,
     * and a switcher whose items move between page loads is unusable.
     *
     * @return array<string, array{code: string, name: string, image: ?string, isRtl: bool, isDefault: bool}>
     */
    public function enabled(): array
    {
        if ($this->languages !== null) {
            return $this->languages;
        }

        $documents = Cache::remember(
            self::CACHE_KEY,
            $this->cacheSeconds,
            fn () => $this->firestore->collection('languages'),
        );

        $languages = collect($documents)
            ->filter(fn (array $doc) => ($doc['enable'] ?? false) === true && filled($doc['code'] ?? null))
            ->map(fn (array $doc) => [
                'code' => strtolower(trim((string) $doc['code'])),
                'name' => (string) ($doc['name'] ?? strtoupper((string) $doc['code'])),
                'image' => filled($doc['image'] ?? null) ? (string) $doc['image'] : null,
                'isRtl' => ($doc['isRtl'] ?? false) === true,
                'isDefault' => ($doc['isDefault'] ?? false) === true,
            ])
            ->keyBy('code')
            ->sortBy(fn (array $language) => ($language['isDefault'] ? '0' : '1').$language['name'])
            ->all();

        return $this->languages = $languages ?: $this->emergencyFallback();
    }

    public function codes(): array
    {
        return array_keys($this->enabled());
    }

    public function has(?string $code): bool
    {
        return $code !== null && array_key_exists(strtolower($code), $this->enabled());
    }

    /**
     * The default language's code.
     *
     * Falls back to the first enabled one when no document is marked default — an
     * admin can clear that flag, and a site with no default has no working front page.
     */
    public function defaultCode(): string
    {
        $languages = $this->enabled();

        foreach ($languages as $code => $language) {
            if ($language['isDefault']) {
                return $code;
            }
        }

        return (string) array_key_first($languages);
    }

    public function isRtl(?string $code = null): bool
    {
        $code = strtolower($code ?: app()->getLocale());

        return $this->enabled()[$code]['isRtl'] ?? false;
    }

    /** `rtl` or `ltr`, for the `dir` attribute on <html>. */
    public function direction(?string $code = null): string
    {
        return $this->isRtl($code) ? 'rtl' : 'ltr';
    }

    public function name(?string $code = null): string
    {
        $code = strtolower($code ?: app()->getLocale());

        return $this->enabled()[$code]['name'] ?? strtoupper($code);
    }

    /*
     * There is no `urlFor()`. It built the current URL with the locale segment swapped,
     * for a site where each language had its own address; the client asked on
     * 2026-08-20 for one address per page, so a language has no URL of its own to point
     * at. The switcher POSTs instead — see LanguageController.
     */

    /**
     * One language, hard-coded, for when Firestore cannot be reached.
     *
     * Bahasa Indonesia — the site's default, and what the client's customers read. It was
     * English until 2026-08-26, which meant a Firestore outage silently flipped the whole
     * site into a language most of its visitors had not chosen, at the moment it was
     * already least able to explain itself.
     *
     * `config('app.locale')` rather than a literal, so the default is written down once.
     *
     * @return array<string, array<string, mixed>>
     */
    private function emergencyFallback(): array
    {
        $code = strtolower((string) config('app.locale', 'id'));

        return [$code => [
            'code' => $code,
            // No name to show: with one language the switcher does not render at all.
            'name' => strtoupper($code),
            'image' => null,
            // Firestore is what knows which languages read right to left, and Firestore
            // is exactly what is unavailable here. Left to right is the safe assumption.
            'isRtl' => false,
            'isDefault' => true,
        ]];
    }
}

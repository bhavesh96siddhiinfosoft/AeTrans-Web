<?php

namespace App\Http\Middleware;

use App\Services\Site\Languages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which language this request is answered in.
 *
 * The choice is carried by a COOKIE, and the URL never changes — `/login` is `/login`
 * in both languages. That is the client's decision, taken on 2026-08-20, and it
 * reverses spec §3, which put the locale in the path (`/id/login`).
 *
 * What that costs, recorded here so it is not rediscovered as a mystery:
 *
 *   * a search engine can only index ONE version of each page, so the Indonesian and
 *     English pages cannot rank separately, and `hreflang` has nothing to point at;
 *   * a link shared on WhatsApp opens in whatever language the RECIPIENT last chose,
 *     not the one the sender was reading — the preview card included;
 *   * `cms_pages.translationGroup`, which exists to tie a page's translations
 *     together, has nothing to do again.
 *
 * None of that breaks the site; it limits what the site can be found by. If SEO per
 * language is ever wanted, the locale has to go back in the path.
 *
 * Applied to the whole `web` group, so no route can be reached without a language set.
 */
class SetLocale
{
    public const COOKIE = 'locale';

    public function __construct(private readonly Languages $languages) {}

    public function handle(Request $request, Closure $next): Response
    {
        $chosen = $request->cookie(self::COOKIE);

        /*
         * The admin's default when the visitor has never chosen, and also when they
         * chose a language that has since been switched off in the panel — a stale
         * cookie must not leave someone reading a language the client has withdrawn.
         */
        app()->setLocale(
            $this->languages->has($chosen) ? strtolower((string) $chosen) : $this->languages->defaultCode()
        );

        return $next($request);
    }
}

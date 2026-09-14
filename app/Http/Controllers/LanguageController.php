<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Services\Site\Languages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Changes the language and puts the visitor back where they were.
 *
 * A POST and not a link, because it changes something stored about the visitor. A GET
 * would also work, but only by putting `?lang=id` in the address bar — which is the
 * thing this design exists to avoid — or by leaving a URL that a crawler could follow
 * and quietly reassign the language of.
 *
 * The redirect is back to the referring page, so switching language on the shuttle
 * page returns the shuttle page, not the front page.
 */
class LanguageController extends Controller
{
    public function update(Request $request, Languages $languages): RedirectResponse
    {
        $code = strtolower((string) $request->input('code'));

        /*
         * Only a language the admin has enabled. Anything else is ignored rather than
         * rejected with an error: a stale form from a language that has since been
         * switched off should quietly leave the visitor where they are, not show them
         * a validation message about something they cannot see.
         */
        if (! $languages->has($code)) {
            return back();
        }

        // A year, and NOT encrypted: it holds `id` or `en`, and there is nothing in it
        // to protect. See bootstrap/app.php.
        Cookie::queue(Cookie::make(SetLocale::COOKIE, $code, 60 * 24 * 365, null, null, null, false));

        return redirect()->to($this->returnTo($request));
    }

    /**
     * Where to send the visitor back to — the page they switched language on.
     *
     * The form carries the path rather than relying on the referrer, which browsers
     * omit often enough to matter. That makes it visitor-supplied input, so it is
     * accepted ONLY as a path on this site: a value like `//evil.example/` or
     * `https://evil.example` would otherwise turn the language switch into an open
     * redirect, which is a real phishing tool and not a theoretical one.
     */
    private function returnTo(Request $request): string
    {
        $return = (string) $request->input('return');

        if (str_starts_with($return, '/') && ! str_starts_with($return, '//')) {
            return url($return);
        }

        return route('home');
    }
}

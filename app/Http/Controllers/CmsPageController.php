<?php

namespace App\Http\Controllers;

use App\Services\Site\CmsPages;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A page the admin wrote in the panel's CMS, at its own address.
 *
 * The route this answers is the LAST one in `routes/web.php` and matches anything, so
 * every real 404 on the site arrives here first. That is why the miss below throws
 * rather than redirecting or showing a friendly "page not found" of its own: Laravel's
 * own 404 is still the right answer, and a catch-all that swallowed it would turn every
 * mistyped URL into a 200 and let search engines index the lot.
 */
class CmsPageController extends Controller
{
    public function __invoke(CmsPages $pages, string $path)
    {
        $page = $pages->atPath($path);

        if ($page === null) {
            throw new NotFoundHttpException;
        }

        return view('cms.page', [
            'page' => $page,
            'seo' => $pages->seo($page),
        ]);
    }
}

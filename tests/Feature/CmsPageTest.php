<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Services\Site\CmsPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Pages the admin writes in the panel, served on the website.
 *
 * The feature's whole promise is that adding a page in the panel puts it on the site
 * with nobody touching this repository, so what these tests pin is the RESOLUTION —
 * which document answers which URL, and which ones must never answer at all.
 */
class CmsPageTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /** The two pages that exist in the client's live Firestore today. */
    private function pages(array $extra = []): array
    {
        return array_merge([
            'page-about' => [
                'id' => 'page-about',
                'name' => 'About Us',
                'slug' => 'about-us',
                'path' => 'about-us',
                'description' => '<h2>Who we are</h2><p>Charter cars and airport shuttles.</p>',
                'excerpt' => 'Charter cars with drivers across East Java.',
                'status' => 'publish',
                'publish' => true,
                'locale' => 'en',
                'translationGroup' => 'about-us',
                'menuOrder' => 1,
                'featuredImage' => '',
                'seo' => [
                    'title' => 'About Aetranse — Charter Cars & Airport Shuttle',
                    'metaDescription' => 'Own fleet, fixed fares.',
                    'canonicalUrl' => '',
                    'schema' => ['pageType' => 'AboutPage', 'articleType' => 'None'],
                ],
            ],
            'page-contact' => [
                'id' => 'page-contact',
                'name' => 'Contact Us',
                'slug' => 'contact-us',
                'path' => 'contact-us',
                'description' => '<p>Message us on WhatsApp.</p>',
                'excerpt' => 'Contact Aetranse.',
                'status' => 'publish',
                'publish' => true,
                'locale' => 'en',
                'translationGroup' => 'contact-us',
                'menuOrder' => 2,
                'seo' => [],
            ],
        ], $extra);
    }

    public function test_a_published_page_is_served_at_its_own_path(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages()]);

        $this->get('/about-us')
            ->assertOk()
            ->assertSee('About Us')
            ->assertSee('Charter cars and airport shuttles.')
            // The body is the panel's HTML and must reach the page as MARKUP. Escaped,
            // the customer reads the tags instead of the page.
            ->assertSee('<h2>Who we are</h2>', false);
    }

    /**
     * The address is `path`, not `slug`.
     *
     * They are equal on both live documents, which is the coincidence that would hide
     * this: the first nested page the admin creates has `slug: "terms"` and
     * `path: "legal/terms"`, and a site that routed on the slug would 404 it.
     */
    public function test_the_url_is_the_path_field_and_nesting_works(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages([
            'page-terms' => [
                'id' => 'page-terms', 'name' => 'Terms', 'slug' => 'terms',
                'path' => 'legal/terms', 'description' => '<p>The terms.</p>',
                'status' => 'publish', 'publish' => true, 'locale' => 'en',
                'translationGroup' => 'terms', 'menuOrder' => 3, 'seo' => [],
            ],
        ])]);

        $this->get('/legal/terms')->assertOk()->assertSee('The terms.', false);
        $this->get('/terms')->assertNotFound();
    }

    /**
     * A draft is invisible, and BOTH flags have to agree before it is not.
     *
     * The panel writes `status` and `publish` together; the website trusts neither
     * alone. A draft reaching a public URL is the one failure here that cannot be taken
     * back — it can be indexed before anybody notices.
     */
    public function test_an_unpublished_page_is_not_served(): void
    {
        $this->fakeCatalog(['cms_pages' => [
            'page-draft' => [
                'id' => 'page-draft', 'name' => 'Draft', 'path' => 'draft',
                'description' => '<p>Not finished.</p>', 'status' => 'draft',
                'publish' => false, 'locale' => 'en', 'seo' => [],
            ],
            'page-half' => [
                'id' => 'page-half', 'name' => 'Half', 'path' => 'half',
                'description' => '<p>Flags disagree.</p>', 'status' => 'publish',
                'publish' => false, 'locale' => 'en', 'seo' => [],
            ],
            'page-other-half' => [
                'id' => 'page-other-half', 'name' => 'Other half', 'path' => 'other-half',
                'description' => '<p>Flags disagree.</p>', 'status' => 'draft',
                'publish' => true, 'locale' => 'en', 'seo' => [],
            ],
        ]]);

        $this->get('/draft')->assertNotFound();
        $this->get('/half')->assertNotFound();
        $this->get('/other-half')->assertNotFound();
    }

    /**
     * The catch-all does not swallow the site's real 404s.
     *
     * This route matches every path on the site and is the last one declared, so a
     * mistyped URL arrives here before Laravel gives up. Answering 200 with a friendly
     * page of its own would tell search engines that every typo is a real page.
     */
    public function test_an_unknown_path_is_still_a_404(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages()]);

        $this->get('/no-such-page')->assertNotFound();
    }

    /** And it does not shadow a real route that happens to be one segment long. */
    public function test_the_catch_all_does_not_shadow_the_site(): void
    {
        $this->fakeCatalog(['cms_pages' => [
            'page-login' => [
                'id' => 'page-login', 'name' => 'Hijack', 'path' => 'login',
                'description' => '<p>Should never be reachable.</p>',
                'status' => 'publish', 'publish' => true, 'locale' => 'en', 'seo' => [],
            ],
        ]]);

        $this->get('/login')->assertOk()->assertDontSee('Should never be reachable.', false);
    }

    /** Every page carries a footer link to every other, in the admin's own order. */
    public function test_the_pages_are_listed_in_the_footer(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages()]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['About Us', 'Contact Us'])
            ->assertSee('href="'.url('about-us').'"', false)
            ->assertSee('href="'.url('contact-us').'"', false);
    }

    /**
     * The page's own SEO block reaches the `<head>`.
     *
     * This is what the site is server-rendered FOR: WhatsApp and Facebook run no
     * JavaScript, and in Indonesia a link travels by WhatsApp.
     */
    public function test_a_pages_own_seo_is_used_in_the_head(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages()]);

        $this->get('/about-us')
            ->assertOk()
            ->assertSee('<title>About Aetranse — Charter Cars &amp; Airport Shuttle</title>', false)
            ->assertSee('<meta name="description" content="Own fleet, fixed fares.">', false)
            ->assertSee('"@type": "AboutPage"', false)
            // Not set on this page, so the canonical is the address being read.
            ->assertSee('<link rel="canonical" href="'.url('about-us').'">', false);
    }

    /**
     * An empty SEO field means "not set", not "an empty title".
     *
     * The panel saves the whole map whether or not the admin filled it in, so a page
     * with a blank SEO tab must fall back to its own name rather than serve a site with
     * no title at all.
     */
    public function test_a_blank_seo_field_falls_back_rather_than_blanking_the_head(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages()]);

        $this->get('/contact-us')
            ->assertOk()
            ->assertSee('<title>Contact Us</title>', false)
            ->assertDontSee('<title></title>', false);
    }

    /**
     * `noindex` is printed; the default is not.
     *
     * No robots tag already means index, follow, so emitting it everywhere adds a line
     * to every page and says nothing. A page the admin has hidden from search is the
     * case that earns the tag.
     */
    public function test_robots_are_emitted_only_when_they_say_something(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages([
            'page-hidden' => [
                'id' => 'page-hidden', 'name' => 'Hidden', 'path' => 'hidden',
                'description' => '<p>Live, but not for search.</p>',
                'status' => 'publish', 'publish' => true, 'locale' => 'en',
                'translationGroup' => 'hidden', 'menuOrder' => 9,
                'seo' => ['robots' => [
                    'index' => false, 'follow' => false, 'archive' => true,
                    'snippet' => true, 'imageIndex' => true,
                    'maxSnippet' => -1, 'maxImagePreview' => 'large', 'maxVideoPreview' => -1,
                ]],
            ],
        ])]);

        $this->get('/hidden')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow, max-image-preview:large">', false);

        // The About page carries no robots block at all.
        $this->get('/about-us')->assertOk()->assertDontSee('name="robots"', false);
    }

    /**
     * One URL per page, in every language.
     *
     * The site's whole convention: the address never changes and the language lives in
     * a cookie ([[aetranse-locale-in-cookie]]). So a reader in Indonesian following an
     * English link gets the Indonesian text at the English address, and the Indonesian
     * document's own path reaches the same page.
     */
    public function test_a_page_is_served_in_the_readers_language(): void
    {
        $this->fakeCatalog([
            'cms_pages' => $this->pages([
                'page-about-id' => [
                    'id' => 'page-about-id', 'name' => 'Tentang Kami', 'slug' => 'tentang-kami',
                    'path' => 'tentang-kami', 'description' => '<p>Sewa mobil dengan sopir.</p>',
                    'status' => 'publish', 'publish' => true, 'locale' => 'id',
                    'translationGroup' => 'about-us', 'menuOrder' => 1, 'seo' => [],
                ],
            ]),
            'languages' => [
                'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true, 'isRtl' => false],
                'id-doc' => ['code' => 'id', 'name' => 'Bahasa Indonesia', 'enable' => true, 'isDefault' => false, 'isRtl' => false],
            ],
        ]);

        /*
         * Through the cookie, the way a real reader chooses. `app()->setLocale()` here
         * would be undone before the controller runs: `SetLocale` resolves the language
         * on every request, which is the whole mechanism this test is about.
         *
         * UNencrypted, because that is what this cookie is (see bootstrap/app.php).
         * `withCookie()` encrypts what it sends, so the middleware would read ciphertext,
         * fail `Languages::has()` and fall back to English — a test that passes while
         * checking nothing.
         */
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'id');

        $this->get('/about-us')->assertOk()->assertSee('Sewa mobil dengan sopir.', false);
        $this->get('/tentang-kami')->assertOk()->assertSee('Sewa mobil dengan sopir.', false);

        // And the menu lists the page ONCE, under the name the reader can read.
        $this->get('/')->assertOk()->assertSee('Tentang Kami')->assertDontSee('About Us');
    }

    /**
     * A missing translation shows the page that does exist, rather than a 404.
     *
     * All the live content is English today. Hiding a page from every Indonesian reader
     * until somebody translates it hides the gap from the admin too — and loses them the
     * page they had.
     */
    public function test_an_untranslated_page_still_appears(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages()]);

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'id');

        $this->get('/about-us')->assertOk()->assertSee('Charter cars and airport shuttles.', false);
    }

    /**
     * Pages with no `translationGroup` stay separate.
     *
     * The panel writes one, but a document created another way may not have it — and
     * grouping them all under the empty string would collapse the whole menu into a
     * single entry and serve one page at every address.
     */
    public function test_pages_without_a_translation_group_are_not_merged(): void
    {
        $this->fakeCatalog(['cms_pages' => [
            'page-a' => [
                'id' => 'page-a', 'name' => 'First', 'path' => 'first',
                'description' => '<p>First.</p>', 'status' => 'publish',
                'publish' => true, 'locale' => 'en', 'menuOrder' => 1, 'seo' => [],
            ],
            'page-b' => [
                'id' => 'page-b', 'name' => 'Second', 'path' => 'second',
                'description' => '<p>Second.</p>', 'status' => 'publish',
                'publish' => true, 'locale' => 'en', 'menuOrder' => 2, 'seo' => [],
            ],
        ]]);

        $this->assertCount(2, app(CmsPages::class)->menu());

        $this->get('/first')->assertOk()->assertSee('First.', false);
        $this->get('/second')->assertOk()->assertSee('Second.', false);
    }

    /** A trailing slash is the same page, not a second one. */
    public function test_a_trailing_slash_reaches_the_same_page(): void
    {
        $this->fakeCatalog(['cms_pages' => $this->pages()]);

        $this->get('/about-us/')->assertOk()->assertSee('About Us');
    }
}

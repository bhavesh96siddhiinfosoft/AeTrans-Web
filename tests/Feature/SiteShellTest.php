<?php

namespace Tests\Feature;

use App\Services\Site\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The header, the footer and the <head> — everything the admin owns from Global
 * Settings, and everything the site has to do when they are not there.
 *
 * The field names are the panel's own (settings/globalValue, settings/contact_us,
 * settings/socialLinks). They are a contract with another application, so they are
 * pinned rather than left to be noticed when a live site renders a blank header.
 */
class SiteShellTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private function settings(): array
    {
        return [
            'globalValue' => [
                'web_site_name' => 'AeTrans.id',
                'web_tagline' => 'Charter and airport shuttle',
                'web_logo' => 'https://storage.example/logo-light.png',
                'web_logo_dark' => 'https://storage.example/logo-dark.png',
                'web_footer_logo' => 'https://storage.example/logo-footer.png',
                'web_favicon' => 'https://storage.example/favicon.png',
                'web_og_image' => 'https://storage.example/og.png',
                'web_meta_title' => 'Ground transport in East Java',
                'web_meta_description' => 'Book a car with a driver, or a shuttle seat.',
                'web_meta_keywords' => 'charter, shuttle',
                'web_play_store_url' => 'https://play.google.com/store/apps/details?id=id.aetrans',
            ],
            'contact_us' => [
                'email' => 'info@aetrans.id',
                'phone' => '+62 816 955 959',
                'whatsapp' => '+62816955959',
                'address' => 'Ngawi, Jawa Timur',
                'supportURL' => 'https://aetrans.id/contact-us',
            ],
            'socialLinks' => [
                'facebook' => 'https://facebook.com/aetransid',
                'tiktok' => 'https://tiktok.com/@aetransid',
                'telegram' => '',
                'whatsapp' => 'https://wa.me/628123456789',
            ],
        ];
    }

    public function test_the_header_and_footer_carry_the_admins_logo(): void
    {
        $this->fakeSettings($this->settings());

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('https://storage.example/logo-light.png', false)
            // Both marks are in the markup; CSS picks one, so the swap needs no script
            // and cannot flash.
            ->assertSee('https://storage.example/logo-dark.png', false)
            ->assertSee('https://storage.example/logo-footer.png', false);
    }

    public function test_the_head_carries_the_admins_seo(): void
    {
        $this->fakeSettings($this->settings());

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Ground transport in East Java</title>', false)
            ->assertSee('Book a car with a driver, or a shuttle seat.', false)
            ->assertSee('<meta name="keywords" content="charter, shuttle">', false)
            ->assertSee('https://storage.example/og.png', false)
            ->assertSee('<link rel="icon" href="https://storage.example/favicon.png">', false)
            // The large card only when there is an image to fill it.
            ->assertSee('content="summary_large_image"', false);
    }

    public function test_the_footer_shows_the_contact_block_and_the_links_that_exist(): void
    {
        $this->fakeSettings($this->settings());

        $response = $this->get('/');

        $response->assertSee('mailto:info@aetrans.id', false)
            // Spaces stripped: a dialler will not follow `tel:+62 816 955 959`.
            ->assertSee('tel:+62816955959', false)
            ->assertSee('https://wa.me/62816955959', false)
            ->assertSee('Ngawi, Jawa Timur')
            ->assertSee('https://facebook.com/aetransid', false)
            ->assertSee('https://tiktok.com/@aetransid', false);

        // An empty setting is an absent block, not a dead link.
        $response->assertDontSee('https://t.me/', false);
    }

    /**
     * `web_play_store_url` really does hold `#` on the live project — a placeholder
     * saved to get past the form. A button that reloads the page is worse than none.
     */
    public function test_a_placeholder_play_store_url_is_not_rendered_as_a_button(): void
    {
        $settings = $this->settings();
        $settings['globalValue']['web_play_store_url'] = '#';

        $this->fakeSettings($settings);

        $this->get('/')->assertOk()->assertDontSee('Get the app');
    }

    /**
     * Firestore is down, or the rules refuse the read. The page still has to render —
     * a site that sells cannot 500 because a tagline was unreadable.
     */
    public function test_the_page_still_renders_when_firestore_is_unreachable(): void
    {
        $this->fakeSettingsUnavailable();

        $this->get('/')
            ->assertOk()
            // Falls back to APP_NAME rather than printing an empty header.
            ->assertSee(config('app.name'))
            // In the default language: with no Firestore there is no admin default to read.
            ->assertSee(__('lang.rental_driver'));
    }

    /** No logo uploaded is not a missing brand — the name is printed as words. */
    public function test_the_site_name_stands_in_for_a_missing_logo(): void
    {
        $this->fakeSettings(['globalValue' => ['web_site_name' => 'AeTrans.id']]);

        $this->get('/')
            ->assertOk()
            ->assertSee('brand-name', false)
            ->assertDontSee('brand-logo', false);
    }

    /**
     * THREE reads serve the whole page, and only three: `settings`, `languages` and
     * `cms_pages`. The header, the footer, the meta tags, the `dir` attribute, the
     * switcher and the page menu all draw on those, and none of them may cost a round
     * trip of its own.
     *
     * It was two until the CMS pages landed on 2026-08-31, and the third is the price of
     * a footer that lists what the admin has published. The number is asserted rather
     * than left to drift because each read is ~2s over REST against the real project:
     * the same measurement that put 5.3 seconds into the booking screen before the
     * availability cache.
     */
    public function test_the_whole_shell_costs_three_firestore_reads(): void
    {
        $this->fakeSettings($this->settings());

        $this->get('/')->assertOk();

        Http::assertSentCount(3);
    }

    public function test_the_settings_are_cached_across_requests(): void
    {
        $this->fakeSettings($this->settings());

        $this->get('/');
        $this->get('/login');

        // Still three: the second page is served entirely from the cached copies.
        Http::assertSentCount(3);
    }

    /**
     * The admin's own switch, not ours. FIREBASE_GOOGLE_LOGIN only stands in while the
     * setting has never been saved.
     */
    public function test_google_sign_in_follows_the_admin_setting(): void
    {
        $settings = $this->settings();
        $settings['globalValue']['web_google_login'] = false;

        $this->fakeSettings($settings);

        $this->assertFalse(app(SiteSettings::class)->googleLoginEnabled());

        $settings['globalValue']['web_google_login'] = true;
        $this->fakeSettings($settings);

        $this->assertTrue(app(SiteSettings::class)->googleLoginEnabled());
    }

    /**
     * The maintenance flag lives at `settings/maintenance_settings.customerWeb`. The
     * website spec calls it `settings.maintenance_customer_web`; the panel is the
     * authority and this is what it writes.
     */
    public function test_the_maintenance_flag_is_read_from_the_panels_field(): void
    {
        $this->fakeSettings(['maintenance_settings' => ['customerWeb' => true]]);

        $this->assertTrue(app(SiteSettings::class)->inMaintenance());

        $this->fakeSettings(['maintenance_settings' => ['customerWeb' => false]]);

        $this->assertFalse(app(SiteSettings::class)->inMaintenance());
    }
}

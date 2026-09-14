<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Changing language without changing the address.
 *
 * The client's decision of 2026-08-20: one URL per page, the language carried by a
 * cookie. This replaced locale-prefixed paths (`/id/login`), which is what spec §3
 * asks for — see SetLocale for what that trade costs in search visibility.
 */
class LanguageSwitchingTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /** English is the default in the fakes; the live project has Indonesian there. */
    public function test_a_visitor_who_has_never_chosen_gets_the_admins_default(): void
    {
        $this->fakeSettings([], [
            'id-doc' => ['code' => 'id', 'name' => 'Bahasa Indonesia', 'enable' => true, 'isDefault' => true],
            'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => false],
        ]);

        $this->get('/')->assertOk()->assertSee('Masuk');
    }

    public function test_switching_language_changes_the_page_but_not_the_address(): void
    {
        $this->fakeSettings([]);

        $this->get('/')->assertSee('Log in');

        $response = $this->post('/language', ['code' => 'id', 'return' => '/']);

        // Back to the very same URL — the whole point of the change.
        $response->assertRedirect(url('/'))
            ->assertCookie(SetLocale::COOKIE, 'id', false);

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'id')
            ->get('/')
            ->assertOk()
            ->assertSee('Masuk')
            ->assertDontSee('Log in');
    }

    /** Switching on the sign-in page returns the sign-in page, not the front page. */
    public function test_the_visitor_comes_back_to_the_page_they_were_reading(): void
    {
        $this->fakeSettings([]);

        $this->post('/language', ['code' => 'id', 'return' => '/forgot-password'])
            ->assertRedirect(url('/forgot-password'));
    }

    /**
     * `return` is a hidden field, so it is visitor-supplied. Anything that is not a
     * path on this site is discarded: an open redirect here would turn the language
     * switch into a phishing link that starts on the real domain.
     */
    public function test_an_off_site_return_address_is_refused(): void
    {
        $this->fakeSettings([]);

        foreach (['//evil.example/', 'https://evil.example', 'javascript:alert(1)'] as $bad) {
            $this->post('/language', ['code' => 'id', 'return' => $bad])
                ->assertRedirect(route('home'));
        }
    }

    /** A language the admin has switched off leaves the visitor where they are. */
    public function test_a_language_that_is_not_enabled_is_ignored(): void
    {
        $this->fakeSettings([]);

        $this->from('/')
            ->post('/language', ['code' => 'fr', 'return' => '/'])
            ->assertRedirect('/')
            ->assertCookieMissing(SetLocale::COOKIE);
    }

    /**
     * A cookie naming a language the admin has since withdrawn must not keep serving
     * it. The visitor quietly returns to the default.
     */
    public function test_a_stale_cookie_falls_back_to_the_default(): void
    {
        $this->fakeSettings([], [
            'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true],
            'id-doc' => ['code' => 'id', 'name' => 'Bahasa Indonesia', 'enable' => false, 'isDefault' => false],
        ]);

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'id')
            ->get('/')
            ->assertOk()
            ->assertSee('Log in')
            ->assertDontSee('Masuk');
    }

    /** The choice has to survive the whole visit, not just the redirect. */
    public function test_the_choice_holds_across_pages(): void
    {
        $this->fakeSettings([]);

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'id')
            ->get('/login')
            ->assertOk()
            ->assertSee('Masuk');
    }

    public function test_the_html_tag_carries_the_language_and_direction(): void
    {
        $this->fakeSettings([]);

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'id')
            ->get('/')
            ->assertSee('<html lang="id" dir="ltr"', false);
    }

    /**
     * `isRtl` is what the site keys on — so the day an admin switches Arabic on, the page
     * comes out the right way round with no code change.
     */
    public function test_an_rtl_language_flips_the_document_direction(): void
    {
        $this->fakeSettings([], [
            'ar-doc' => ['code' => 'ar', 'name' => 'العربية', 'enable' => true, 'isDefault' => true, 'isRtl' => true],
        ]);

        $this->get('/')->assertSee('<html lang="ar" dir="rtl"', false);
    }

    /** The three languages the client asked for, all offered at once. */
    public function test_all_three_languages_are_offered_when_the_admin_enables_them(): void
    {
        $this->fakeSettings([], $this->threeLanguages());

        $this->get('/')
            ->assertOk()
            ->assertSee('English')
            ->assertSee('Bahasa Indonesia')
            ->assertSee('العربية');
    }

    /**
     * The Arabic copy itself, not just the direction.
     *
     * `lang/ar.json` is a DRAFT — written to get the language working end to end, and to
     * be read by somebody who speaks it before a customer sees it. What this pins is that
     * it is wired up: the same page that says "Log in" in English must not fall back to
     * English once Arabic is chosen.
     */
    public function test_choosing_arabic_serves_the_arabic_copy(): void
    {
        $this->fakeSettings([], $this->threeLanguages());

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'ar')
            ->get('/')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl"', false)
            // "Log in", "Travel Shuttle", "Rental + Driver".
            ->assertSee('تسجيل الدخول')
            ->assertSee('رحلات المطار')
            ->assertSee('سيارة مع سائق')
            ->assertDontSee('Log in');
    }

    /**
     * Laravel's own three authentication lines, which the framework ships in English.
     *
     * Without `lang/ar/auth.php` a customer reads the whole site in Arabic and then gets
     * an English sentence the moment a password is wrong — the moment they most need to
     * understand it.
     */
    public function test_arabic_covers_the_framework_authentication_lines(): void
    {
        app()->setLocale('ar');

        foreach (['auth.failed', 'auth.password', 'auth.throttle'] as $key) {
            $line = __($key);

            $this->assertNotSame($key, $line, "auth.php is missing {$key} in Arabic.");
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $line);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function threeLanguages(): array
    {
        return [
            'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true, 'isRtl' => false],
            'id-doc' => ['code' => 'id', 'name' => 'Bahasa Indonesia', 'enable' => true, 'isDefault' => false, 'isRtl' => false],
            'ar-doc' => ['code' => 'ar', 'name' => 'العربية', 'enable' => true, 'isDefault' => false, 'isRtl' => true],
        ];
    }

    /** One language means no switcher: a control with a single option is furniture. */
    public function test_the_switcher_is_absent_when_only_one_language_is_enabled(): void
    {
        $this->fakeSettings([], [
            'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true],
        ]);

        $this->get('/')->assertOk()->assertDontSee('lang-toggle', false);
    }

    /**
     * No `hreflang`: it names the URL of each translation, and there is now only one
     * URL. Tags all pointing at the same address would tell a search engine something
     * untrue, which is worse than saying nothing.
     */
    public function test_no_hreflang_is_claimed_for_a_single_url(): void
    {
        $this->fakeSettings([]);

        $this->get('/')->assertDontSee('hreflang', false);
    }

    /**
     * If Firestore cannot be reached there are no languages at all, and no default to
     * answer with. English stands in so the site keeps serving.
     */
    public function test_the_site_still_serves_when_the_language_list_is_unreadable(): void
    {
        $this->fakeSettingsUnavailable();

        /*
         * In the site's DEFAULT language, not English. With no language list there is no
         * switcher and no admin default to read, so the page falls back to
         * `config('app.locale')` — Bahasa Indonesia, because that is what these customers
         * read. Serving English when Firestore is down would flip the whole site at the
         * moment it is least able to explain itself.
         */
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="'.config('app.locale').'"', false)
            ->assertSee(__('lang.log_in'));
    }
}

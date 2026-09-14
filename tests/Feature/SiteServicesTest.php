<?php

namespace Tests\Feature;

use App\Services\Firebase\FirestoreValue;
use App\Services\Site\SiteServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * What the website calls the two services is the ADMIN'S to decide.
 *
 * The panel's Services screen says the name it sets is "shown in each enabled language",
 * and until 2026-09-07 that was not true of the website: "Rental + Driver" and "Travel
 * Shuttle" were baked into nineteen views and two mailables, so renaming a service in the
 * panel changed nothing a customer could see.
 *
 * These tests pin the rename actually arriving, and — the part that matters more — pin
 * the fallbacks, because these names sit in the header of every page on the site.
 */
class SiteServicesTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /** The names the client's live panel actually holds, which is the point of the change. */
    private const LIVE = [
        'services' => [
            'svc-charter' => ['id' => 'svc-charter', 'slug' => 'charter', 'name' => 'Rental With Driver', 'enable' => true, 'order' => 1],
            'svc-shuttle' => ['id' => 'svc-shuttle', 'slug' => 'airport-shuttle', 'name' => 'Travel Juanda & Tj Perak', 'enable' => true, 'order' => 2],
        ],
    ];

    public function test_the_admins_name_reaches_the_header_the_footer_and_the_home_page(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog(self::LIVE);

        $this->get('/')
            ->assertOk()
            ->assertSee('Rental With Driver')
            // `&` in the live shuttle name, so the page carries it escaped.
            ->assertSee('Travel Juanda &amp; Tj Perak', false)
            // And the wording it replaced is gone from the shell entirely.
            ->assertDontSee('Rental + Driver')
            ->assertDontSee('Travel Shuttle');
    }

    /**
     * `services.name` is a locale map on documents the current panel writes, and the
     * reader's own language is the one to print.
     */
    public function test_a_localised_name_follows_the_readers_language(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog([
            'services' => [
                'svc-charter' => [
                    'id' => 'svc-charter', 'slug' => 'charter', 'enable' => true, 'order' => 1,
                    'name' => ['en' => 'Rental With Driver', 'id' => 'Sewa Dengan Sopir'],
                ],
                'svc-shuttle' => [
                    'id' => 'svc-shuttle', 'slug' => 'airport-shuttle', 'enable' => true, 'order' => 2,
                    'name' => ['en' => 'Travel Shuttle', 'id' => 'Travel Bandara'],
                ],
            ],
            /*
             * Indonesian ENABLED, or `SetLocale` falls back to English and this test
             * silently checks nothing — the trap `fakeCatalog()` warns about in so many
             * words.
             */
            'languages' => [
                'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true, 'isRtl' => false],
                'id-doc' => ['code' => 'id', 'name' => 'Bahasa Indonesia', 'enable' => true, 'isDefault' => false, 'isRtl' => false],
            ],
        ]);

        // Unencrypted, because that is how `SetLocale` reads it — `withCookie()` would
        // encrypt it and the middleware would fall back to the default language.
        $this->withUnencryptedCookie('locale', 'id')
            ->get('/')
            ->assertOk()
            ->assertSee('Sewa Dengan Sopir')
            ->assertDontSee('Rental With Driver');
    }

    /**
     * A name the admin typed in ONE language still has to show up in the others.
     *
     * The alternative — falling back to the site's own wording for any language the
     * panel has not filled in — would mean a rename that silently applied to some
     * visitors and not others.
     */
    public function test_a_name_missing_in_this_language_falls_back_to_one_the_panel_has(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog([
            'services' => [
                'svc-charter' => [
                    'id' => 'svc-charter', 'slug' => 'charter', 'enable' => true, 'order' => 1,
                    'name' => ['en' => 'Rental With Driver'],
                ],
                'svc-shuttle' => [
                    'id' => 'svc-shuttle', 'slug' => 'airport-shuttle', 'enable' => true, 'order' => 2,
                    'name' => ['en' => 'Travel Juanda & Tj Perak'],
                ],
            ],
            /*
             * Indonesian ENABLED, or `SetLocale` falls back to English and this test
             * silently checks nothing — the trap `fakeCatalog()` warns about in so many
             * words.
             */
            'languages' => [
                'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true, 'isRtl' => false],
                'id-doc' => ['code' => 'id', 'name' => 'Bahasa Indonesia', 'enable' => true, 'isDefault' => false, 'isRtl' => false],
            ],
        ]);

        $this->withUnencryptedCookie('locale', 'id')
            ->get('/')
            ->assertOk()
            ->assertSee('Rental With Driver');
    }

    /** An unnamed service is not a nameless website: the site's own wording stands in. */
    public function test_an_empty_name_falls_back_to_the_sites_own_wording(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog([
            'services' => [
                'svc-charter' => ['id' => 'svc-charter', 'slug' => 'charter', 'name' => '   ', 'enable' => true, 'order' => 1],
                'svc-shuttle' => ['id' => 'svc-shuttle', 'slug' => 'airport-shuttle', 'enable' => true, 'order' => 2],
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee(__('lang.rental_driver'))
            ->assertSee(__('lang.travel_shuttle'));
    }

    /**
     * The one that matters most.
     *
     * These names are in the header and footer of EVERY page, so a Firestore read that
     * throws here would take the whole site down over a nav link. It must degrade to the
     * site's own wording instead.
     */
    public function test_an_unreachable_firestore_does_not_take_the_site_down(): void
    {
        $this->fakeSettingsUnavailable();
        app(SiteServices::class);

        $this->get('/')
            ->assertOk()
            ->assertSee(__('lang.rental_driver'))
            ->assertSee(__('lang.travel_shuttle'));
    }

    // ---- Switched off in the panel -------------------------------------------

    /**
     * The panel's Services screen says "switch one off to take it off the website", and
     * until 2026-09-09 the website did nothing of the kind.
     *
     * It looked like it was working, which is why it lasted: a disabled service comes
     * back from `Catalog::service()` as null, and the NAME fallback built for a Firestore
     * outage then printed the site's own perfectly ordinary wording over it.
     */
    private function only(string $keep): array
    {
        $services = [
            'charter' => ['id' => 'svc-charter', 'slug' => 'charter', 'name' => 'Rental With Driver', 'enable' => $keep === 'charter', 'order' => 1],
            'shuttle' => ['id' => 'svc-shuttle', 'slug' => 'airport-shuttle', 'name' => 'Travel Juanda & Tj Perak', 'enable' => $keep === 'shuttle', 'order' => 2],
        ];

        return ['services' => ['svc-charter' => $services['charter'], 'svc-shuttle' => $services['shuttle']]];
    }

    public function test_a_switched_off_service_is_gone_from_the_home_page_header_and_footer(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog($this->only('charter'));

        $this->get('/')
            ->assertOk()
            ->assertSee('Rental With Driver')
            // Neither the admin's name for it NOR the site's fallback wording.
            ->assertDontSee('Travel Juanda &amp; Tj Perak', false)
            ->assertDontSee(__('lang.travel_shuttle'))
            // And the heading stops promising two of them.
            ->assertDontSee(__('lang.two_ways_to_travel'))
            ->assertSee(__('lang.what_we_offer'));
    }

    /**
     * And it is not bookable, only unadvertised.
     *
     * `/book/shuttle` gets bookmarked and shared on WhatsApp. A hidden card with a live
     * flow behind it still takes orders an operator has to ring somebody up to cancel.
     */
    public function test_a_switched_off_service_cannot_be_booked_by_url(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog($this->only('charter'));

        $this->get('/book/shuttle')->assertNotFound();
        $this->get('/book/shuttle/trip')->assertNotFound();
        $this->post('/book/shuttle', [])->assertNotFound();

        // The one still on sale is untouched.
        $this->get('/book/charter')->assertOk();
    }

    public function test_the_other_way_round_too(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog($this->only('shuttle'));

        $this->get('/')
            ->assertOk()
            ->assertSee('Travel Juanda &amp; Tj Perak', false)
            ->assertDontSee('Rental With Driver')
            ->assertDontSee(__('lang.rental_driver'));

        $this->get('/book/charter')->assertNotFound();
        $this->get('/book/shuttle')->assertOk();
    }

    /** Both off: the section goes entirely rather than heading an empty grid. */
    public function test_both_switched_off_removes_the_services_section(): void
    {
        $this->fakeFirebase();
        $this->fakeCatalog([
            'services' => [
                'svc-charter' => ['id' => 'svc-charter', 'slug' => 'charter', 'name' => 'Rental With Driver', 'enable' => false, 'order' => 1],
                'svc-shuttle' => ['id' => 'svc-shuttle', 'slug' => 'airport-shuttle', 'name' => 'Travel Juanda & Tj Perak', 'enable' => false, 'order' => 2],
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee(__('lang.two_ways_to_travel'))
            ->assertDontSee(__('lang.what_we_offer'))
            ->assertDontSee('id="services"', false);
    }

    /**
     * THE ONE THAT NEARLY WENT WRONG.
     *
     * `Firestore::collection()` returns an empty array when it cannot read, so an outage
     * arrives looking exactly like every service being switched off. The first version of
     * `offers()` caught exceptions — which never come — and would have closed the whole
     * shop the first time Google had a bad second.
     */
    public function test_an_unreadable_catalogue_keeps_both_services_on_sale(): void
    {
        $this->fakeSettingsUnavailable();

        $this->get('/')
            ->assertOk()
            ->assertSee(__('lang.rental_driver'))
            ->assertSee(__('lang.travel_shuttle'))
            ->assertSee(__('lang.two_ways_to_travel'));

        $this->get('/book/charter')->assertOk();
    }

    /**
     * The switch has to take effect while the admin is still looking at the site.
     *
     * Reported as a bug on 2026-09-09 twice over — once for a service that would not go
     * away, and again for one that would not come back — and both times the code was
     * right and the CACHE was stale. `services` is now held for 30 seconds rather than
     * the catalogue's five minutes, because it is two documents carrying the one field
     * an admin flips and then immediately checks.
     */
    public function test_the_services_list_is_cached_far_more_briefly_than_the_catalogue(): void
    {
        $this->assertLessThanOrEqual(
            60,
            (int) config('firebase.services_cache_seconds'),
            'An admin who switches a service off must see it go within a reasonable wait.',
        );

        $this->assertLessThan(
            (int) config('firebase.settings_cache_seconds'),
            (int) config('firebase.services_cache_seconds'),
            'services must be held for less time than the rest of the catalogue.',
        );
    }

    /**
     * My Bookings shows the LIVE name, not the `serviceName` snapshotted onto the
     * booking when it was made.
     *
     * That field stays on the document for the operator's record, but printing it here
     * would put the old name beside the new one on a site that had just been renamed —
     * the same staleness this whole change removes.
     */
    public function test_my_bookings_shows_the_live_name_not_the_one_stored_on_the_booking(): void
    {
        $this->signInCustomer();

        // Staged AFTER signing in: staging replaces the whole stub set.
        $this->fakeCatalog(self::LIVE);
        $this->stubWith([
            '*/documents:runQuery*' => Http::response([[
                'document' => [
                    'name' => 'projects/test/databases/(default)/documents/bookings/bk1234567890',
                    'fields' => FirestoreValue::encodeFields([
                        'id' => 'bk1234567890',
                        'bookingType' => 'charter',
                        // What the service was called the day this booking was taken.
                        'serviceName' => 'Rental + Driver',
                        'status' => 'pending',
                        'pickupAddress' => 'Jl. Raya Ngawi 1',
                        'travelDate' => now()->addDays(3)->toDateString(),
                        'pickupTime' => '08:30',
                        'cost' => 1360000,
                        'currencyCode' => 'IDR',
                        'createdAt' => now()->toIso8601String(),
                    ]),
                ],
            ]]),
        ]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSee('Rental With Driver')
            ->assertDontSee('Rental + Driver');
    }
}

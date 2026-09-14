<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The Google Maps browser key is fetched by the page, not printed into it.
 *
 * The client asked on 2026-09-08 that the key not sit in view-source. It does not any
 * more — but it is NOT a secret and these tests are careful not to imply that it is.
 * The key travels in the Maps script URL, which is visible in the Network tab and in
 * the Elements panel on tags Google's own API injects. What limits the key is the
 * HTTP-referrer and API restriction set on it in the Google Cloud console.
 */
class MapsKeyTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private const KEY = 'AIzaSyTESTKEYTESTKEYTESTKEYTESTKEYTES';

    private function withKey(): void
    {
        $this->fakeCatalog([
            'settings' => [
                'mapSettings' => ['mapAPIkey' => self::KEY],
                'globalValue' => ['regionCode' => 'ID'],
            ],
        ]);
    }

    public function test_the_booking_page_does_not_carry_the_key_in_its_html(): void
    {
        $this->withKey();

        $response = $this->get('/book/charter')->assertOk();

        $response->assertDontSee(self::KEY);
        // The block that used to hold it is gone, not merely emptied.
        $response->assertDontSee('google-maps-config');
    }

    /** But the picker still renders, because a key IS configured. */
    public function test_the_picker_still_renders_when_a_key_is_configured(): void
    {
        $this->withKey();

        $this->get('/book/charter')
            ->assertOk()
            ->assertSee('id="location-picker"', false)
            ->assertSee('js/google-maps-loader.js', false);
    }

    public function test_the_endpoint_hands_the_key_to_the_page(): void
    {
        $this->withKey();

        $this->getJson('/maps-key')
            ->assertOk()
            ->assertJson(['key' => self::KEY, 'region' => 'ID'])
            // Private: a shared proxy must never hold one visitor's copy for another.
            ->assertHeader('Cache-Control', 'max-age=300, private');
    }

    /**
     * No key configured is not an error, and never has been: the address fields fall
     * back to plain text boxes, the distance is typed by hand, and the booking still
     * completes. The loader treats an empty string exactly as it treated a missing one.
     */
    public function test_no_key_configured_answers_with_an_empty_string(): void
    {
        $this->fakeCatalog(['settings' => []]);

        $this->getJson('/maps-key')
            ->assertOk()
            ->assertJson(['key' => '']);

        $this->get('/book/charter')
            ->assertOk()
            ->assertDontSee('id="location-picker"', false);
    }
}

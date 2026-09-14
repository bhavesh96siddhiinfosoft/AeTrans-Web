<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The signed-in customer's corner of the header.
 *
 * On a site whose only sign-in is at the end of a booking, a customer has no other way
 * to tell whether they are still signed in, or which account they are signed in AS —
 * and that matters here, because a booking belongs to the account that made it.
 */
class AccountMenuTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_a_signed_in_customer_sees_their_name_and_email(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer(['name' => 'Siti Rahayu', 'email' => 'siti@example.com']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Siti Rahayu')
            ->assertSee('siti@example.com');
    }

    public function test_the_menu_offers_the_pages_that_belong_to_them(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer();

        $this->get('/')
            ->assertOk()
            ->assertSee(route('bookings'), false)
            ->assertSee(route('profile.edit'), false)
            ->assertSee(route('logout'), false)
            ->assertSee(__('lang.profile_settings'))
            ->assertSee(__('lang.log_out'));
    }

    /**
     * Signing out is a POST.
     *
     * A link would let any page on the internet sign the customer out by embedding it
     * as an image.
     */
    public function test_signing_out_is_a_form_and_not_a_link(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer();

        $this->get('/')
            ->assertOk()
            ->assertSee('<form method="POST" action="'.route('logout').'"', false);
    }

    /** A visitor is offered the way in, and nothing that needs an account. */
    public function test_a_visitor_sees_the_way_in_instead(): void
    {
        $this->fakeCatalog();

        $this->get('/')
            ->assertOk()
            ->assertSee(__('lang.log_in'))
            ->assertDontSee(__('lang.profile_settings'))
            ->assertDontSee(__('lang.log_out'));
    }
}

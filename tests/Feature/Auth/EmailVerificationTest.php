<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Address verification belongs to Firebase.
 *
 * Laravel's signed-URL route is gone: the address is on a Firebase account the mobile
 * app signs into as well, and verifying it here would leave the customer verified on
 * one side and not the other. `emailVerified` is copied down at sign-in.
 */
class EmailVerificationTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_the_notice_is_shown_to_an_unverified_customer(): void
    {
        $this->signInCustomer(['emailVerified' => false]);

        $this->get('/verify-email')->assertOk();
    }

    public function test_a_verified_customer_is_sent_on(): void
    {
        $this->signInCustomer(['emailVerified' => true]);

        $this->get('/verify-email')->assertRedirect(route('bookings', absolute: false));
    }

    public function test_verification_state_is_copied_down_from_firebase_at_sign_in(): void
    {
        $user = $this->signInCustomer(['emailVerified' => true]);

        $this->assertNotNull($user->email_verified_at);
    }

    public function test_the_resend_button_asks_firebase_to_send_it(): void
    {
        $this->signInCustomer(['emailVerified' => false]);

        $this->post('/email/verification-notification')
            ->assertSessionHas('status', 'verification-link-sent');

        $this->assertFirebaseCalled('accounts:sendOobCode');
    }

    /** Nothing to resend to an address Firebase already considers verified. */
    public function test_nothing_is_sent_to_a_verified_customer(): void
    {
        $this->signInCustomer(['emailVerified' => true]);

        $this->post('/email/verification-notification')
            ->assertRedirect(route('bookings', absolute: false));

        $this->assertFirebaseNotCalled('accounts:sendOobCode');
    }

    public function test_the_signed_url_route_no_longer_exists(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has('verification.verify'),
            'Firebase handles its own verification link; a local one would disagree with the app.'
        );
    }
}

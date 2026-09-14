<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Password reset, run by Firebase.
 *
 * Laravel's broker and its `password_reset_tokens` table are unused: the password
 * lives in Firebase, so a token issued here could not change it. Firebase sends the
 * email and hosts the page that takes the new password.
 */
class PasswordResetTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_the_request_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_firebase_is_asked_to_send_the_link(): void
    {
        $this->fakeFirebase();

        $this->post('/forgot-password', ['email' => 'customer@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertFirebaseCalled('accounts:sendOobCode');
    }

    /**
     * An unknown address gets the same answer as a known one.
     *
     * Otherwise this form is a way to test a list of addresses for which are
     * customers — the same reasoning that makes Firebase answer
     * INVALID_LOGIN_CREDENTIALS instead of "no such user" at sign-in.
     */
    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        $this->fakeFirebaseRefusal('EMAIL_NOT_FOUND');

        $this->post('/forgot-password', ['email' => 'stranger@example.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');
    }

    public function test_an_outage_is_reported_rather_than_swallowed(): void
    {
        $this->fakeFirebaseRefusal('TOO_MANY_ATTEMPTS_TRY_LATER');

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'customer@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_there_is_no_local_reset_form(): void
    {
        $this->assertFalse(
            Route::has('password.reset'),
            'The new password is chosen on Firebase\'s page, not on ours.'
        );
    }
}

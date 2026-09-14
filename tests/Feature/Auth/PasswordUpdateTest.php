<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /**
     * The old password is checked by signing in with it, not by Laravel's
     * `current_password` rule — there is no local hash for that rule to compare
     * against, and Firebase's answer is the one the mobile app would give.
     */
    public function test_the_password_is_changed_in_firebase(): void
    {
        $this->signInCustomer();

        $response = $this->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertFirebaseCalled('accounts:update');
    }

    public function test_the_current_password_must_be_right(): void
    {
        $this->signInCustomer();

        // The re-authentication, and only it, now fails.
        $this->fakeFirebaseRefusal('INVALID_LOGIN_CREDENTIALS');

        $this->from('/profile')->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

    /**
     * A Google account has no password of ours to change. The form is hidden from it;
     * this is the matching refusal for a POST made directly.
     */
    public function test_an_account_without_a_password_can_not_use_this_form(): void
    {
        $user = User::factory()->firebase('google.com')->create();

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertForbidden();
    }

    /** The local mirror must not start holding a password because one was changed. */
    public function test_no_password_is_written_to_the_mirror_row(): void
    {
        $user = $this->signInCustomer();

        $this->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $this->assertNull($user->fresh()->password);
    }
}

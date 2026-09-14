<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->signInCustomer();

        $this->get('/profile')->assertOk();
    }

    /**
     * The name is written to Firebase as well as here, so the mobile app and the admin
     * panel see the change. Firebase first — see ProfileController::update().
     */
    public function test_the_name_is_updated_in_both_places(): void
    {
        $user = $this->signInCustomer(['name' => 'Old Name']);

        $response = $this->patch('/profile', ['name' => 'New Name']);

        $response->assertSessionHasNoErrors()->assertRedirect('/profile');
        $this->assertSame('New Name', $user->fresh()->name);
        $this->assertFirebaseCalled('accounts:update');
    }

    /**
     * The address identifies the Firebase account. The form shows it disabled, and a
     * posted `email` is ignored rather than silently changing our copy only.
     */
    public function test_the_email_address_can_not_be_changed_from_this_form(): void
    {
        $user = $this->signInCustomer(['email' => 'customer@example.com']);

        $this->patch('/profile', [
            'name' => 'New Name',
            'email' => 'someone-else@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('customer@example.com', $user->fresh()->email);
    }

    /**
     * Deleting the mirror row would leave the Firebase account, the Firestore profile
     * and every past booking in place while making the customer invisible here. Closing
     * an account is an operator action — see ProfileController.
     */
    public function test_there_is_no_self_service_account_deletion(): void
    {
        $this->signInCustomer();

        $this->assertFalse(Route::has('profile.destroy'));

        $this->delete('/profile')->assertStatus(405);
    }
}

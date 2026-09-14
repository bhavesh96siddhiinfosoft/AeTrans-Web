<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    /**
     * The account is created in Firebase and mirrored here. The mirror carries the UID
     * — that is the join to Firestore and to the mobile app — and no password.
     */
    public function test_new_customers_register_with_firebase(): void
    {
        $this->fakeFirebase(['email' => 'test@example.com', 'name' => 'Test User']);

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertRedirectedToDashboard($response);
        $this->assertFirebaseCalled('accounts:signUp');

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertSame($this->firebaseUid, $user->uuid);
        $this->assertSame('password', $user->provider);
        $this->assertSame('Test User', $user->name);
        $this->assertNull($user->password);
    }

    /** Firebase, not Laravel, sends the verification email — spec §9, one account. */
    public function test_registration_asks_firebase_to_send_the_verification_email(): void
    {
        $this->fakeFirebase();

        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertFirebaseCalled('accounts:sendOobCode');
    }

    /**
     * Whether the address is taken is Firebase's answer, not a local `unique` rule: a
     * row can exist here with no Firebase account behind it.
     */
    public function test_an_address_firebase_already_knows_is_refused(): void
    {
        $this->fakeFirebaseRefusal('EMAIL_EXISTS');

        $response = $this->from('/register')->post('/register', [
            'name' => 'Test User',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $this->assertSame(0, User::count(), 'A refused registration must leave no row behind.');
    }

    public function test_a_weak_password_is_refused_by_firebase_without_creating_a_row(): void
    {
        $this->fakeFirebaseRefusal('WEAK_PASSWORD', 'Password should be at least 6 characters');

        $this->from('/register')->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}

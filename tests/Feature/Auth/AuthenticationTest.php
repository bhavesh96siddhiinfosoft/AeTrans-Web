<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    /**
     * The credentials go to Firebase, and what comes back is mirrored into a local row.
     * There is no local password for `Auth::attempt()` to check — see LoginRequest.
     */
    public function test_customers_authenticate_against_firebase(): void
    {
        $this->fakeFirebase(['email' => 'customer@example.com', 'name' => 'Sri Wahyuni']);

        $response = $this->post('/login', [
            'email' => 'customer@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertRedirectedToDashboard($response);
        $this->assertFirebaseCalled('accounts:signInWithPassword');

        $user = User::firebaseUid($this->firebaseUid)->firstOrFail();

        $this->assertSame('customer@example.com', $user->email);
        $this->assertSame('password', $user->provider);
        $this->assertNull($user->password, 'The mirror row must never hold a password.');
    }

    /**
     * A row that was created before its Firebase account is ADOPTED, not duplicated —
     * the unique index on `email` would refuse a second one anyway.
     */
    public function test_an_existing_row_without_a_uid_is_adopted(): void
    {
        $existing = User::factory()->create(['email' => 'customer@example.com', 'uuid' => null]);

        $this->fakeFirebase(['email' => 'customer@example.com']);

        $this->post('/login', ['email' => 'customer@example.com', 'password' => 'password']);

        $this->assertAuthenticated();
        $this->assertSame(1, User::count());
        $this->assertSame($this->firebaseUid, $existing->fresh()->uuid);
    }

    public function test_customers_can_not_authenticate_with_invalid_password(): void
    {
        $this->fakeFirebaseRefusal('INVALID_LOGIN_CREDENTIALS');

        $response = $this->from('/login')->post('/login', [
            'email' => 'customer@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $this->assertSame(0, User::count(), 'A failed sign-in must not create a mirror row.');
    }

    /**
     * `blocked` is set by an operator in the admin panel and travels with the customer
     * across all three channels. Spec §9: a blocked customer must not be able to book —
     * which starts with not being able to sign in.
     */
    public function test_a_blocked_customer_is_refused_even_with_the_right_password(): void
    {
        $this->fakeFirebase(['blocked' => true]);

        $response = $this->from('/login')->post('/login', [
            'email' => 'customer@example.com',
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    /** A Firebase account an admin disabled is a separate refusal from `blocked`. */
    public function test_a_disabled_firebase_account_is_refused(): void
    {
        $this->fakeFirebase(['disabled' => true]);

        $this->from('/login')->post('/login', [
            'email' => 'customer@example.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * When Firestore cannot be read the sign-in goes through, by design: the reserve
     * endpoint re-checks `blocked` inside the booking transaction, and locking every
     * customer out over a Firestore hiccup is the worse failure. See CustomerAccounts.
     */
    public function test_a_sign_in_survives_firestore_being_unreachable(): void
    {
        $this->fakeFirebase(['blocked' => 'error']);

        $this->post('/login', ['email' => 'customer@example.com', 'password' => 'password']);

        $this->assertAuthenticated();
    }

    public function test_customers_can_logout(): void
    {
        $user = $this->signInCustomer();

        $response = $this->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
        $this->assertSame($user->id, $user->fresh()->id, 'Logging out must not touch the account.');
    }

    /** Logging out must not leave Firebase tokens behind in the session. */
    public function test_logout_clears_the_firebase_tokens(): void
    {
        $this->signInCustomer();

        $this->post('/logout');

        $this->assertNull(session('firebase.refresh_token'));
        $this->assertNull(session('firebase.id_token'));
    }
}

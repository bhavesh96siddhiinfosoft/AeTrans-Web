<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Google sign-in, from the server's side of it.
 *
 * The browser does the Google half — its window returns a Firebase ID token. What
 * matters here is what this application does with a token someone hands it: it is not
 * trusted on sight, it is looked up with Google first.
 */
class GoogleSignInTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_the_button_is_offered_on_both_auth_pages(): void
    {
        config(['firebase.google_login' => true]);

        foreach (['/login', '/register'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee(__('lang.continue_with_google'))
                ->assertSee('data-google-button', false);
        }
    }

    /**
     * `web_google_login` in the panel's Global Settings is the real switch; until the
     * Firestore reader exists, config stands in for it. Off means off — the endpoint
     * has to refuse too, or the button being hidden is decoration.
     */
    public function test_google_sign_in_is_gone_when_it_is_switched_off(): void
    {
        config(['firebase.google_login' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Continue with Google')
            ->assertDontSee('data-google-button', false);

        $this->fakeFirebase(['provider' => 'google.com']);

        $this->post('/auth/google', ['id_token' => 'anything'])->assertNotFound();
        $this->assertGuest();
    }

    public function test_a_verified_token_signs_the_customer_in_and_records_the_provider(): void
    {
        config(['firebase.google_login' => true]);

        $this->fakeFirebase([
            'provider' => 'google.com',
            'email' => 'customer@gmail.com',
            'name' => 'Budi Santoso',
            'emailVerified' => true,
        ]);

        $response = $this->post('/auth/google', [
            'id_token' => 'token-from-the-browser',
            'refresh_token' => 'refresh-from-the-browser',
        ]);

        $this->assertAuthenticated();
        $this->assertRedirectedToDashboard($response);
        $this->assertFirebaseCalled('accounts:lookup');

        $user = User::firebaseUid($this->firebaseUid)->firstOrFail();

        // Firebase's own provider id, stored raw — it is what the admin panel reads
        // out of the Firestore profile and labels. See config/auth_providers.php.
        $this->assertSame('google.com', $user->provider);
        $this->assertSame('Budi Santoso', $user->name);
        $this->assertNotNull($user->email_verified_at, 'A Google address arrives verified.');
    }

    public function test_a_token_google_rejects_signs_nobody_in(): void
    {
        config(['firebase.google_login' => true]);

        $this->fakeFirebaseRefusal('INVALID_ID_TOKEN');

        $this->from('/login')
            ->post('/auth/google', ['id_token' => 'forged'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_blocked_customer_can_not_come_in_through_google_either(): void
    {
        config(['firebase.google_login' => true]);

        $this->fakeFirebase(['provider' => 'google.com', 'blocked' => true]);

        $this->from('/login')
            ->post('/auth/google', ['id_token' => 'token-from-the-browser'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_endpoint_requires_a_token(): void
    {
        config(['firebase.google_login' => true]);

        $this->from('/login')
            ->post('/auth/google', [])
            ->assertSessionHasErrors('id_token');

        $this->assertGuest();
    }

    /**
     * One person, one account: signing in with Google using the address an
     * email/password account already holds adopts that row rather than making a second.
     * Firebase itself decides whether the two identities are the same account — this
     * only pins that our mirror does not fork.
     */
    public function test_google_adopts_the_row_that_already_holds_the_address(): void
    {
        config(['firebase.google_login' => true]);

        User::factory()->create(['email' => 'customer@gmail.com', 'uuid' => null, 'provider' => 'password']);

        $this->fakeFirebase(['provider' => 'google.com', 'email' => 'customer@gmail.com']);

        $this->post('/auth/google', ['id_token' => 'token-from-the-browser']);

        $this->assertSame(1, User::count());
        $this->assertSame('google.com', User::firstOrFail()->provider);
    }
}

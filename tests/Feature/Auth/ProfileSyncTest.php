<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The Firestore profile document — the customer as the ADMIN PANEL sees them.
 *
 * The panel reads `users` client-side and expects a fixed set of field names
 * (resources/views/users/index.blade.php in the panel: uid, displayName, email, phone,
 * provider, blocked, createdAt, lastLoginAt, updatedAt). This is a contract with
 * another application, so it is pinned here rather than left to be noticed when an
 * operator finds a customer listed with a blank name.
 */
class ProfileSyncTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    public function test_registering_writes_the_profile_the_panel_reads(): void
    {
        $this->fakeFirebase(['email' => 'customer@example.com', 'name' => 'Sri Wahyuni']);

        $this->post('/register', [
            'name' => 'Sri Wahyuni',
            'email' => 'customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $written = $this->profileWrite();

        $this->assertNotNull($written, 'A website sign-up must appear in the panel.');

        $fields = $written->data()['fields'];

        $this->assertSame($this->firebaseUid, $fields['uid']['stringValue']);
        // `displayName`, not `name` — the panel reads the former and would show a blank.
        $this->assertSame('Sri Wahyuni', $fields['displayName']['stringValue']);
        $this->assertSame('customer@example.com', $fields['email']['stringValue']);
        // Firebase's raw provider id, which is what the panel's providerLabel() maps.
        $this->assertSame('password', $fields['provider']['stringValue']);

        // Real Firestore timestamps: the panel formats and sorts on these, and a string
        // would print as raw text and sort alphabetically.
        $this->assertArrayHasKey('timestampValue', $fields['createdAt']);
        $this->assertArrayHasKey('timestampValue', $fields['lastLoginAt']);
        $this->assertArrayHasKey('timestampValue', $fields['updatedAt']);
    }

    /**
     * The write is masked, so it can only touch the fields it names.
     *
     * This is the guard that stops a sign-in from blanking `blocked` and quietly
     * un-suspending a customer an operator had suspended.
     */
    public function test_the_write_can_not_touch_blocked_or_phone(): void
    {
        $this->fakeFirebase(['blocked' => false]);

        $this->post('/login', ['email' => 'customer@example.com', 'password' => 'password']);

        $written = $this->profileWrite();
        $this->assertNotNull($written);

        $this->assertStringNotContainsString('blocked', $written->url());
        $this->assertStringNotContainsString('phone', $written->url());
        $this->assertArrayNotHasKey('blocked', $written->data()['fields']);
        $this->assertArrayNotHasKey('phone', $written->data()['fields']);
    }

    /**
     * `createdAt` records when the customer first appeared. Signing in again is not
     * appearing again, and rewriting it would reorder the panel's list every login.
     */
    public function test_createdAt_is_only_written_for_a_customer_who_had_no_profile(): void
    {
        $this->fakeFirebase(['blocked' => false]);

        $this->post('/login', ['email' => 'customer@example.com', 'password' => 'password']);

        $fields = $this->profileWrite()->data()['fields'];

        $this->assertArrayNotHasKey('createdAt', $fields);
        $this->assertArrayHasKey('lastLoginAt', $fields, 'A returning customer still stamps a login.');
    }

    /** A profile that cannot be written must not cost the customer their sign-in. */
    public function test_a_failed_profile_write_does_not_break_the_sign_in(): void
    {
        $this->fakeFirebase(['blocked' => 'error']);

        $this->post('/login', ['email' => 'customer@example.com', 'password' => 'password']);

        $this->assertAuthenticated();
    }

    /** The PATCH that wrote the profile document, or null if none was sent. */
    private function profileWrite(): ?Request
    {
        return collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0])
            ->first(fn (Request $request) => $request->method() === 'PATCH'
                && str_contains($request->url(), 'firestore.googleapis.com'));
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The two fields this project was set up for: the Firebase UID and the sign-in
 * provider. Both are joins to systems outside this database — Firestore and the
 * mobile app — so their shape is a contract, not an implementation detail.
 */
class AccountFieldsTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /**
     * Registration now creates the Firebase account first, so the UID is known by the
     * time the row is written and the join exists from the customer's first minute.
     *
     * This test used to assert the opposite — that `uuid` stayed null, because nothing
     * created Firebase accounts yet. That is what changed when sign-in moved to
     * Firebase, not a rule that was got wrong.
     */
    public function test_registration_records_the_email_provider_and_the_firebase_uid(): void
    {
        $this->fakeFirebase(['email' => 'customer@example.com']);

        $this->post('/register', [
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('bookings', absolute: false));

        $user = User::where('email', 'customer@example.com')->firstOrFail();

        $this->assertSame('password', $user->provider);
        $this->assertSame($this->firebaseUid, $user->uuid);
        // The provider says this account has a password — in Firebase. The column here
        // stays null; nothing on this site checks it any more.
        $this->assertTrue($user->hasPassword());
        $this->assertNull($user->password);
    }

    /**
     * A Firebase UID is a 28-character base62 string, NOT an RFC-4122 UUID — which is
     * why the column is a VARCHAR. This pins that a real one fits and round-trips.
     */
    public function test_a_firebase_uid_is_stored_and_found_intact(): void
    {
        $uid = 'g7I1L1sOb6dsCn0O1HmAcBgBfg83';

        $user = User::factory()->create(['uuid' => $uid, 'provider' => 'google.com']);

        $this->assertSame($uid, $user->fresh()->uuid);
        $this->assertSame($user->id, User::firebaseUid($uid)->firstOrFail()->id);
    }

    public function test_the_uid_is_unique_across_accounts(): void
    {
        $uid = Str::random(28);
        User::factory()->create(['uuid' => $uid]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        User::factory()->create(['uuid' => $uid]);
    }

    /**
     * Two accounts with no Firebase link must both be allowed. A plain `unique` index
     * permits many NULLs, and losing that would stop a second customer registering.
     */
    public function test_many_accounts_may_have_no_uid(): void
    {
        User::factory()->count(3)->create(['uuid' => null]);

        $this->assertSame(3, User::whereNull('uuid')->count());
    }

    /** @dataProvider providers */
    public function test_each_provider_labels_itself_and_declares_whether_it_holds_a_password(
        string $provider,
        string $label,
        bool $hasPassword
    ): void {
        $user = User::factory()->create(['provider' => $provider]);

        $this->assertSame($label, $user->providerLabel());
        $this->assertSame($hasPassword, $user->hasPassword());
    }

    /** @return array<string, array{string, string, bool}> */
    public static function providers(): array
    {
        return [
            'password' => ['password', 'Email & password', true],
            'phone' => ['phone', 'Phone', false],
            'google' => ['google.com', 'Google', false],
            'apple' => ['apple.com', 'Apple', false],
        ];
    }

    /**
     * An unrecognised provider prints its raw value rather than a tidy fallback, and
     * must not crash. Silently showing "Email & password" for an unknown provider
     * would hide a real data problem behind a plausible label.
     */
    public function test_an_unknown_provider_prints_its_raw_value(): void
    {
        $user = User::factory()->create(['provider' => 'facebook']);

        $this->assertSame('facebook', $user->providerLabel());
        $this->assertFalse($user->hasPassword());
    }

    /**
     * The password screens are only offered to accounts that have a password of ours.
     * A Google customer submitting that form would fail the current-password check
     * with nothing to check against.
     */
    public function test_the_password_form_is_hidden_from_an_account_without_one(): void
    {
        $google = User::factory()->firebase('google.com')->create();

        $this->actingAs($google)->get('/profile')
            ->assertOk()
            ->assertDontSee('update_password_current_password', false);

        $email = User::factory()->create(['provider' => 'password']);

        $this->actingAs($email)->get('/profile')
            ->assertOk()
            ->assertSee('update_password_current_password', false);
    }
}

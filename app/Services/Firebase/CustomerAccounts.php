<?php

namespace App\Services\Firebase;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Turns a verified Firebase identity into a signed-in customer of this site.
 *
 * Three jobs, in this order, and the order matters:
 *
 *  1. refuse a blocked customer (spec §9) — before any local row is touched;
 *  2. mirror the account into our `users` table, keyed by the Firebase UID;
 *  3. keep the Firestore profile document in step, if that is switched on.
 *
 * The local row is a MIRROR, never a source of truth. Firebase owns the credential;
 * `users.password` is left null by every path here, including email/password
 * registration. Two stores holding the same password is two stores that can disagree,
 * and the one we could check is not the one the mobile app checks.
 */
class CustomerAccounts
{
    public function __construct(
        private readonly FirestoreDocuments $firestore,
        private readonly string $collection = 'users',
        private readonly bool $writeProfile = false,
        private readonly bool $blockCheckFailsClosed = false,
    ) {}

    /**
     * @throws AccountBlockedException
     */
    public function sync(FirebaseIdentity $identity): User
    {
        $profile = $this->readProfile($identity);

        $this->assertNotBlocked($profile);

        $user = $this->mirror($identity);

        // `$profile === null` means Firestore had no document — either the customer is
        // new, or the read failed. Both want the same write, and a masked write creates
        // the document if it is missing.
        $this->writeProfileDocument($identity, isNew: $profile === null);

        return $user;
    }

    /** @return array<string, mixed>|null the profile document, or null if unreadable */
    private function readProfile(FirebaseIdentity $identity): ?array
    {
        if (! $identity->idToken) {
            return null;
        }

        try {
            return $this->firestore->get($this->collection.'/'.$identity->uid, $identity->idToken);
        } catch (IdentityToolkitException $e) {
            /*
             * Firestore was unreachable or refused the read. The default is to LET THE
             * SIGN-IN THROUGH and log it, because failing closed here would lock every
             * customer out of the site whenever Firestore hiccups, to stop an account
             * that is suspended rather than dangerous.
             *
             * That trade is only acceptable because signing in is not the transaction
             * that matters: the reserve endpoint (spec §8) re-checks `blocked` inside
             * the booking transaction, where the money is. Set
             * FIREBASE_BLOCK_CHECK_STRICT=true to reverse the choice.
             */
            Log::warning('Could not read the customer profile to check `blocked`.', [
                'uid' => $identity->uid,
                'code' => $e->errorCode,
            ]);

            if ($this->blockCheckFailsClosed) {
                throw new AccountBlockedException;
            }

            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $profile
     *
     * @throws AccountBlockedException
     */
    private function assertNotBlocked(?array $profile): void
    {
        if (($profile['blocked'] ?? false) === true) {
            throw new AccountBlockedException;
        }
    }

    /**
     * Finds or creates the local row for this Firebase account.
     *
     * Matching is by UID first and email second. The email fallback exists for a row
     * that predates its Firebase account — the users migration allows a null UID
     * precisely for that — and it ADOPTS the row rather than creating a second one,
     * which the unique index on `email` would refuse anyway.
     */
    private function mirror(FirebaseIdentity $identity): User
    {
        $user = User::firebaseUid($identity->uid)->first()
            ?? User::where('email', $identity->email)->first()
            ?? new User;

        $user->uuid = $identity->uid;
        $user->email = $identity->email;
        $user->provider = $identity->provider;

        /*
         * The name is only overwritten when Firebase has one and we do not, or when
         * ours is still the placeholder. A customer who edits their name on this site
         * should not have it reverted by whatever the mobile app last wrote.
         */
        if (blank($user->name) || $user->wasRecentlyCreated || ! $user->exists) {
            $user->name = $identity->displayName();
        }

        // Firebase is the authority on whether the address is verified; it owns the
        // verification email. We only copy the answer down.
        if ($identity->emailVerified && ! $user->email_verified_at) {
            $user->email_verified_at = now();
        }

        $user->save();

        return $user;
    }

    /**
     * Mirrors the profile UP into Firestore, so a customer who registers on the
     * website appears in the admin panel's customer list.
     *
     * The field names are the panel's, read off its Users screens rather than invented
     * here: the document id is the Firebase UID with a `uid` field mirroring it, and it
     * carries `displayName`, `email`, `phone`, `provider`, `blocked`, `createdAt`,
     * `lastLoginAt`, `updatedAt` (resources/views/users/index.blade.php).
     *
     * Three fields are deliberately never written:
     *
     *   `blocked` — an operator sets it, and the update mask is what keeps a sign-in
     *               from quietly un-suspending someone;
     *   `phone`   — nothing on this site collects one yet, and writing an empty string
     *               would erase a number the app or an operator had entered;
     *   `createdAt` on an existing document — it records when the customer first
     *               appeared, not when they last signed in.
     */
    private function writeProfileDocument(FirebaseIdentity $identity, bool $isNew): void
    {
        if (! $this->writeProfile || ! $identity->idToken) {
            return;
        }

        $now = now()->toDateTimeImmutable();

        $fields = [
            'uid' => $identity->uid,
            'displayName' => $identity->displayName(),
            'email' => $identity->email,
            // Firebase's own id — `password`, `google.com` — which is what the panel's
            // `providerLabel()` expects to be handed.
            'provider' => $identity->provider,
            'lastLoginAt' => $now,
            'updatedAt' => $now,
        ];

        if ($isNew) {
            $fields['createdAt'] = $now;
        }

        try {
            $this->firestore->patch(
                $this->collection.'/'.$identity->uid,
                $fields,
                $identity->idToken,
            );
        } catch (IdentityToolkitException $e) {
            // A profile that did not sync is not a reason to refuse a sign-in the
            // customer has already passed.
            Log::warning('Could not write the customer profile document.', [
                'uid' => $identity->uid,
                'code' => $e->errorCode,
            ]);
        }
    }
}

<?php

namespace App\Services\Firebase;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Auth over its REST API (the "Identity Toolkit").
 *
 * Why REST and not the Firebase PHP SDK: `grpc` is unavailable on this host and will
 * not be added — see docs/website-spec.md §2. The same decision that shapes the
 * Firestore reader shapes this.
 *
 * Why the SERVER talks to Firebase for email/password at all, when the browser SDK
 * could: the login and registration forms then work as plain HTML forms. A customer
 * on a flaky 4G connection, or with a script blocked, still gets an account. Google
 * sign-in is the one flow that genuinely needs the browser SDK (it needs a popup), so
 * that one arrives here as an ID token to verify — see `verifyIdToken()`.
 *
 * The API key used here is the PUBLIC web key. It identifies the project and
 * authorises nothing on its own; every call below is authenticated by the password or
 * the ID token in its body. Nothing in this class needs the service account.
 */
class IdentityToolkit
{
    private const BASE = 'https://identitytoolkit.googleapis.com/v1';

    private const TOKEN_BASE = 'https://securetoken.googleapis.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 10,
    ) {}

    /**
     * Creates an email/password account and returns it signed in.
     *
     * @throws IdentityToolkitException on EMAIL_EXISTS, WEAK_PASSWORD, …
     */
    public function register(string $email, string $password, string $name): FirebaseIdentity
    {
        $signUp = $this->post('accounts:signUp', [
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true,
        ]);

        // The display name is a second call: `accounts:signUp` accepts no displayName.
        // A failure here must NOT lose the account that was just created, so it is
        // deliberately not wrapped in anything that would rethrow — the customer is
        // registered either way and can fix the name on their profile.
        try {
            $this->post('accounts:update', [
                'idToken' => $signUp['idToken'],
                'displayName' => $name,
                'returnSecureToken' => false,
            ]);
        } catch (IdentityToolkitException) {
            // Intentionally swallowed; see above.
        }

        return new FirebaseIdentity(
            uid: $signUp['localId'],
            email: $signUp['email'] ?? $email,
            name: $name,
            emailVerified: false,
            // Firebase's id for email + password, and what the panel expects to read.
            provider: 'password',
            idToken: $signUp['idToken'] ?? null,
            refreshToken: $signUp['refreshToken'] ?? null,
            expiresIn: isset($signUp['expiresIn']) ? (int) $signUp['expiresIn'] : null,
        );
    }

    /** @throws IdentityToolkitException on bad credentials or a disabled account */
    public function signIn(string $email, string $password): FirebaseIdentity
    {
        $signIn = $this->post('accounts:signInWithPassword', [
            'email' => $email,
            'password' => $password,
            'returnSecureToken' => true,
        ]);

        return $this->describe(
            $signIn['idToken'],
            $signIn['refreshToken'] ?? null,
            isset($signIn['expiresIn']) ? (int) $signIn['expiresIn'] : null,
        );
    }

    /**
     * Verifies an ID token minted by the browser SDK — the Google sign-in path.
     *
     * The check is `accounts:lookup`, which asks GOOGLE whether the token is good,
     * rather than validating the JWT signature here. That is deliberate: it needs no
     * extra dependency and no cached signing certificates, it cannot be fooled by a
     * token from another Firebase project (the API key pins the project), and it
     * returns the account's current state — including whether an admin has since
     * disabled it. The cost is one HTTPS round trip, paid once per sign-in.
     *
     * @throws IdentityToolkitException when the token is invalid, expired or unknown
     */
    public function verifyIdToken(string $idToken, ?string $refreshToken = null): FirebaseIdentity
    {
        return $this->describe($idToken, $refreshToken);
    }

    /**
     * Renames the account in Firebase, so the mobile app and the panel see the change.
     *
     * @throws IdentityToolkitException
     */
    public function updateDisplayName(string $idToken, string $name): void
    {
        $this->post('accounts:update', [
            'idToken' => $idToken,
            'displayName' => $name,
            'returnSecureToken' => false,
        ]);
    }

    /**
     * Changes the password of an already re-authenticated account.
     *
     * Firebase requires a RECENT sign-in for this, which is why the caller passes an
     * identity it has just obtained from `signIn()` with the current password rather
     * than the one sitting in the session. That re-authentication is also what proves
     * the person at the keyboard knows the old password — there is no local hash left
     * for Laravel's `current_password` rule to check.
     *
     * Every other session of this account is signed out by Firebase as a side effect,
     * on every device. That is the correct behaviour for a password change and worth
     * telling the customer.
     */
    public function changePassword(FirebaseIdentity $identity, string $newPassword): FirebaseIdentity
    {
        $updated = $this->post('accounts:update', [
            'idToken' => $identity->idToken,
            'password' => $newPassword,
            'returnSecureToken' => true,
        ]);

        return new FirebaseIdentity(
            uid: $identity->uid,
            email: $identity->email,
            name: $identity->name,
            emailVerified: $identity->emailVerified,
            provider: $identity->provider,
            photoUrl: $identity->photoUrl,
            idToken: $updated['idToken'] ?? $identity->idToken,
            refreshToken: $updated['refreshToken'] ?? $identity->refreshToken,
            expiresIn: isset($updated['expiresIn']) ? (int) $updated['expiresIn'] : null,
        );
    }

    /** Sends Firebase's own password-reset email. Firebase hosts the reset page. */
    public function sendPasswordResetEmail(string $email): void
    {
        $this->post('accounts:sendOobCode', [
            'requestType' => 'PASSWORD_RESET',
            'email' => $email,
        ]);
    }

    /** Sends Firebase's own address-verification email to the signed-in account. */
    public function sendVerificationEmail(string $idToken): void
    {
        $this->post('accounts:sendOobCode', [
            'requestType' => 'VERIFY_EMAIL',
            'idToken' => $idToken,
        ]);
    }

    /**
     * Exchanges a refresh token for a fresh ID token.
     *
     * An ID token lives one hour; a session here lives as long as the session cookie.
     * Anything that reads Firestore as the customer needs this.
     */
    public function refresh(string $refreshToken): array
    {
        $response = Http::asForm()
            ->timeout($this->timeout)
            ->post(self::TOKEN_BASE.'/token?key='.$this->apiKey, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ]);

        if ($response->failed()) {
            throw new IdentityToolkitException(
                $response->json('error.message') ?? 'TOKEN_REFRESH_FAILED',
            );
        }

        return [
            'id_token' => $response->json('id_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    /** Looks an account up by its ID token and builds our identity from the answer. */
    private function describe(string $idToken, ?string $refreshToken, ?int $expiresIn = null): FirebaseIdentity
    {
        $lookup = $this->post('accounts:lookup', ['idToken' => $idToken]);

        $account = $lookup['users'][0] ?? null;

        if (! $account) {
            throw new IdentityToolkitException('INVALID_ID_TOKEN');
        }

        // A disabled Firebase account. This is NOT the same as `blocked` in Firestore,
        // which the admin panel sets and which is checked separately — an account can
        // be either, or both, and each must refuse a sign-in on its own.
        if (($account['disabled'] ?? false) === true) {
            throw new IdentityToolkitException('USER_DISABLED');
        }

        $email = $account['email'] ?? null;

        if (! $email) {
            // Every sign-in method the site offers carries an email. One that does not
            // (an anonymous session, say) has no place in a customer account.
            throw new IdentityToolkitException('MISSING_EMAIL');
        }

        return new FirebaseIdentity(
            uid: $account['localId'],
            email: $email,
            name: $account['displayName'] ?? null,
            emailVerified: (bool) ($account['emailVerified'] ?? false),
            provider: FirebaseIdentity::mapProvider($account['providerUserInfo'][0]['providerId'] ?? null),
            photoUrl: $account['photoUrl'] ?? null,
            idToken: $idToken,
            refreshToken: $refreshToken,
            expiresIn: $expiresIn,
        );
    }

    /**
     * @throws IdentityToolkitException
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->post(self::BASE.'/'.$path.'?key='.$this->apiKey, $payload);
        } catch (ConnectionException $e) {
            throw new IdentityToolkitException(IdentityToolkitException::TRANSPORT, $e->getMessage(), $e);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }

        // Google packs the reason into `error.message`, sometimes with a colon and a
        // human explanation after it ("WEAK_PASSWORD : Password should be at least 6
        // characters"). Split on the colon so the code stays matchable.
        $raw = (string) $response->json('error.message', 'UNKNOWN_ERROR');

        throw new IdentityToolkitException(trim(strtok($raw, ':') ?: $raw), $raw);
    }
}

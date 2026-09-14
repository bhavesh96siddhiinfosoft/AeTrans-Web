<?php

namespace App\Services\Firebase;

use Illuminate\Contracts\Session\Session;

/**
 * Keeps the customer's Firebase tokens for the life of the Laravel session.
 *
 * They are needed after sign-in, not just during it: reading `users/{uid}` — and
 * later every Firestore read made as the customer — is authorised by the ID token.
 *
 * An ID token lives one hour and a session cookie lives far longer, so `idToken()`
 * quietly exchanges the refresh token when the current one is close to expiry. The
 * 60-second margin is there so a token that passes the check does not expire midway
 * through the request that just accepted it.
 *
 * The session driver is `database`, so these tokens sit in MySQL rather than in a
 * cookie the browser can read.
 */
class FirebaseSession
{
    private const ID_TOKEN = 'firebase.id_token';

    private const REFRESH_TOKEN = 'firebase.refresh_token';

    private const EXPIRES_AT = 'firebase.expires_at';

    private const UID = 'firebase.uid';

    private const EXPIRY_MARGIN = 60;

    public function __construct(
        private readonly Session $session,
        private readonly IdentityToolkit $auth,
    ) {}

    public function start(FirebaseIdentity $identity): void
    {
        $this->session->put(self::UID, $identity->uid);
        $this->session->put(self::ID_TOKEN, $identity->idToken);
        $this->session->put(self::REFRESH_TOKEN, $identity->refreshToken);
        $this->session->put(self::EXPIRES_AT, now()->addSeconds($identity->expiresIn ?? 3600)->timestamp);
    }

    public function forget(): void
    {
        $this->session->forget([self::UID, self::ID_TOKEN, self::REFRESH_TOKEN, self::EXPIRES_AT]);
    }

    public function uid(): ?string
    {
        return $this->session->get(self::UID);
    }

    /**
     * A usable ID token, refreshed if needed. Null when there is nothing to refresh
     * with — an old session, or one whose refresh token Firebase has since revoked.
     */
    public function idToken(): ?string
    {
        $token = $this->session->get(self::ID_TOKEN);
        $expiresAt = (int) $this->session->get(self::EXPIRES_AT, 0);

        if ($token && $expiresAt - self::EXPIRY_MARGIN > now()->timestamp) {
            return $token;
        }

        $refreshToken = $this->session->get(self::REFRESH_TOKEN);

        if (! $refreshToken) {
            return null;
        }

        try {
            $fresh = $this->auth->refresh($refreshToken);
        } catch (IdentityToolkitException) {
            // Revoked, or Firebase is unreachable. The caller decides what a missing
            // token means; it is not this class's business to end the session.
            return null;
        }

        $this->session->put(self::ID_TOKEN, $fresh['id_token']);
        $this->session->put(self::REFRESH_TOKEN, $fresh['refresh_token']);
        $this->session->put(self::EXPIRES_AT, now()->addSeconds($fresh['expires_in'])->timestamp);

        return $fresh['id_token'];
    }
}

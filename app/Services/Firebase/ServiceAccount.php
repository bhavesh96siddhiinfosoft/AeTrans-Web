<?php

namespace App\Services\Firebase;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The server's own Firestore credential.
 *
 * A visitor who is not signed in still needs the logo, the site name and the footer,
 * and there is no customer ID token to read those with. That is what a service account
 * is for: this application authenticating as itself.
 *
 * `google/auth` mints the token — the library spec §2 names — and it is used ONLY for
 * the OAuth exchange. Firestore itself is still spoken to over its REST API with plain
 * Guzzle, because `grpc` is unavailable on this host.
 *
 * WITHOUT a credentials file this returns null and reads go out unauthenticated. That
 * works today only because the Firestore rules are still open to anonymous reads —
 * which is itself a launch blocker (spec §13). The day those rules are tightened, a
 * missing key file turns the public pages into fallbacks, so `configured()` exists to
 * let a health check say so before a visitor finds out.
 */
class ServiceAccount
{
    /** Firestore's OAuth scope. `cloud-platform` would also work and grants far more. */
    private const SCOPE = 'https://www.googleapis.com/auth/datastore';

    private const CACHE_KEY = 'firebase.service-account.token';

    public function __construct(private readonly ?string $credentialsPath) {}

    public function configured(): bool
    {
        return $this->credentialsPath !== null && is_file($this->credentialsPath);
    }

    /**
     * An access token for Firestore, or null when there is no key file to sign with.
     *
     * Cached: minting one is an RSA signature plus a round trip to Google, and the
     * token is good for an hour. The 60-second haircut keeps a token that passed the
     * check from expiring midway through the request that accepted it.
     */
    public function token(): ?string
    {
        if (! $this->configured()) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY, now()->addMinutes(50), function () {
            try {
                $credentials = new ServiceAccountCredentials(
                    self::SCOPE,
                    json_decode((string) file_get_contents($this->credentialsPath), true),
                );

                $token = $credentials->fetchAuthToken();

                return $token['access_token'] ?? null;
            } catch (\Throwable $e) {
                /*
                 * A malformed key, a clock too far out of step, no route to Google. The
                 * read that follows goes out unauthenticated and either succeeds (rules
                 * still open) or fails and falls back to config — either way the page
                 * renders, and the reason is in the log rather than on the screen.
                 */
                Log::error('Could not mint a Firestore service-account token.', [
                    'message' => $e->getMessage(),
                ]);

                return null;
            }
        });
    }
}

<?php

namespace App\Services\Firebase;

/**
 * One customer as Firebase Auth knows them, plus the tokens for this session.
 *
 * This is the only shape the rest of the app sees; nothing outside this namespace
 * should be reading Google's JSON key names.
 *
 * `provider` is already mapped to OUR vocabulary (see config/auth_providers.php),
 * because that value is written to `users.provider` here and read by the admin panel
 * off Firestore — the two have to spell it the same way.
 */
class FirebaseIdentity
{
    public function __construct(
        public readonly string $uid,
        public readonly string $email,
        public readonly ?string $name,
        public readonly bool $emailVerified,
        public readonly string $provider,
        public readonly ?string $photoUrl = null,
        public readonly ?string $idToken = null,
        public readonly ?string $refreshToken = null,
        public readonly ?int $expiresIn = null,
    ) {}

    /**
     * Normalises the provider id — it does NOT translate it.
     *
     * Firebase's own ids (`password`, `google.com`, `apple.com`, `phone`) are what get
     * stored, because that is what the admin panel reads out of the Firestore `users`
     * document and prints in its customer list. An earlier version of this mapped them
     * to friendlier words (`email`, `google`); the panel is the authority and it does
     * not use those, so the mapping only made the two sides disagree.
     *
     * The only thing done here is filling in a missing value, which Firebase leaves out
     * for an account with no linked provider record.
     */
    public static function mapProvider(?string $firebaseProviderId): string
    {
        return $firebaseProviderId ?: (string) config('auth_providers.default', 'password');
    }

    /** A display name that is never blank: falls back to the local part of the email. */
    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : ucfirst(strtok($this->email, '@') ?: $this->email);
    }
}

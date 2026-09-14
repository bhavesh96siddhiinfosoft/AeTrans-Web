<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'name',
        'email',
        'provider',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The printable name of this account's sign-in method.
     *
     * Falls back to the RAW STORED VALUE rather than to a blank or to "Email": an
     * account holding a provider we do not know about is something to notice, and a
     * wrong-but-tidy label hides it.
     *
     * The `?? ''` is not defensive padding — the column is NOT NULL with a default, so
     * a saved row always has one, but a model built in memory and not yet refreshed
     * does not, and returning null from a `: string` method is a TypeError that takes
     * the whole page down. Caught by ProfileTest on the first run.
     */
    public function providerLabel(): string
    {
        $provider = $this->provider ?? config('auth_providers.default');

        return (string) ($this->providerConfig()['label'] ?? $provider);
    }

    /**
     * The config entry for this account's provider, or an empty array.
     *
     * Fetched by array key rather than with `config('...providers.'.$provider)`,
     * because the keys are Firebase's provider ids and two of them contain a DOT.
     * `config()` reads a dot as nesting, so `google.com` is looked up as `google` →
     * `com`, finds nothing, and every Google customer is silently labelled
     * "google.com" with no password rights. It looks like a data problem and is not.
     *
     * @return array<string, mixed>
     */
    private function providerConfig(): array
    {
        $provider = $this->provider ?? config('auth_providers.default');

        return config('auth_providers.providers')[$provider] ?? [];
    }

    /**
     * True when this account signs in with a password we hold.
     *
     * Google and phone accounts are authenticated by Firebase, so there is nothing
     * here to check or to change — the password screens must not be offered to them.
     */
    public function hasPassword(): bool
    {
        return (bool) ($this->providerConfig()['has_password'] ?? false);
    }

    /** Finds a customer by their Firebase UID. */
    public function scopeFirebaseUid($query, string $uid)
    {
        return $query->where('uuid', $uid);
    }
}

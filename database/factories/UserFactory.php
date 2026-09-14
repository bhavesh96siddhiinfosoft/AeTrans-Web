<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'provider' => 'password',
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // `uuid` stays null by default — most accounts are not linked to Firebase
            // yet, and a factory that invented one would make the unlinked case, which
            // is the common one, the untested one. Use ->firebase() for a linked user.
        ];
    }

    /**
     * A customer already linked to a Firebase account.
     *
     * The UID shape matters: Firebase issues a 28-character base62 string, not a
     * UUID, and code that assumes a UUID here would pass its tests and fail on real
     * data. See the `uuid` column in the users migration.
     */
    public function firebase(string $provider = 'google.com'): static
    {
        return $this->state(fn (array $attributes) => [
            'uuid' => Str::random(28),
            'provider' => $provider,
            // Google and phone accounts hold no password of ours.
            'password' => $provider === 'password' ? $attributes['password'] : null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

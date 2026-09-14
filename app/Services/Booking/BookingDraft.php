<?php

namespace App\Services\Booking;

use Illuminate\Contracts\Session\Session;

/**
 * A booking being filled in, held in the session until it is submitted.
 *
 * The whole point is that a visitor with no account can fill in every detail, and is
 * only asked to sign in at the last step — the client's instruction of 2026-08-21.
 * That means the draft has to SURVIVE the round trip through the login or registration
 * screen, including a customer who registers, reads a verification email and comes
 * back. The session does that; a hidden field in the form would not, because the sign-in
 * screens are ordinary pages that know nothing about a booking in progress.
 *
 * Nothing here is trusted at submit time. Prices, seat counts and vehicle availability
 * are all re-derived from Firestore when the booking is written — a draft is what the
 * customer typed, not what they get charged. Somebody editing their own session cookie
 * gets no further than somebody editing the form.
 *
 * Charter and shuttle keep separate drafts, so a customer part-way through a shuttle
 * booking who goes to look at charter prices does not lose either.
 */
class BookingDraft
{
    private const KEY = 'booking.draft.';

    public function __construct(private readonly Session $session) {}

    /** @return array<string, mixed> */
    public function all(string $type): array
    {
        return (array) $this->session->get(self::KEY.$type, []);
    }

    public function get(string $type, string $field, mixed $default = null): mixed
    {
        return $this->all($type)[$field] ?? $default;
    }

    public function has(string $type, string ...$fields): bool
    {
        $draft = $this->all($type);

        foreach ($fields as $field) {
            if (! isset($draft[$field]) || $draft[$field] === '' || $draft[$field] === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Merges one step's answers in.
     *
     * Merged rather than replaced so that stepping back to an earlier screen and
     * changing one answer does not wipe the later ones — a customer correcting a date
     * should not have to choose their vehicle again.
     *
     * @param  array<string, mixed>  $values
     */
    public function fill(string $type, array $values): void
    {
        $this->session->put(self::KEY.$type, array_merge($this->all($type), $values));
    }

    /**
     * Drops one field and everything downstream of it.
     *
     * Changing the passenger count can make the chosen vehicle too small, and changing
     * the direction of a shuttle trip makes the chosen route meaningless. Carrying the
     * stale answer forward is how a customer ends up on the review screen with a
     * six-seater booked for nine people.
     */
    public function forgetFields(string $type, string ...$fields): void
    {
        $draft = $this->all($type);

        foreach ($fields as $field) {
            unset($draft[$field]);
        }

        $this->session->put(self::KEY.$type, $draft);
    }

    public function clear(string $type): void
    {
        $this->session->forget(self::KEY.$type);
    }
}

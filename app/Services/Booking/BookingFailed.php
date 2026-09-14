<?php

namespace App\Services\Booking;

use RuntimeException;

/**
 * A booking that could not be written, with a sentence the customer can act on.
 *
 * `taken` is the one that matters: somebody else got the last vehicle between this
 * customer seeing it offered and pressing Confirm. It is not an error in the ordinary
 * sense — the system worked — so the flow sends them back to choose again rather than
 * showing a failure page.
 */
class BookingFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $customerMessage,
    ) {
        parent::__construct($customerMessage);
    }

    public static function taken(): self
    {
        return new self('taken', __('lang.vehicle_just_taken'));
    }

    public static function soldOut(): self
    {
        return new self('sold_out', __('lang.no_vehicle_free'));
    }

    public static function signInAgain(): self
    {
        return new self('session', __('lang.sign_in_again_to_book'));
    }

    public static function unavailable(): self
    {
        return new self('unavailable', __('lang.booking_not_saved'));
    }
}

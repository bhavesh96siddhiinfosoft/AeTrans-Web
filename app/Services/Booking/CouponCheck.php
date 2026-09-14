<?php

namespace App\Services\Booking;

/**
 * What happened when a code was checked: a discount, or the reason there is none.
 *
 * The reason is the finished SENTENCE, already translated. It was a `lang.*` key at
 * first, translated on the way out — but both the check and the screen that shows it
 * happen inside one request with one locale, so the indirection bought nothing and cost
 * the translation coverage scan its ability to see the keys at all.
 */
class CouponCheck
{
    private function __construct(
        public readonly bool $ok,
        public readonly float $discount,
        public readonly string $message,
        public readonly ?array $coupon,
    ) {}

    /** @param  array<string, mixed>  $coupon */
    public static function accepted(array $coupon, float $discount): self
    {
        return new self(true, $discount, '', $coupon);
    }

    /**
     * @param  string  $message  what the customer is told, already translated
     * @param  array<string, mixed>|null  $coupon
     */
    public static function refused(string $message, ?array $coupon = null): self
    {
        return new self(false, 0.0, $message, $coupon);
    }

    /** The coupon's own code, for storing on a booking. */
    public function code(): string
    {
        return (string) ($this->coupon['code'] ?? '');
    }

    public function id(): string
    {
        return (string) ($this->coupon['id'] ?? '');
    }
}

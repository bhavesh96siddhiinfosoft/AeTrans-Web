<?php

namespace App\Services\Booking;

use App\Models\User;
use App\Services\Firebase\Firestore;
use Illuminate\Support\Facades\Cache;

/**
 * Discount codes, as the admin panel defines them.
 *
 * THE COLLECTION IS SINGULAR — `coupon`, not `coupons` — matching `currency`, because
 * that is the name the client uses. The panel says so in its own list view.
 *
 * ── EVERY RULE ON THE DOCUMENT, AND WHY EACH IS CHECKED HERE ────────────────
 *
 *   enable            switched off is invisible, whatever the dates say
 *   startDate/endDate `YYYY-MM-DD` STRINGS, not timestamps; inclusive at both ends
 *   serviceIds[]      which services it may be spent on — the live one covers both
 *   minOrderAmount    a floor on the price BEFORE the discount
 *   discountType      `fixed` or `percent`
 *   discountValue     an amount, or a percentage
 *   maxDiscount       caps a percentage; meaningless for a fixed amount
 *   usageLimit        total redemptions across everybody, against `usedCount`
 *   usageLimitPerUser how many times ONE customer may spend it
 *   firstBookingOnly  only a customer who has never booked before
 *
 * ── HOW A REDEMPTION IS RECORDED, AND WHY THERE IS NO LEDGER ────────────────
 *
 * The BOOKING is the record. A booking carrying `couponId` is a redemption, so the
 * per-customer count is a count of that customer's own bookings — no second collection
 * to keep in step, and nothing that can disagree with the bookings themselves.
 *
 * `usedCount` on the coupon is the global tally, incremented atomically as the booking
 * is written (see `FirestoreWriter::increment`). It is the panel's field and the panel
 * displays it; this only ever adds to it.
 *
 * A booking that is later CANCELLED still counts. The client's call, 2026-09-02: check
 * the customer's usage, then increase it on use. Giving a use back is an operator's
 * decision and the panel is where it would be made.
 *
 * ── WHAT THIS DOES NOT DO ───────────────────────────────────────────────────
 *
 * It never writes to `coupon` except that one increment, and it never decides the final
 * price. `cost` on a booking stays the FULL amount — the client's decision, 2026-09-02 —
 * and the discount sits beside it.
 */
class Coupons
{
    private const CACHE_KEY = 'site.coupons';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $coupons = null;

    public function __construct(
        private readonly Firestore $firestore,
        private readonly int $cacheSeconds = 300,
    ) {}

    public function forget(): void
    {
        $this->coupons = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The coupon with this code, however it was typed.
     *
     * Case- and space-insensitive: a code read off a poster is typed as "01aetrans" and
     * pasted with a trailing space, and refusing that is refusing a real customer a
     * discount the business is advertising.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $code): ?array
    {
        $wanted = $this->normalise($code);

        if ($wanted === '') {
            return null;
        }

        foreach ($this->all() as $id => $coupon) {
            if ($this->normalise((string) ($coupon['code'] ?? '')) === $wanted) {
                return $coupon + ['id' => $id];
            }
        }

        return null;
    }

    /**
     * Whether this customer may spend this code on this booking, and for how much.
     *
     * @param  string  $serviceId  the `services` document id the booking is for
     * @param  float  $amount  the price BEFORE any discount
     */
    public function check(string $code, string $serviceId, float $amount, ?User $customer): CouponCheck
    {
        $coupon = $this->find($code);

        if (! $coupon) {
            return CouponCheck::refused(__('lang.coupon_not_found'));
        }

        if (($coupon['enable'] ?? false) !== true) {
            return CouponCheck::refused(__('lang.coupon_not_available'), $coupon);
        }

        /*
         * Dates are `YYYY-MM-DD` strings on the document, and a string comparison is
         * exactly right for that format — no parsing, no time zone, and inclusive at
         * both ends, which is what "valid until the 20th" means to the person who typed
         * it into the panel.
         */
        $today = now()->toDateString();

        if (($start = (string) ($coupon['startDate'] ?? '')) !== '' && $today < $start) {
            return CouponCheck::refused(__('lang.coupon_not_started'), $coupon);
        }

        if (($end = (string) ($coupon['endDate'] ?? '')) !== '' && $today > $end) {
            return CouponCheck::refused(__('lang.coupon_expired'), $coupon);
        }

        /*
         * An EMPTY `serviceIds` means every service. A coupon restricted to nothing at
         * all would be a coupon nobody can use, which is not what an admin who left the
         * picker alone meant.
         */
        $services = array_filter((array) ($coupon['serviceIds'] ?? []));

        if ($services !== [] && ! in_array($serviceId, $services, true)) {
            return CouponCheck::refused(__('lang.coupon_wrong_service'), $coupon);
        }

        $minimum = $coupon['minOrderAmount'] ?? null;

        if ($minimum !== null && $amount < (float) $minimum) {
            return CouponCheck::refused(__('lang.coupon_below_minimum'), $coupon);
        }

        // Spent everywhere. `usedCount` is the panel's tally and this only reads it.
        $limit = $coupon['usageLimit'] ?? null;

        if ($limit !== null && (int) ($coupon['usedCount'] ?? 0) >= (int) $limit) {
            return CouponCheck::refused(__('lang.coupon_used_up'), $coupon);
        }

        /*
         * The rest is about THIS customer, and a visitor who is not signed in is not yet
         * a customer. The details step asks for a code before the sign-in, so the code
         * is accepted provisionally and checked again at confirm — where there is
         * somebody to check it against.
         */
        if (! $customer) {
            return CouponCheck::accepted($coupon, $this->discountFor($coupon, $amount));
        }

        $history = $this->historyOf($customer, (string) ($coupon['id'] ?? ''));

        if (($coupon['firstBookingOnly'] ?? false) === true && $history['bookings'] > 0) {
            return CouponCheck::refused(__('lang.coupon_first_booking_only'), $coupon);
        }

        $perUser = $coupon['usageLimitPerUser'] ?? null;

        if ($perUser !== null && $history['redemptions'] >= (int) $perUser) {
            return CouponCheck::refused(__('lang.coupon_already_used'), $coupon);
        }

        return CouponCheck::accepted($coupon, $this->discountFor($coupon, $amount));
    }

    /**
     * What the discount comes to on this amount.
     *
     * Never more than the amount itself: a Rp 10,000 coupon on a Rp 8,000 fare makes the
     * trip free, not a booking the business owes money on. Rounded to whole units
     * because the currency is Rupiah — see `Currency::format`.
     *
     * @param  array<string, mixed>  $coupon
     */
    public function discountFor(array $coupon, float $amount): float
    {
        $value = (float) ($coupon['discountValue'] ?? 0);

        if ($value <= 0 || $amount <= 0) {
            return 0.0;
        }

        if ((string) ($coupon['discountType'] ?? 'fixed') === 'percent') {
            $discount = $amount * $value / 100;
            $cap = $coupon['maxDiscount'] ?? null;

            // The cap is what a percentage coupon is bounded by; a fixed one has none,
            // and the panel hides the field for it.
            if ($cap !== null) {
                $discount = min($discount, (float) $cap);
            }
        } else {
            $discount = $value;
        }

        return round(min($discount, $amount));
    }

    /**
     * How much this customer has booked, and how often with this coupon.
     *
     * One pass over their bookings answers both questions — `firstBookingOnly` needs the
     * count of everything they have ever booked, and `usageLimitPerUser` needs the count
     * carrying this coupon.
     *
     * Read whole and filtered here rather than queried: a query on two fields needs a
     * composite index somebody has to create in the console first, and a missing index
     * fails at run time where no test sees it. The same trade `SeatAvailability` makes.
     *
     * @return array{bookings: int, redemptions: int}
     */
    private function historyOf(User $customer, string $couponId): array
    {
        $uuid = (string) ($customer->uuid ?? '');

        if ($uuid === '') {
            return ['bookings' => 0, 'redemptions' => 0];
        }

        $bookings = 0;
        $redemptions = 0;

        foreach ($this->firestore->collection('bookings', 1000) as $booking) {
            if ((string) ($booking['userId'] ?? '') !== $uuid) {
                continue;
            }

            $bookings++;

            if ($couponId !== '' && (string) ($booking['couponId'] ?? '') === $couponId) {
                $redemptions++;
            }
        }

        return ['bookings' => $bookings, 'redemptions' => $redemptions];
    }

    /** Upper case, no spaces — how a code is compared, never how it is stored. */
    private function normalise(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    /**
     * Every coupon, briefly cached.
     *
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        if ($this->coupons !== null) {
            return $this->coupons;
        }

        return $this->coupons = Cache::remember(
            self::CACHE_KEY,
            $this->cacheSeconds,
            fn () => $this->firestore->collection('coupon'),
        );
    }
}

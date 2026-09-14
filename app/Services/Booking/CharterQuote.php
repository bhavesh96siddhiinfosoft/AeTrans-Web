<?php

namespace App\Services\Booking;

/**
 * What a charter trip costs.
 *
 *     price = dailyRate × billableDays + perKmRate × distanceKm
 *
 * with `billableDays` the LARGER of the days the customer is away and the days the
 * distance bills at:
 *
 *     dateDays     = the travel dates inclusive (one day when there is no return date)
 *     distanceDays = max(1, ceil(distanceKm / 500))
 *     billableDays = max(dateDays, distanceDays, minDailyRental)
 *
 * Taking the larger of the two is the point. A three-day trip that covers 200 km is
 * still three days of a driver's life; a one-day trip that covers 900 km is two days
 * of driving whatever the calendar says. Adding them would charge twice for the same
 * day.
 *
 * The 500 km threshold is STRICTLY greater — 500 km is one day, 501 km is two — and it
 * lives in config/bookings.php because the admin panel holds the same number. A
 * threshold written out twice is a threshold that will eventually differ, and then the
 * two surfaces quote different prices for the same trip.
 *
 * ── A RETURN TRIP IS A LOOP, NOT THE OUTBOUND ROAD TWICE ───────────────────
 *
 * The distance measured on step 1 is ONE WAY, through the stops in order: A -> B -> C.
 * A return trip goes home from the LAST stop — A -> B -> C -> A — and step 1 measures
 * that separately and sends it as `roundTripKm`.
 *
 * It used to double the one-way figure instead, which bills A -> B -> C plus C -> B -> A:
 * driving home through every stop the van has already left behind. The client caught it
 * on 2026-09-09 and was right. Measured on a real route — Juanda -> Malang -> Batu and
 * back — doubling billed 243.6 km against the true loop of 230.1 km, 5.9% too much, and
 * it can only ever err in that direction because going home via the stops is never
 * shorter than going home direct.
 *
 * It went unnoticed because with ONE drop-off the two are the same journey: A -> B
 * doubled and A -> B -> A differ only by rounding. It bites from the second stop on.
 *
 * DOUBLING SURVIVES as the fallback, for the one case where there is nothing better: a
 * customer with no Directions API types a single distance by hand and there is no return
 * leg to measure. Better an approximation that errs high than a booking that cannot be
 * priced.
 *
 * Both figures are picked between HERE rather than in the browser: the price the
 * customer is shown and the price the booking is written with both come through this
 * class, and a rule applied in only one of them is a rule that eventually disagrees with
 * itself.
 *
 * `distanceKm` on a finished quote is therefore the TOTAL billed distance, which is what
 * the panel stores and what its price card explains.
 *
 * Every quote carries its own day counts, so a booking can be re-read later and show
 * what it was charged on rather than what today's rates would say.
 */
class CharterQuote
{
    public function __construct(
        public readonly int $dateDays,
        public readonly int $distanceDays,
        public readonly int $billableDays,
        public readonly float $distanceKm,
        public readonly float $dailyRate,
        public readonly float $perKmRate,
        public readonly float $dayCost,
        public readonly float $distanceCost,
        public readonly float $total,
    ) {}

    /**
     * Can a round trip leave and be back on the SAME DAY?
     *
     * The client's rule, 2026-09-07: yes, when the whole round trip is under the
     * distance one day of driving covers. Until then the booking screen refused a
     * same-day return outright, so a customer going 180 km each way — a morning out and
     * home by evening, the commonest charter there is — had to name tomorrow as their
     * return date and be billed two days for it.
     *
     * Deliberately the SAME threshold the price uses rather than a second 500 written
     * beside it: `distanceDays` is already the answer to "how many days of driving is
     * this", so a trip that bills as one day is a trip that can be done in one day. That
     * also means the client can revise one number in `config/bookings.php` and have the
     * calendar and the invoice keep agreeing — the thing this class's own doc warns
     * about.
     *
     * `public/js/charter-trip.js` computes the same answer to decide whether clicking
     * the start date a second time closes the range. It reads the threshold from the
     * page rather than carrying its own copy, for the same reason.
     *
     * @param  float  $distanceKm  the ONE-WAY distance, as step 1 measured it
     */
    public static function sameDayReturnFits(float $distanceKm, ?float $roundTripKm = null): bool
    {
        $threshold = max(1, (int) config('bookings.distance_day_threshold_km', 500));

        return self::returnDistance($distanceKm, $roundTripKm) <= $threshold;
    }

    /**
     * What a return trip actually covers.
     *
     * The measured loop when step 1 could get one, and the outbound doubled when it
     * could not. ONE function, because the calendar's same-day rule and the price have
     * to agree about the length of the same journey — offering a same-day return and
     * then billing two days for it is the disagreement this prevents.
     *
     * Rounded to one decimal, as the panel rounds it: a measured distance is already an
     * estimate and one carrying six decimals only looks precise.
     */
    private static function returnDistance(float $distanceKm, ?float $roundTripKm): float
    {
        if ($roundTripKm !== null && $roundTripKm > 0) {
            return round($roundTripKm, 1);
        }

        return round($distanceKm * 2, 1);
    }

    /**
     * @param  array<string, mixed>  $vehicleType  a `vehicle_types` document
     * @param  float  $distanceKm  the ONE-WAY distance, A -> B -> C
     * @param  string  $tripType  `one_way` or `round_trip`
     * @param  float|null  $roundTripKm  the LOOP, A -> B -> C -> A, when step 1 measured
     *                                   it; null falls back to doubling
     */
    public static function for(
        array $vehicleType,
        string $travelDate,
        ?string $returnDate,
        float $distanceKm,
        string $tripType = 'one_way',
        ?float $roundTripKm = null,
    ): self {
        $threshold = max(1, (int) config('bookings.distance_day_threshold_km', 500));

        if ($tripType === 'round_trip') {
            $distanceKm = self::returnDistance($distanceKm, $roundTripKm);
        }

        $dateDays = self::daysBetween($travelDate, $returnDate);
        $distanceDays = max(1, (int) ceil($distanceKm / $threshold));

        // The admin can set a floor per vehicle type — a minibus nobody sends out for
        // half a day. Absent or zero means no floor.
        $minimum = max(1, (int) ($vehicleType['minDailyRental'] ?? 1));

        $billableDays = max($dateDays, $distanceDays, $minimum);

        $dailyRate = (float) ($vehicleType['dailyRate'] ?? 0);
        $perKmRate = (float) ($vehicleType['perKmRate'] ?? 0);

        $dayCost = $dailyRate * $billableDays;
        $distanceCost = $perKmRate * $distanceKm;

        return new self(
            dateDays: $dateDays,
            distanceDays: $distanceDays,
            billableDays: $billableDays,
            distanceKm: $distanceKm,
            dailyRate: $dailyRate,
            perKmRate: $perKmRate,
            dayCost: $dayCost,
            distanceCost: $distanceCost,
            total: $dayCost + $distanceCost,
        );
    }

    /**
     * Days from the first to the last INCLUSIVE — a trip out and back tomorrow is two
     * days, not one. No return date is a single day.
     */
    private static function daysBetween(string $travelDate, ?string $returnDate): int
    {
        if (! $returnDate || $returnDate <= $travelDate) {
            return 1;
        }

        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $returnDate);

        if (! $start || ! $end) {
            return 1;
        }

        return (int) $start->diff($end)->days + 1;
    }

    /** @return array<string, mixed> the fields a booking snapshots */
    public function toArray(): array
    {
        return [
            'dateDays' => $this->dateDays,
            'distanceDays' => $this->distanceDays,
            'billableDays' => $this->billableDays,
            'distanceKm' => $this->distanceKm,
            'dailyRate' => $this->dailyRate,
            'perKmRate' => $this->perKmRate,
            'cost' => $this->total,
        ];
    }
}

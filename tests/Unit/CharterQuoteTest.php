<?php

namespace Tests\Unit;

use App\Services\Booking\CharterQuote;
use Tests\TestCase;

/**
 * What a charter trip costs.
 *
 * The distance rule is the client's, confirmed on 2026-08-11, and it is STRICTLY
 * greater: 500 km is one day and 501 km is two. Both boundaries are pinned below,
 * because `floor(km / t) + 1` and `ceil(km / t)` agree everywhere EXCEPT on the exact
 * multiples — which is precisely where a customer notices being charged for a day they
 * did not use.
 */
class CharterQuoteTest extends TestCase
{
    private array $type = [
        'name' => 'Hiace Commuter',
        'seatCapacity' => 14,
        'dailyRate' => 1000000,
        'perKmRate' => 3000,
        'minDailyRental' => 1,
    ];

    public function test_a_one_day_trip_is_the_daily_rate_plus_the_distance(): void
    {
        $quote = CharterQuote::for($this->type, '2026-09-01', null, 120);

        $this->assertSame(1, $quote->billableDays);
        $this->assertSame(1_000_000.0, $quote->dayCost);
        $this->assertSame(360_000.0, $quote->distanceCost);
        $this->assertSame(1_360_000.0, $quote->total);
    }

    /**
     * A RETURN TRIP IS A LOOP, not the outbound road twice — the client's correction of
     * 2026-09-09.
     *
     * A -> B -> C -> A. Doubling the one-way figure bills A -> B -> C plus C -> B -> A,
     * driving home through every stop the van has already left. Measured on the real
     * route the client's own fleet drives — Juanda -> Malang -> Batu and back — doubling
     * charged 243.6 km against a true loop of 230.1.
     */
    public function test_a_round_trip_bills_the_measured_loop_not_the_outbound_doubled(): void
    {
        $quote = CharterQuote::for($this->type, '2026-09-01', '2026-09-02', 121.8, 'round_trip', 230.1);

        $this->assertSame(230.1, $quote->distanceKm, 'the loop is what the van drives');
        $this->assertSame(230.1 * 3000, $quote->distanceCost);

        // What it used to charge, kept here so the regression is unmistakable.
        $this->assertNotSame(243.6, $quote->distanceKm);
    }

    /**
     * With ONE drop-off the two are the same journey, which is why this went unnoticed:
     * A -> B doubled and A -> B -> A differ only by rounding.
     */
    public function test_a_single_stop_round_trip_is_unchanged_in_practice(): void
    {
        $doubled = CharterQuote::for($this->type, '2026-09-01', '2026-09-02', 95.3, 'round_trip');
        $measured = CharterQuote::for($this->type, '2026-09-01', '2026-09-02', 95.3, 'round_trip', 189.9);

        $this->assertSame(190.6, $doubled->distanceKm);
        $this->assertSame(189.9, $measured->distanceKm);
        $this->assertLessThan(1.0, abs($doubled->distanceKm - $measured->distanceKm));
    }

    /**
     * DOUBLING SURVIVES where there is nothing better.
     *
     * A customer with no Directions API types one distance by hand; there is no return
     * leg to measure. An approximation that errs high beats a booking that cannot be
     * priced at all.
     */
    public function test_an_unmeasured_return_falls_back_to_doubling(): void
    {
        foreach ([null, 0.0] as $missing) {
            $quote = CharterQuote::for($this->type, '2026-09-01', '2026-09-02', 100.0, 'round_trip', $missing);

            $this->assertSame(200.0, $quote->distanceKm);
        }
    }

    /** A one-way trip never looks at the loop, even when one was measured. */
    public function test_a_one_way_trip_ignores_the_measured_loop(): void
    {
        $quote = CharterQuote::for($this->type, '2026-09-01', null, 121.8, 'one_way', 230.1);

        $this->assertSame(121.8, $quote->distanceKm);
    }

    /**
     * The same-day rule reads the loop too.
     *
     * 260 km each way doubles to 520 — over the 500 km threshold — while the real loop
     * home might be 480 and perfectly drivable in a day. The calendar would have refused
     * a trip the price would then have billed as one day.
     */
    public function test_the_same_day_rule_uses_the_loop_when_there_is_one(): void
    {
        $this->assertFalse(CharterQuote::sameDayReturnFits(260.0), 'doubled, this is 520 km');
        $this->assertTrue(CharterQuote::sameDayReturnFits(260.0, 480.0), 'the loop home is 480');
        $this->assertFalse(CharterQuote::sameDayReturnFits(260.0, 540.0));
    }

    /**
     * Same-day returns, the client's rule of 2026-09-07.
     *
     * The argument is the ONE-WAY distance and the trip drives it twice, so the
     * decision is made on the doubled number: 250 km each way is 500 there and back and
     * fits; 250.1 does not. Pinned at the boundary because that is where a rule written
     * with the wrong comparison looks right.
     *
     * @dataProvider sameDayDistances
     */
    public function test_a_same_day_return_is_offered_only_for_a_trip_that_fits_in_a_day(float $oneWayKm, bool $fits): void
    {
        $this->assertSame(
            $fits,
            CharterQuote::sameDayReturnFits($oneWayKm),
            $oneWayKm.' km each way should '.($fits ? '' : 'not ').'allow a same-day return',
        );
    }

    /** @return array<string, array{float, bool}> */
    public static function sameDayDistances(): array
    {
        return [
            'nothing measured yet' => [0.0, true],
            'a morning out and home by evening' => [180.0, true],
            'the screenshot the client sent' => [181.3, true],
            'exactly the threshold, doubled' => [250.0, true],
            'a rounding step past it' => [250.05, false],
            'plainly too far' => [400.0, false],
        ];
    }

    /**
     * The same-day rule and the price agree, because both read the one threshold.
     *
     * A trip that fits in a day must also BILL as one day. If these two ever disagreed,
     * the calendar would offer a same-day return that the invoice then charged two days
     * for.
     */
    public function test_the_same_day_rule_and_the_billed_days_cannot_disagree(): void
    {
        foreach ([0.0, 180.0, 250.0, 250.05, 400.0] as $oneWayKm) {
            $quote = CharterQuote::for($this->type, '2026-09-01', '2026-09-01', $oneWayKm, 'round_trip');

            $this->assertSame(
                CharterQuote::sameDayReturnFits($oneWayKm),
                $quote->distanceDays === 1,
                $oneWayKm.' km each way disagrees between the calendar rule and the price',
            );
        }
    }

    /** @dataProvider distances */
    public function test_the_distance_threshold_is_strictly_greater(float $km, int $expectedDays): void
    {
        $quote = CharterQuote::for($this->type, '2026-09-01', null, $km);

        $this->assertSame($expectedDays, $quote->distanceDays, $km.' km should bill as '.$expectedDays.' day(s)');
    }

    /** @return array<string, array{float, int}> */
    public static function distances(): array
    {
        return [
            'nothing yet' => [0.0, 1],
            'under' => [499.0, 1],
            'exactly 500' => [500.0, 1],
            'one over' => [501.0, 2],
            'exactly 1000' => [1000.0, 2],
            'one over 1000' => [1001.0, 3],
        ];
    }

    /**
     * The two day counts are compared, not added. A three-day trip covering 200 km is
     * three days of a driver's life; adding the distance day would charge four.
     */
    public function test_the_longer_of_the_two_day_counts_wins(): void
    {
        $longTrip = CharterQuote::for($this->type, '2026-09-01', '2026-09-03', 200);

        $this->assertSame(3, $longTrip->dateDays);
        $this->assertSame(1, $longTrip->distanceDays);
        $this->assertSame(3, $longTrip->billableDays);

        $longDrive = CharterQuote::for($this->type, '2026-09-01', null, 900);

        $this->assertSame(1, $longDrive->dateDays);
        $this->assertSame(2, $longDrive->distanceDays);
        $this->assertSame(2, $longDrive->billableDays);
    }

    /** Out and back tomorrow is two days, not one — the dates are inclusive. */
    public function test_dates_are_counted_inclusively(): void
    {
        $this->assertSame(2, CharterQuote::for($this->type, '2026-09-01', '2026-09-02', 10)->dateDays);
        $this->assertSame(1, CharterQuote::for($this->type, '2026-09-01', '2026-09-01', 10)->dateDays);
    }

    /** A vehicle the admin will not send out for less than three days. */
    public function test_the_types_own_minimum_is_respected(): void
    {
        // `array_merge`, not `+`: the union operator keeps the LEFT operand's value for
        // a key both sides hold, so `+` would have left the minimum at 1 and the test
        // would have passed for the wrong reason.
        $quote = CharterQuote::for(array_merge($this->type, ['minDailyRental' => 3]), '2026-09-01', null, 10);

        $this->assertSame(3, $quote->billableDays);
    }

    public function test_the_quote_records_what_it_charged_on(): void
    {
        $snapshot = CharterQuote::for($this->type, '2026-09-01', null, 501)->toArray();

        // A booking keeps these so it can be re-read later showing what it was charged
        // on, rather than what today's rates would say.
        $this->assertSame(2, $snapshot['distanceDays']);
        $this->assertSame(2, $snapshot['billableDays']);
        $this->assertSame(501.0, $snapshot['distanceKm']);
        $this->assertSame(1000000.0, $snapshot['dailyRate']);
    }
}

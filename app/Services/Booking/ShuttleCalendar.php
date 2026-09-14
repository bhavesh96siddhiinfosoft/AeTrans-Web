<?php

namespace App\Services\Booking;

use App\Services\Site\Catalog;

/**
 * What the shuttle booking calendar knows about the next three months.
 *
 * The charter calendar counts vehicles; this one counts SEATS, and the unit is a run
 * rather than a day. For one city on one leg, every day of the booking window carries a
 * number per departure — "20 October: 12:00 has 7 left, 16:00 has 2, 20:00 is full".
 *
 * The day cell shows the BEST run, because the question a customer is asking while they
 * look at a month is "can I travel that day at all"; the run they then pick shows its own
 * number beside it. A day coloured by the worst run would grey out a date with an empty
 * bus on it.
 *
 * ── WHY THE WHOLE WINDOW IS SENT AT ONCE ────────────────────────────────────
 *
 * Same reasoning as `CharterCalendar`: one page render rather than a request per month,
 * counts only, and no public endpoint added to a site whose Firestore rules are still
 * open to anonymous reads. Nothing from a booking document travels — the seat counts come
 * from `shuttle_trips`, which the panel maintains and which holds no customer data at all.
 */
class ShuttleCalendar
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly SeatAvailability $seats,
    ) {}

    /**
     * The earliest date a customer may travel — tomorrow, not today.
     *
     * The client's rule of 2026-08-21, restated in the panel's 2026-08-26 contract as
     * applying to every service: "no service may be booked for the day it is booked on".
     */
    public function earliest(): string
    {
        return now()->addDays((int) config('bookings.lead_time_days', 1))->toDateString();
    }

    /** The furthest ahead a customer may book — the panel plans no further. */
    public function latest(): string
    {
        return now()->addDays((int) config('bookings.booking_window_days', 90))->toDateString();
    }

    /**
     * Everything the grid needs for one stop on one leg, ready to print as JSON.
     *
     * @param  array<string, mixed>  $rate  the chosen `shuttle_rates` document
     * @return array{minKey: string, maxKey: string, limitedUpto: int, times: array<int, string>, seats: array<string, array<int, int|string|null>>}
     */
    public function payload(array $rate, string $direction): array
    {
        $times = $this->catalog->departureTimes($rate, $direction);
        $airportId = (string) ($rate['airportId'] ?? '');
        $cityGroupId = (string) ($rate['cityGroupId'] ?? '');
        $seats = [];

        foreach ($this->days() as $day) {
            /*
             * Keyed by run INDEX, not by the clock time. Coming back from a city the same
             * bus collects each stop as it reaches it, so two stops on one run show
             * different times — the index is what the seats are actually counted against.
             *
             * A run the admin has called off arrives as `SeatAvailability::CANCELLED`
             * rather than 0, so the grid can say so instead of saying "sold out".
             */
            $seats[$day] = $this->seats->acrossRuns($airportId, $cityGroupId, $direction, $day, $times);
        }

        return [
            'minKey' => $this->earliest(),
            'maxKey' => $this->latest(),
            'limitedUpto' => (int) config('bookings.limited_upto', 3),
            // In `runIndex` order, which is what the whole seat model hangs on.
            'times' => $times,
            'seats' => $seats,
        ];
    }

    /**
     * Every bookable day, as `Y-m-d`.
     *
     * @return array<int, string>
     */
    private function days(): array
    {
        $days = [];
        $cursor = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->earliest());
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->latest());

        if (! $cursor || ! $end) {
            return [];
        }

        while ($cursor <= $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }
}

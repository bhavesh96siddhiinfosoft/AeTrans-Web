<?php

namespace App\Services\Booking;

use App\Services\Firebase\Firestore;
use Illuminate\Support\Facades\Cache;

/**
 * How many shuttle seats are left on one RUN.
 *
 * THE RULE, and it is the one most likely to be got wrong. A seat pool is:
 *
 *     airportId + cityGroupId + direction + date + runIndex
 *
 * Five parts, all of them. Seats belong to a CITY and a RUN — "Juanda → Ngawi City,
 * 20 October, the 16:00 bus, 10 seats" — and every drop point in that city shares those
 * ten. Booking two seats to Ngawi 1 and three to Ngawi 3 on the same bus takes five from
 * the same ten.
 *
 * ── WHY A RUN IS A POSITION AND NOT A CLOCK TIME ────────────────────────────
 *
 * Leaving the airport, every stop in a city departs together. Coming back, the same
 * vehicle collects each stop as it reaches it, so each stop has its own pickup time:
 * 15:15 at one stop and 17:00 at another are THE SAME BUS, run index 1. A pool keyed on
 * the literal time would split one bus into as many pools as it has stops and undercount
 * every one of them.
 *
 * ── WHAT CHANGED, AND HOW IT USED TO BE WRONG ───────────────────────────────
 *
 * Until 2026-08-26 this class matched on airport + direction + date and took the FIRST
 * document it found, because a document was a whole day. It now returns one per city per
 * departure — nine for Juanda outbound across three cities at three departures. Taking
 * the first is reading an arbitrary run's seats and calling them the day's; it will
 * happily show 10 free on a run that is full. The contract
 * (`docs/shuttle-app-integration.md`) calls this out as the part that breaks quietly.
 *
 * ── WHAT THE WEBSITE MAY AND MAY NOT DO ─────────────────────────────────────
 *
 * READ ONLY. `seatsBooked` is the panel's to maintain: it counts `confirmed` and
 * `completed`, and a `pending` booking holds no seat at all. If the website also wrote
 * it, every seat would be counted twice.
 *
 * The consequence is the client's and has to be said on screen: a customer can book the
 * last seat and still be refused, because their order holds nothing until an operator
 * accepts it.
 */
class SeatAvailability
{
    /**
     * What a run reports instead of a number when the admin has called it off.
     *
     * A cancelled run and a full one both have nought seats, and telling a customer the
     * wrong one of those is a real cost: "sold out" invites them to try the next day,
     * while a cancelled bus may be the only thing wrong with an otherwise open route. The
     * panel already draws the two differently — a cancelled cell shows `—`, not `10/10`.
     *
     * Carried as a STRING rather than a negative number so nothing can quietly arithmetic
     * on it: `left - 1` on a sentinel of -1 is a bug that runs.
     */
    public const CANCELLED = 'cancelled';

    private const CACHE_KEY = 'site.shuttle-trips';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $trips = null;

    public function __construct(private readonly Firestore $firestore) {}

    /** Drops the cache, so a test staging a different world is not answered by the last. */
    public function forget(): void
    {
        $this->trips = null;

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Seats still free on one run, or null when it cannot be worked out.
     *
     * Null is not zero. Firestore being unreachable must not read as "sold out" — the
     * screen shows nothing rather than telling a customer something false.
     */
    public function remaining(string $airportId, string $cityGroupId, string $direction, string $date, int $runIndex): ?int
    {
        if ($airportId === '' || $cityGroupId === '' || $date === '' || $runIndex < 0) {
            return null;
        }

        $trip = $this->run($airportId, $cityGroupId, $direction, $date, $runIndex);

        if ($trip === false) {
            return null;
        }

        if ($trip === null) {
            /*
             * A missing document means the DEFAULT, not zero. The admin only writes one
             * when they change a run's capacity or cancel it, so an unplanned run is a
             * full one.
             */
            return $this->defaultSeats();
        }

        // The admin called this run off. No seats, whatever the capacity says.
        if (($trip['status'] ?? null) === 'cancelled') {
            return 0;
        }

        $capacity = (int) ($trip['seatsTotal'] ?? $this->defaultSeats());
        $booked = (int) ($trip['seatsBooked'] ?? 0);

        return max(0, $capacity - $booked);
    }

    /**
     * Whether the admin has called one run off.
     *
     * Separate from `remaining()` on purpose. The booking path only needs to know that
     * nought seats are free, and 0 refuses it correctly whichever the reason; the SCREEN
     * needs to know which reason, because it has to say it.
     */
    public function isCancelled(string $airportId, string $cityGroupId, string $direction, string $date, int $runIndex): bool
    {
        if ($airportId === '' || $cityGroupId === '' || $date === '' || $runIndex < 0) {
            return false;
        }

        $trip = $this->run($airportId, $cityGroupId, $direction, $date, $runIndex);

        return is_array($trip) && ($trip['status'] ?? null) === 'cancelled';
    }

    /**
     * The state of every run of a timetable, keyed by run index.
     *
     * One pass for a whole day's departures, so a screen listing three runs does not ask
     * three times.
     *
     * A value is the seats left, `null` when they could not be read, or
     * `self::CANCELLED` when the run was called off — three states, because a screen that
     * collapses the last two tells a customer a bus is full when it is not running.
     *
     * @param  array<int, string>  $times  the timetable, in `runIndex` order
     * @return array<int, int|string|null>
     */
    public function acrossRuns(string $airportId, string $cityGroupId, string $direction, string $date, array $times): array
    {
        $seats = [];

        foreach (array_keys($times) as $runIndex) {
            $seats[$runIndex] = $this->isCancelled($airportId, $cityGroupId, $direction, $date, (int) $runIndex)
                ? self::CANCELLED
                : $this->remaining($airportId, $cityGroupId, $direction, $date, (int) $runIndex);
        }

        return $seats;
    }

    /**
     * One run's document.
     *
     * @return array<string, mixed>|null|false the document, null when there is none,
     *                                         false when Firestore could not be read
     */
    private function run(string $airportId, string $cityGroupId, string $direction, string $date, int $runIndex): array|null|false
    {
        $trips = $this->all();

        if ($trips === []) {
            /*
             * Empty is genuinely ambiguous — an unreachable Firestore and a fleet that
             * has never had a capacity changed both look like this. Treated as
             * unreadable, because promising the default when the truth is unknown is the
             * error that oversells.
             */
            return false;
        }

        foreach ($trips as $trip) {
            /*
             * All five parts, and `cityGroupId`/`runIndex` must be PRESENT. Documents
             * written before 2026-08-26 carry neither: their `seatsTotal` counted a whole
             * day and cannot be divided into runs. They match no run, and that is
             * correct — the contract says to ignore them rather than fall back to them.
             */
            if (($trip['airportId'] ?? null) === $airportId
                && (string) ($trip['cityGroupId'] ?? '') === $cityGroupId
                && ($trip['direction'] ?? null) === $direction
                && ($trip['date'] ?? null) === $date
                && isset($trip['runIndex'])
                && (int) $trip['runIndex'] === $runIndex) {
                return $trip;
            }
        }

        return null;
    }

    /**
     * Every trip document, briefly cached.
     *
     * Read whole and matched here rather than with a Firestore query. Anything narrower
     * than one equality filter needs a composite index somebody has to create in the
     * console first, and a missing index fails at run time where no test sees it — the
     * same trade the panel makes, for the same reason.
     *
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        if ($this->trips !== null) {
            return $this->trips;
        }

        $seconds = (int) config('bookings.availability_cache_seconds', 30);

        return $this->trips = $seconds < 1
            ? $this->firestore->collection('shuttle_trips')
            : Cache::remember(
                self::CACHE_KEY,
                $seconds,
                fn () => $this->firestore->collection('shuttle_trips'),
            );
    }

    private function defaultSeats(): int
    {
        return (int) config('bookings.default_run_seats', 10);
    }
}

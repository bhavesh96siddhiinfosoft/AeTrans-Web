<?php

namespace Tests\Feature;

use App\Services\Booking\SeatAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The shuttle seat pool.
 *
 *     airportId + cityGroupId + direction + date + runIndex
 *
 * Five parts, all of them. The panel's contract calls the lookup "the part that breaks
 * quietly": a query on airport + direction + date used to return ONE document, because a
 * document was a whole day. It now returns one per city per departure — nine for Juanda
 * outbound across three cities at three departures. Taking the first is reading an
 * arbitrary run's seats and calling them the day's, and it will happily show 10 free on a
 * run that is full.
 *
 * Nothing here has a screen. That is the point: the failure is silent, so the reader is
 * pinned directly.
 */
class ShuttleSeatsTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private const AIRPORT = 'apt-sub';

    private const CITY = 'city-sby';

    private function seats(): SeatAvailability
    {
        return app(SeatAvailability::class);
    }

    /** A run with seats booked against it. */
    private function trip(array $overrides = []): array
    {
        return array_merge([
            'id' => 'trip-1',
            'airportId' => self::AIRPORT,
            'cityGroupId' => self::CITY,
            'cityGroupName' => 'Surabaya City',
            'direction' => 'from_airport',
            'date' => '2026-09-10',
            'runIndex' => 1,
            'runLabel' => '13:15',
            'seatsTotal' => 10,
            'seatsBooked' => 4,
            'status' => 'scheduled',
        ], $overrides);
    }

    public function test_a_run_with_a_document_reports_what_is_left(): void
    {
        $this->fakeCatalog(['shuttle_trips' => ['trip-1' => $this->trip()]]);

        $this->assertSame(6, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 1));
    }

    /**
     * A missing document means the DEFAULT, not zero.
     *
     * The admin only writes one when they change a run's capacity or cancel it, so an
     * unplanned run is a full one.
     */
    public function test_a_run_with_no_document_is_the_default_capacity(): void
    {
        $this->fakeCatalog(['shuttle_trips' => ['trip-1' => $this->trip()]]);

        $this->assertSame(10, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 0));
    }

    /**
     * THE FAILURE THE CONTRACT WARNS ABOUT.
     *
     * Another city's document for the same airport, direction and day must not answer for
     * this one. Every Madiun seat would otherwise be counted as a Surabaya seat.
     */
    public function test_another_citys_run_does_not_answer_for_this_one(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => $this->trip(['cityGroupId' => 'city-ngw', 'seatsBooked' => 10]),
        ]]);

        // Ngawi is full; Surabaya has not been planned and is untouched.
        $this->assertSame(0, $this->seats()->remaining(self::AIRPORT, 'city-ngw', 'from_airport', '2026-09-10', 1));
        $this->assertSame(10, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 1));
    }

    /** And neither does another run of the same city on the same day. */
    public function test_another_run_does_not_answer_for_this_one(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => $this->trip(['runIndex' => 2, 'seatsBooked' => 10]),
        ]]);

        $this->assertSame(0, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 2));
        $this->assertSame(10, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 1));
    }

    /** Nor the other direction, which is a different bus entirely. */
    public function test_the_other_direction_does_not_answer_for_this_one(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => $this->trip(['direction' => 'to_airport', 'seatsBooked' => 10]),
        ]]);

        $this->assertSame(10, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 1));
    }

    /**
     * Documents written before 2026-08-26 carry no city and no run.
     *
     * Their `seatsTotal` counted a whole day and cannot be divided into runs, so they
     * match nothing — the contract says to ignore them rather than fall back to them.
     */
    public function test_a_legacy_whole_day_document_is_ignored(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-old' => [
                'id' => 'trip-old',
                'airportId' => self::AIRPORT,
                'direction' => 'from_airport',
                'date' => '2026-09-10',
                'seatsTotal' => 10,
                'seatsBooked' => 9,
                'status' => 'scheduled',
            ],
        ]]);

        // Not 1. The old number described the whole day and says nothing about this run.
        $this->assertSame(10, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 0));
    }

    /** A cancelled run holds no seats, whatever its capacity says. */
    public function test_a_cancelled_run_has_no_seats(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => $this->trip(['status' => 'cancelled', 'seatsBooked' => 0]),
        ]]);

        $this->assertSame(0, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 1));
    }

    /** Overbooked by an operator is zero, never a negative number on a screen. */
    public function test_seats_never_go_below_zero(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => $this->trip(['seatsTotal' => 10, 'seatsBooked' => 14]),
        ]]);

        $this->assertSame(0, $this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 1));
    }

    /**
     * Null is not zero.
     *
     * Firestore being unreachable must not read as "sold out" — the screen shows nothing
     * rather than telling a customer something false and losing a booking that was free.
     */
    public function test_an_unreadable_collection_reports_nothing_rather_than_none(): void
    {
        $this->fakeCatalog(['shuttle_trips' => []]);

        $this->assertNull($this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 0));
    }

    /** A run index of -1 is a failure marker, never a pool. */
    public function test_a_missing_run_index_is_refused(): void
    {
        $this->fakeCatalog(['shuttle_trips' => ['trip-1' => $this->trip()]]);

        $this->assertNull($this->seats()->remaining(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', -1));
        $this->assertNull($this->seats()->remaining(self::AIRPORT, '', 'from_airport', '2026-09-10', 1));
    }

    /** A whole timetable in one pass, for a screen that lists every departure. */
    public function test_every_run_of_a_day_can_be_read_at_once(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => $this->trip(['runIndex' => 0, 'seatsBooked' => 2]),
            'trip-2' => $this->trip(['id' => 'trip-2', 'runIndex' => 1, 'status' => 'cancelled']),
        ]]);

        $seats = $this->seats()->acrossRuns(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', ['09:00', '13:15', '17:00']);

        // Run 1 reports CANCELLED, not 0 — see the test below for why the difference is
        // worth a third state.
        $this->assertSame([0 => 8, 1 => SeatAvailability::CANCELLED, 2 => 10], $seats);
    }

    /**
     * A CANCELLED run is told apart from a FULL one.
     *
     * The client's report, 2026-08-27: 30 August was cancelled in the panel and the
     * website drew it "Sold out". Both have nought seats and the reasons are opposite —
     * "sold out" tells a customer to try the next day, and the next day is the same bus
     * that is not running. The panel already draws its own cell `—` rather than `10/10`.
     *
     * `remaining()` keeps saying 0, and should: the booking path only needs to refuse,
     * and 0 refuses correctly whichever the reason. It is the SCREEN that needs the
     * distinction, so it is `acrossRuns()` — the one the calendar reads — that carries it.
     */
    public function test_a_cancelled_run_is_not_reported_as_a_full_one(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => $this->trip(['runIndex' => 0, 'status' => 'cancelled']),
            'trip-2' => $this->trip(['id' => 'trip-2', 'runIndex' => 1, 'seatsBooked' => 10]),
        ]]);

        $seats = $this->seats()->acrossRuns(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', ['09:00', '13:15']);

        $this->assertSame(SeatAvailability::CANCELLED, $seats[0], 'a cancelled run');
        $this->assertSame(0, $seats[1], 'a genuinely full run');

        $this->assertTrue($this->seats()->isCancelled(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 0));
        $this->assertFalse($this->seats()->isCancelled(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 1));
    }

    /**
     * A run with no document of its own is not cancelled.
     *
     * The admin writes a document only to change a capacity or call a run off, so the
     * common case — an ordinary untouched departure — has nothing stored. Reading that
     * absence as a cancellation would close the whole timetable.
     */
    public function test_a_run_nobody_has_touched_is_not_cancelled(): void
    {
        $this->fakeCatalog(['shuttle_trips' => ['trip-1' => $this->trip(['runIndex' => 0])]]);

        $this->assertFalse($this->seats()->isCancelled(self::AIRPORT, self::CITY, 'from_airport', '2026-09-10', 2));
        $this->assertFalse($this->seats()->isCancelled(self::AIRPORT, self::CITY, 'from_airport', '2026-09-11', 0));
    }
}

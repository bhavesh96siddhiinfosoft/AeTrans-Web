<?php

namespace Tests\Feature;

use App\Services\Booking\CharterCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The month grid the customer picks their dates on.
 *
 * The counts under each day are the whole point of the screen: a sold-out weekend has
 * to be visible while the customer is still choosing, rather than being discovered by a
 * refusal after they have described an itinerary. A grid that colours a taken day green
 * is worse than no grid, so what goes into the page is pinned here.
 *
 * The privacy line is pinned too. The panel hands its calendar every booking in the
 * system; this page must hand the browser numbers and nothing else.
 */
class BookingCalendarTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private function date(int $days): string
    {
        return now()->addDays($days)->toDateString();
    }

    /** A charter booking that holds `unit-b` for one day. */
    private function bookingOn(string $day, string $status = 'pending', string $unit = 'unit-b'): array
    {
        return ['bookings' => [
            'bk-1' => [
                'id' => 'bk-1', 'vehicleUnitId' => $unit, 'status' => $status,
                'travelDate' => $day, 'returnDate' => $day,
            ],
        ]];
    }

    public function test_the_counts_cover_the_whole_bookable_window(): void
    {
        $this->fakeCatalog();

        $payload = app(CharterCalendar::class)->payload();

        $this->assertSame($this->date(1), $payload['minKey']);
        $this->assertSame($this->date(90), $payload['maxKey']);

        foreach ($payload['types'] as $type) {
            $this->assertArrayHasKey($this->date(1), $type['free']);
            $this->assertArrayHasKey($this->date(90), $type['free']);
            // A day past the window is one the operator has planned nothing for; the
            // grid greys it, and sending counts for it would invite a click.
            $this->assertArrayNotHasKey($this->date(91), $type['free']);
        }
    }

    /**
     * Grouped by vehicle type, with the seat count alongside.
     *
     * The party size is applied in the browser — it is a field the customer is still
     * typing — so the seats have to travel with the counts or the grid cannot re-colour
     * without a round trip.
     */
    public function test_the_counts_are_grouped_by_vehicle_type_with_their_seats(): void
    {
        $this->fakeCatalog();

        $seats = array_column(app(CharterCalendar::class)->payload()['types'], 'seats');

        sort($seats);

        $this->assertSame([6, 14], $seats);
    }

    public function test_a_booking_takes_its_vehicle_out_of_that_day(): void
    {
        $taken = $this->date(5);

        $this->fakeCatalog($this->bookingOn($taken));

        $large = $this->typeSeating(14);

        // The only van of that type is held, so nothing is free — and only on that day.
        $this->assertSame([], $large['free'][$taken]);
        $this->assertCount(1, $large['free'][$this->date(6)]);
    }

    /**
     * A cancelled booking gives its vehicle back.
     *
     * The fleet would silently shrink every time a customer backed out otherwise, and
     * nothing would show it — the van simply stops being offered.
     */
    public function test_a_cancelled_booking_frees_its_vehicle_again(): void
    {
        $day = $this->date(5);

        $this->fakeCatalog($this->bookingOn($day, 'cancelled'));

        $this->assertCount(1, $this->typeSeating(14)['free'][$day]);
    }

    /** A charter holds its van from the moment the order arrives, not once accepted. */
    public function test_a_pending_booking_still_holds_its_vehicle(): void
    {
        $day = $this->date(5);

        $this->fakeCatalog($this->bookingOn($day, 'pending'));

        $this->assertSame([], $this->typeSeating(14)['free'][$day]);
    }

    public function test_a_maintenance_block_takes_its_vehicle_out_of_every_day_it_covers(): void
    {
        $this->fakeCatalog(['availability_blocks' => [
            'blk-1' => [
                'id' => 'blk-1', 'vehicleUnitId' => 'unit-b',
                'startDate' => $this->date(4), 'endDate' => $this->date(6),
            ],
        ]]);

        $large = $this->typeSeating(14);

        $this->assertCount(1, $large['free'][$this->date(3)]);
        $this->assertSame([], $large['free'][$this->date(4)]);
        $this->assertSame([], $large['free'][$this->date(5)]);
        $this->assertSame([], $large['free'][$this->date(6)]);
        $this->assertCount(1, $large['free'][$this->date(7)]);
    }

    /** A type with no vehicle behind it cannot be sold, so it must not colour a day. */
    public function test_a_type_with_no_vehicles_is_left_out(): void
    {
        $this->fakeCatalog(['vehicle_units' => [
            'unit-a' => ['id' => 'unit-a', 'vehicleTypeId' => 'type-small', 'plateNumber' => '001', 'enable' => true, 'order' => 1],
        ]]);

        $this->assertSame([6], array_column(app(CharterCalendar::class)->payload()['types'], 'seats'));
    }

    /**
     * Narrowed once a vehicle has been chosen.
     *
     * A customer who has picked a van and stepped back to change the dates is asking
     * about that van, and a month counting the rest of the fleet answers a question
     * they stopped asking.
     */
    public function test_choosing_a_vehicle_narrows_the_month_to_that_type(): void
    {
        $this->fakeCatalog();

        $payload = app(CharterCalendar::class)->payload('type-large');

        $this->assertSame([14], array_column($payload['types'], 'seats'));
    }

    /**
     * The fleet travels as id and plate, and nothing else.
     *
     * Until 2026-08-26 no vehicle identifier reached the page at all — the days held
     * positions and the customer never chose a van. They choose one now, so the plates
     * are on the screen by design; what must not follow them is the rest of the unit
     * document, which carries the admin's own notes about each vehicle.
     */
    public function test_the_fleet_travels_as_a_plate_and_an_id_only(): void
    {
        $this->fakeCatalog(['vehicle_units' => [
            'unit-b' => [
                'id' => 'unit-b', 'vehicleTypeId' => 'type-large', 'plateNumber' => 'AE 12 XY',
                'enable' => true, 'order' => 1,
                'internalNote' => 'Brakes due in October',
            ],
        ]]);

        $units = $this->typeSeating(14)['units'];

        $this->assertSame([['id' => 'unit-b', 'name' => 'AE 12 XY']], $units);
    }

    /** The days still hold positions into that list, not repeated ids. */
    public function test_the_days_hold_positions_rather_than_repeating_the_fleet(): void
    {
        $this->fakeCatalog();

        $this->assertSame([0], $this->typeSeating(14)['free'][$this->date(5)]);
    }

    public function test_no_customer_details_reach_the_browser(): void
    {
        $this->fakeCatalog(['bookings' => [
            'bk-1' => [
                'id' => 'bk-1', 'vehicleUnitId' => 'unit-b', 'status' => 'confirmed',
                'travelDate' => $this->date(5), 'returnDate' => $this->date(5),
                'customerName' => 'Siti Rahayu', 'customerPhone' => '+62 811 2233 4455',
                'pickupAddress' => 'Jl. Diponegoro 14',
            ],
        ]]);

        $json = json_encode(app(CharterCalendar::class)->payload());

        $this->assertStringNotContainsString('Siti Rahayu', $json);
        $this->assertStringNotContainsString('4455', $json);
        $this->assertStringNotContainsString('Diponegoro', $json);
    }

    public function test_the_dates_step_carries_the_counts_and_the_grid(): void
    {
        $this->fakeCatalog();
        $this->describeTrip();

        $this->get('/book/charter/vehicle')
            ->assertOk()
            ->assertSee('charterTrip', false)
            ->assertSee($this->date(1))
            // ONE grid, as the app has: a return trip picks a range on the same month.
            ->assertSee('id="travel-calendar"', false)
            ->assertDontSee('id="return-calendar"', false);
    }

    /**
     * The native date fields stay, and stay the fields the form posts.
     *
     * The grid is a way of typing into them. A customer whose browser blocks the script
     * — or whose download simply failed — keeps two working date pickers rather than a
     * form with no way to answer it.
     */
    public function test_the_date_fields_survive_for_a_browser_with_no_script(): void
    {
        $this->fakeCatalog();
        $this->describeTrip();

        $this->get('/book/charter/vehicle')
            ->assertOk()
            ->assertSee('id="travelDate" name="travelDate" type="date"', false)
            ->assertSee('id="returnDate" name="returnDate" type="date"', false)
            ->assertSee('min="'.$this->date(1).'"', false)
            ->assertSee('max="'.$this->date(90).'"', false);
    }

    /** Step 1, so the wizard will open the step the calendar lives on. */
    private function describeTrip(): void
    {
        $this->post('/book/charter', [
            'pickupAddress' => 'Jl. Raya Ngawi 1',
            'dropoffAddresses' => ['Juanda Airport'],
            'distanceKm' => 120,
        ]);
    }

    /** The one type in the payload that seats this many. */
    private function typeSeating(int $seats): array
    {
        foreach (app(CharterCalendar::class)->payload()['types'] as $type) {
            if ($type['seats'] === $seats) {
                return $type;
            }
        }

        $this->fail('No vehicle type seating '.$seats.' in the calendar payload.');
    }
}

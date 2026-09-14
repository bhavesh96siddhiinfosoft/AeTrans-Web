<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Sending the booking — the write, and what stops it being written twice.
 *
 * Spec §8: a check made before a write is advisory, because the other channel sells in
 * the gap between the check and the save. The charter path closes that gap by writing
 * the booking and the vehicle's day locks in ONE Firestore transaction, with lock ids
 * that cannot collide silently.
 *
 * The shuttle path deliberately writes NO lock: a seat is taken when the order is
 * accepted, not when it arrives.
 */
class BookingReservationTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private function date(int $days = 3): string
    {
        return now()->addDays($days)->toDateString();
    }

    /** Walks a signed-in customer to the charter details step with a complete draft. */
    private function readyToConfirmCharter(?string $returnDate = null): void
    {
        $this->fakeCatalog();

        $this->post('/book/charter', [
            'pickupAddress' => 'Juanda Airport',
            'pickupLat' => -7.3798, 'pickupLng' => 112.7869,
            'dropoffAddresses' => ['Hotel Majapahit'],
            'dropoffLat' => [-7.2575], 'dropoffLng' => [112.7521],
            'distanceKm' => 120,
        ]);

        $this->post('/book/charter/vehicle', [
            'passengers' => 4,
            'vehicleTypeId' => 'type-large',
            'tripType' => $returnDate ? 'round_trip' : 'one_way',
            'travelDate' => $this->date(),
            'returnDate' => $returnDate,
            'pickupTime' => '08:30',
        ]);

        $this->signInCustomer();
        $this->fakeCatalog();
    }

    /**
     * The details the last step asks for. Sent with every confirm, because the write
     * takes the name and number from here rather than from the account — a hotel may be
     * booking a guest's transfer, and the dispatcher rings whoever is travelling.
     *
     * @param  array<string, mixed>  $override
     */
    /**
     * @param  int  $party  how many names to send. `readyToConfirmCharter()` books four,
     *                      and the server refuses a list that does not match the party
     *                      set on step 2.
     */
    private function confirmCharter(array $override = [], int $party = 4): \Illuminate\Testing\TestResponse
    {
        return $this->post('/book/charter/details', array_merge([
            'customerName' => 'Test Customer',
            'customerPhone' => '+62 811 2233 4455',
            'passengerNames' => array_map(
                fn (int $seat) => 'Passenger '.$seat,
                range(1, max(1, $party)),
            ),
        ], $override));
    }

    /** The shuttle's last step, which asks for the same details as the charter's. */
    /**
     * @param  int  $seats  how many names to send — the form renders one box per seat,
     *                      and the server refuses a list that does not match what was
     *                      bought on step 2
     */
    private function confirmShuttle(array $override = [], int $seats = 1): \Illuminate\Testing\TestResponse
    {
        return $this->post('/book/shuttle/details', array_merge([
            'customerName' => 'Test Customer',
            'customerPhone' => '+62 811 2233 4455',
            'passengerNames' => array_map(
                fn (int $seat) => 'Passenger '.$seat,
                range(1, max(1, $seats)),
            ),
        ], $override));
    }

    public function test_confirming_writes_the_booking_the_panel_reads(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites();

        $this->confirmCharter()->assertRedirect();

        $booking = $this->writtenBooking();

        $this->assertNotNull($booking, 'Confirming must write a booking.');

        // The panel's own field names — a website booking has to read identically to
        // one an operator entered by phone.
        $this->assertSame('charter', $booking['bookingType']);
        $this->assertSame('online', $booking['source']);
        $this->assertSame('pending', $booking['status'], 'The website never accepts its own order.');
        $this->assertSame('type-large', $booking['vehicleTypeId']);
        $this->assertSame('Hiace Commuter', $booking['vehicleTypeName']);
        $this->assertSame(4, $booking['passengers']);
        $this->assertSame(['Hotel Majapahit'], $booking['dropoffAddresses']);
        $this->assertSame('IDR', $booking['currencyCode']);
        $this->assertSame($this->firebaseUid, $booking['userId']);

        // A vehicle is chosen here, not left to the operator — without one the booking
        // holds nothing and the double-selling gap stays open.
        $this->assertNotEmpty($booking['vehicleUnitId']);
    }

    /**
     * The lock ids are deterministic — `{unit}_{day}` — and written "only if absent".
     * That is what makes two customers pressing Confirm at the same second impossible
     * to satisfy twice: Firestore accepts one commit and refuses the other.
     */
    public function test_the_vehicle_is_locked_for_every_day_of_the_trip(): void
    {
        $this->readyToConfirmCharter(returnDate: $this->date(5));
        $this->fakeBookingWrites();

        $this->confirmCharter();

        $locks = $this->writtenLocks();

        // Three days inclusive: day 3, day 4, day 5.
        $this->assertCount(3, $locks);

        foreach ($locks as $id => $lock) {
            $this->assertTrue($lock['createOnly'], 'A lock must refuse to overwrite one that exists.');
            $this->assertSame($id, $lock['fields']['vehicleUnitId'].'_'.$lock['fields']['day']);
        }
    }

    /** A booking that is written must not be sendable again by refreshing the page. */
    public function test_the_draft_is_cleared_once_the_booking_is_sent(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites();

        $this->confirmCharter();

        $this->fakeCatalog();
        $this->get('/book/charter/details')->assertRedirect('/book/charter');
    }

    /**
     * Somebody else took the last van between this customer seeing it offered and
     * pressing Confirm. Not an error — the system worked — so they are sent back to
     * choose, with the rest of the booking intact.
     */
    public function test_a_vehicle_taken_in_the_meantime_sends_the_customer_back_to_choose(): void
    {
        $this->readyToConfirmCharter();

        /*
         * Somebody else's lock is already there, so the commit's "must not exist"
         * precondition refuses it. This is how a taken van now announces itself: the
         * write is TRIED, not checked, because a check made before a write is advisory
         * and the precondition is what actually settles it.
         */
        $this->fakeBookingWrites(commitFails: 'FAILED_PRECONDITION');

        $this->confirmCharter()
            ->assertRedirect('/book/charter/vehicle')
            ->assertSessionHasErrors('booking');

        /*
         * There is deliberately no "nothing was written" assertion here any more. The
         * write IS attempted now — that is the design: the commit's precondition is what
         * decides, so the request goes out and Firestore refuses it. The HTTP fake sees
         * the attempt; the real Firestore stores nothing, because an atomic commit whose
         * precondition fails applies none of its writes.
         *
         * What the customer must never get is a booking, and that is what the redirect
         * back to the vehicle step with an error says.
         */
    }

    /**
     * ABORTED is Firestore saying another writer committed first. It is retried, and
     * when it keeps happening the customer is told rather than sold a van twice.
     */
    public function test_a_contended_commit_never_writes_a_second_booking(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites(commitFails: 'ABORTED');

        $this->confirmCharter()
            ->assertRedirect('/book/charter/vehicle')
            ->assertSessionHasErrors('booking');
    }

    /**
     * A van in the workshop is not for sale, whatever the calendar says.
     *
     * The month grid greys the day (pinned in BookingCalendarTest, which reads the
     * counts the page hands the browser). What is pinned HERE is the half that cannot
     * be got round: the POST is refused, so a hand-made form cannot sell a blocked van.
     */
    public function test_a_blocked_vehicle_cannot_be_chosen(): void
    {
        $this->fakeCatalog([
            'availability_blocks' => [
                'blk-1' => [
                    'id' => 'blk-1', 'vehicleUnitId' => 'unit-b',
                    'startDate' => $this->date(), 'endDate' => $this->date(),
                ],
            ],
        ]);

        $this->post('/book/charter', [
            'pickupAddress' => 'A', 'dropoffAddresses' => ['B'], 'distanceKm' => 10,
        ]);

        $this->from('/book/charter/vehicle')
            ->post('/book/charter/vehicle', [
                'passengers' => 9,
                'vehicleTypeId' => 'type-large',
                'tripType' => 'one_way',
                'travelDate' => $this->date(),
                'pickupTime' => '09:00',
            ])
            ->assertRedirect('/book/charter/vehicle')
            ->assertSessionHasErrors('vehicleTypeId');
    }

    /** A booking someone else holds takes the van out just as a block does. */
    public function test_a_vehicle_already_booked_cannot_be_chosen(): void
    {
        $this->fakeCatalog([
            'bookings' => [
                'bk-1' => [
                    'id' => 'bk-1', 'vehicleUnitId' => 'unit-b', 'status' => 'pending',
                    'travelDate' => $this->date(), 'returnDate' => $this->date(),
                ],
            ],
        ]);

        $this->post('/book/charter', [
            'pickupAddress' => 'A', 'dropoffAddresses' => ['B'], 'distanceKm' => 10,
        ]);

        $this->from('/book/charter/vehicle')
            ->post('/book/charter/vehicle', [
                'passengers' => 9,
                'vehicleTypeId' => 'type-large',
                'tripType' => 'one_way',
                'travelDate' => $this->date(),
                'pickupTime' => '09:00',
            ])
            ->assertSessionHasErrors('vehicleTypeId');
    }

    /**
     * A cancelled booking must give its vehicle back, or the fleet silently shrinks
     * every time a customer backs out.
     */
    public function test_a_cancelled_booking_releases_its_vehicle(): void
    {
        $this->fakeCatalog([
            'bookings' => [
                'bk-1' => [
                    'id' => 'bk-1', 'vehicleUnitId' => 'unit-b', 'status' => 'cancelled',
                    'travelDate' => $this->date(), 'returnDate' => $this->date(),
                ],
            ],
        ]);

        $this->post('/book/charter', [
            'pickupAddress' => 'A', 'dropoffAddresses' => ['B'], 'distanceKm' => 10,
        ]);

        $this->post('/book/charter/vehicle', [
            'passengers' => 9,
            'vehicleTypeId' => 'type-large',
            'tripType' => 'one_way',
            'travelDate' => $this->date(),
            'pickupTime' => '09:00',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/charter/details');
    }

    /**
     * The three answers the last step adds, on the document the panel reads.
     *
     * The phone especially: it is not mirrored into the local `users` table, the write
     * sent an empty string until 2026-08-26, and the panel's customer column was blank
     * for every booking the website took.
     */
    public function test_the_details_step_writes_the_customer_the_dispatcher_rings(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites();

        $this->confirmCharter([
            'customerName' => 'Siti Rahayu',
            'customerPhone' => '+62 811 9999 0000',
            'customerEmail' => 'siti@example.com',
            'notes' => 'Please wait at the south gate.',
        ])->assertRedirect();

        $booking = $this->writtenBooking();

        $this->assertSame('Siti Rahayu', $booking['customerName']);
        $this->assertSame('+62 811 9999 0000', $booking['customerPhone']);
        $this->assertSame('siti@example.com', $booking['customerEmail']);
        $this->assertSame('Please wait at the south gate.', $booking['notes']);
    }

    /** A booking cannot be sent without a number to ring on the morning. */
    public function test_a_booking_without_a_phone_number_is_refused(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites();

        $this->from('/book/charter/details')
            ->confirmCharter(['customerPhone' => ''])
            ->assertSessionHasErrors('customerPhone');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'documents:commit'));
    }

    /**
     * The customer's own answer, not one inferred from the presence of a return date.
     *
     * They mean the same thing today — a round trip is the only way to get a return
     * date — but the panel reads `tripType` to decide how a booking is priced and
     * dispatched, and inferring it would silently rewrite what the customer chose.
     */
    public function test_the_trip_type_is_written_as_the_customer_chose_it(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites();

        $this->confirmCharter();

        $this->assertSame('one_way', $this->writtenBooking()['tripType']);
    }

    public function test_a_round_trip_is_written_as_one_booking_with_twice_the_distance(): void
    {
        $this->readyToConfirmCharter($this->date(5));
        $this->fakeBookingWrites();

        $this->confirmCharter();

        $booking = $this->writtenBooking();

        // ONE booking with a return date, as the app writes it — not two legs.
        $this->assertSame('round_trip', $booking['tripType']);
        $this->assertNotNull($booking['returnDate']);
        // 120 km each way, and the panel stores the TOTAL billed distance.
        $this->assertSame(240.0, (float) $booking['distanceKm']);
    }

    /**
     * A customer who NAMED a van gets that van.
     *
     * Sending a different one would be a booking they did not make — the plate is on the
     * screen when they choose it, and the dispatcher reads the same string back.
     */
    public function test_a_named_vehicle_is_the_one_that_is_locked(): void
    {
        $this->fakeCatalog(['vehicle_units' => [
            'unit-b' => ['id' => 'unit-b', 'vehicleTypeId' => 'type-large', 'plateNumber' => 'AE 11', 'enable' => true, 'order' => 1],
            'unit-c' => ['id' => 'unit-c', 'vehicleTypeId' => 'type-large', 'plateNumber' => 'AE 12', 'enable' => true, 'order' => 2],
        ]]);

        $this->post('/book/charter', [
            'pickupAddress' => 'Juanda Airport', 'dropoffAddresses' => ['Hotel Majapahit'], 'distanceKm' => 120,
        ]);

        $this->post('/book/charter/vehicle', [
            'passengers' => 4,
            'vehicleTypeId' => 'type-large',
            'vehicleUnitId' => 'unit-c',
            'tripType' => 'one_way',
            'travelDate' => $this->date(),
            'pickupTime' => '08:30',
        ])->assertSessionHasNoErrors();

        $this->signInCustomer();
        $this->fakeCatalog(['vehicle_units' => [
            'unit-b' => ['id' => 'unit-b', 'vehicleTypeId' => 'type-large', 'plateNumber' => 'AE 11', 'enable' => true, 'order' => 1],
            'unit-c' => ['id' => 'unit-c', 'vehicleTypeId' => 'type-large', 'plateNumber' => 'AE 12', 'enable' => true, 'order' => 2],
        ]]);
        $this->fakeBookingWrites();

        $this->confirmCharter();

        // Not `unit-b`, which is free, first in the admin's order, and what the
        // reservation would have picked on its own.
        $this->assertSame('unit-c', $this->writtenBooking()['vehicleUnitId']);
    }

    /** Blank means "any available vehicle" — the no-JavaScript case, and the common one. */
    public function test_leaving_the_vehicle_blank_lets_the_reservation_choose(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites();

        $this->confirmCharter();

        $this->assertSame('unit-b', $this->writtenBooking()['vehicleUnitId']);
    }

    /**
     * A van named in a hand-made POST has to be one of that type's, and still free.
     *
     * The dropdown only ever lists free vans of the chosen type; the form field is
     * whatever the browser sends.
     */
    public function test_a_vehicle_that_is_not_free_cannot_be_named(): void
    {
        $this->fakeCatalog([
            // Two vans of the type, so the TYPE is still sellable — the point is that
            // the named one is not, which is a different error and a different message.
            'vehicle_units' => [
                'unit-b' => ['id' => 'unit-b', 'vehicleTypeId' => 'type-large', 'plateNumber' => 'AE 11', 'enable' => true, 'order' => 1],
                'unit-c' => ['id' => 'unit-c', 'vehicleTypeId' => 'type-large', 'plateNumber' => 'AE 12', 'enable' => true, 'order' => 2],
            ],
            'bookings' => [
                'bk-1' => [
                    'id' => 'bk-1', 'vehicleUnitId' => 'unit-b', 'status' => 'confirmed',
                    'travelDate' => $this->date(), 'returnDate' => $this->date(),
                ],
            ],
        ]);

        $this->post('/book/charter', [
            'pickupAddress' => 'A', 'dropoffAddresses' => ['B'], 'distanceKm' => 10,
        ]);

        $this->from('/book/charter/vehicle')
            ->post('/book/charter/vehicle', [
                'passengers' => 4,
                'vehicleTypeId' => 'type-large',
                'vehicleUnitId' => 'unit-b',
                'tripType' => 'one_way',
                'travelDate' => $this->date(),
                'pickupTime' => '08:30',
            ])
            ->assertSessionHasErrors('vehicleUnitId');
    }

    /** A van of ANOTHER type is refused for the same reason. */
    public function test_a_vehicle_of_a_different_type_cannot_be_named(): void
    {
        $this->fakeCatalog();

        $this->post('/book/charter', [
            'pickupAddress' => 'A', 'dropoffAddresses' => ['B'], 'distanceKm' => 10,
        ]);

        $this->from('/book/charter/vehicle')
            ->post('/book/charter/vehicle', [
                'passengers' => 4,
                'vehicleTypeId' => 'type-large',
                // Belongs to `type-small`.
                'vehicleUnitId' => 'unit-a',
                'tripType' => 'one_way',
                'travelDate' => $this->date(),
                'pickupTime' => '08:30',
            ])
            ->assertSessionHasErrors('vehicleUnitId');
    }

    /**
     * The coordinates the panel draws the route from.
     *
     * Missing until 2026-08-27, which is why a booking taken on the website showed
     * "This booking has no coordinates, so there is no route to draw" on the operator's
     * screen while carrying two perfectly good addresses.
     */
    public function test_a_charter_booking_carries_the_points_the_panel_draws(): void
    {
        $this->fakeCatalog();

        $this->post('/book/charter', [
            'pickupAddress' => 'Jl. Ngubalan, Ngawi',
            'pickupLat' => -7.4034,
            'pickupLng' => 111.4462,
            'dropoffAddresses' => ['Juanda Terminal 2'],
            'dropoffLat' => [-7.3797],
            'dropoffLng' => [112.7869],
            'distanceKm' => 191.8,
        ]);

        $this->post('/book/charter/vehicle', [
            'passengers' => 4,
            'vehicleTypeId' => 'type-large',
            'tripType' => 'one_way',
            'travelDate' => $this->date(),
            'pickupTime' => '10:00',
        ]);

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        $this->confirmCharter();

        $written = $this->writtenBookingRaw();

        // Firestore GeoPoints, which is what the panel's reader expects.
        $this->assertSame(
            ['latitude' => -7.4034, 'longitude' => 111.4462],
            $written['pickupPoint']['geoPointValue'],
        );

        $this->assertSame(
            ['latitude' => -7.3797, 'longitude' => 112.7869],
            $written['dropoffPoints']['arrayValue']['values'][0]['geoPointValue'],
        );
    }

    /**
     * A typed address that was never picked has NO point — not one at 0, 0.
     *
     * That spot is in the Gulf of Guinea and would draw on the operator's map as if it
     * were real. The list keeps its length either way, because the panel reads
     * `dropoffPoints[i]` against `dropoffAddresses[i]` and a shorter list would hang
     * every later stop's pin on the wrong address.
     */
    public function test_an_unpicked_address_writes_no_point_and_keeps_its_place(): void
    {
        $this->fakeCatalog();

        $this->post('/book/charter', [
            'pickupAddress' => 'Somewhere nobody picked',
            'dropoffAddresses' => ['First stop', 'Typed but not picked', 'Third stop'],
            // The middle stop has no coordinates at all.
            'dropoffLat' => [-7.1, null, -7.3],
            'dropoffLng' => [112.1, null, 112.3],
            'distanceKm' => 40,
        ]);

        $this->post('/book/charter/vehicle', [
            'passengers' => 4,
            'vehicleTypeId' => 'type-large',
            'tripType' => 'one_way',
            'travelDate' => $this->date(),
            'pickupTime' => '09:00',
        ]);

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        $this->confirmCharter();

        $written = $this->writtenBookingRaw();
        $points = $written['dropoffPoints']['arrayValue']['values'];

        $this->assertSame(['nullValue' => null], $written['pickupPoint']);
        // Three addresses, three slots — the middle one empty, not absent.
        $this->assertCount(3, $points);
        $this->assertSame(['nullValue' => null], $points[1]);
        $this->assertSame(-7.3, $points[2]['geoPointValue']['latitude']);
    }

    // ---- Shuttle --------------------------------------------------------------

    public function test_a_shuttle_booking_is_written_and_holds_no_seat(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 2]);

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        $this->confirmShuttle(seats: 2)->assertRedirect();

        $booking = $this->writtenBooking();

        $this->assertSame('shuttle', $booking['bookingType']);
        $this->assertSame('pending', $booking['status']);
        $this->assertSame(2, $booking['passengers']);
        $this->assertSame(500000.0, $booking['cost']);
        $this->assertSame('Surabaya City Center', $booking['dropPoint']);

        // THE RULE: a pending shuttle order takes no seat, so it writes no lock.
        $this->assertSame([], $this->writtenLocks());
    }

    /**
     * The city and the run, both REQUIRED as of 2026-08-26.
     *
     * They are what the seat pool is keyed on. A booking without them belongs to no pool:
     * it looks complete, it can be confirmed, and it holds no seat at all — which is how a
     * confirmed five-passenger booking came to show as 0 of 10 on the panel's calendar.
     */
    /**
     * A CHARTER CARRIES ITS PASSENGER LIST TOO.
     *
     * The client extended the shuttle rule to charter on 2026-09-02, and the mobile app
     * was already there: one real charter booking in the client's Firestore carries
     * `passengerNames` in exactly this shape.
     */
    public function test_a_charter_booking_carries_a_name_for_every_passenger(): void
    {
        $this->readyToConfirmCharter();
        $this->fakeBookingWrites();

        $this->confirmCharter([
            'customerName' => 'Hotel Majapahit',
            'passengerNames' => ['Guest One', 'Guest Two', 'Guest Three', 'Guest Four'],
        ])->assertRedirect();

        $written = $this->writtenBooking();

        $this->assertSame(
            ['Guest One', 'Guest Two', 'Guest Three', 'Guest Four'],
            $written['passengerNames'],
        );

        // The person booking is not assumed to be travelling.
        $this->assertSame('Hotel Majapahit', $written['customerName']);

        // A LIST on the wire, not a map — see the shuttle test below for why.
        $this->assertArrayHasKey('arrayValue', $this->writtenBookingRaw()['passengerNames']);
    }

    /** A party of four with three names is refused. */
    public function test_a_charter_with_too_few_names_is_refused(): void
    {
        $this->readyToConfirmCharter();

        $this->from('/book/charter/details')
            ->confirmCharter(['passengerNames' => ['One', 'Two', 'Three']])
            ->assertSessionHasErrors('passengerNames');
    }

    /** The charter details step shows one box per passenger, and no more. */
    public function test_the_charter_details_step_shows_one_box_per_passenger(): void
    {
        $this->readyToConfirmCharter();

        $page = $this->get('/book/charter/details')->assertOk();

        foreach ([1, 2, 3, 4] as $seat) {
            $page->assertSee(__('lang.passenger_number', ['number' => $seat]));
        }

        $page->assertDontSee(__('lang.passenger_number', ['number' => 5]));
    }

    /**
     * ONE NAME PER SEAT, AND THEY REACH FIRESTORE AS A LIST.
     *
     * The client's rule, 2026-09-02: *"When booking a transfer service for five people,
     * the names of all five passengers must also be entered (just as in the app)."*
     * `passengerNames` is the app's own field — read off real bookings in the client's
     * Firestore, a plain array of strings, one per seat.
     */
    public function test_a_shuttle_booking_carries_a_name_for_every_seat(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 3]);

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        $this->confirmShuttle([
            'customerName' => 'Ari Yulianto',
            'passengerNames' => ['Ari Yulianto', 'Sari Dewi', 'Budi Santoso'],
        ], seats: 3)->assertRedirect();

        $written = $this->writtenBooking();

        $this->assertSame(['Ari Yulianto', 'Sari Dewi', 'Budi Santoso'], $written['passengerNames']);

        /*
         * A LIST, not an object. Firestore encodes a sparse or string-keyed PHP array as
         * a map, and the mobile app reading it back would find keys where it expects
         * positions.
         */
        $this->assertArrayHasKey('arrayValue', $this->writtenBookingRaw()['passengerNames']);
    }

    /**
     * The passengers are NOT assumed to be the person booking.
     *
     * A hotel books a guest's transfer. One live booking in the client's Firestore has
     * "Test User" holding three seats for "Test Customer 1", "2" and "3".
     */
    public function test_the_passengers_can_be_other_people_entirely(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 2]);

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        $this->confirmShuttle([
            'customerName' => 'Hotel Majapahit',
            'passengerNames' => ['Guest One', 'Guest Two'],
        ], seats: 2)->assertRedirect();

        $written = $this->writtenBooking();

        $this->assertSame('Hotel Majapahit', $written['customerName']);
        $this->assertSame(['Guest One', 'Guest Two'], $written['passengerNames']);
    }

    /** Fewer names than seats is refused — a seat nobody can be checked into. */
    public function test_a_missing_passenger_name_is_refused(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 3]);

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->from('/book/shuttle/details')
            ->confirmShuttle(['passengerNames' => ['Only One', 'And Two']], seats: 3)
            ->assertSessionHasErrors('passengerNames');
    }

    /** And so is a blank box among them. */
    public function test_a_blank_passenger_name_is_refused(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 2]);

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->from('/book/shuttle/details')
            ->confirmShuttle(['passengerNames' => ['Named', '']], seats: 2)
            // Reported against the box it belongs to, not the group.
            ->assertSessionHasErrors('passengerNames.1');
    }

    /** The form shows exactly as many boxes as the customer bought seats. */
    public function test_the_details_step_shows_one_box_per_seat(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 4]);

        $this->signInCustomer();
        $this->fakeCatalog();

        $page = $this->get('/book/shuttle/details')->assertOk();

        foreach ([1, 2, 3, 4] as $seat) {
            $page->assertSee(__('lang.passenger_number', ['number' => $seat]));
        }

        $page->assertDontSee(__('lang.passenger_number', ['number' => 5]));
    }

    public function test_a_shuttle_booking_carries_its_city_and_its_run(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        // The SECOND departure of this stop's outbound timetable — 09:00, then 13:15.
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '13:15', 'passengers' => 1]);

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        $this->confirmShuttle();

        $booking = $this->writtenBooking();

        $this->assertSame('city-sby', $booking['cityGroupId']);
        $this->assertSame('Surabaya City', $booking['cityGroupName']);
        $this->assertSame(1, $booking['runIndex']);
        // A plain string here, even though the rate's own `direction` is an array.
        $this->assertSame('from_airport', $booking['direction']);
    }

    /**
     * The run is a POSITION, and the same position on the way back is the same bus.
     *
     * Coming back from a city the vehicle collects each stop as it reaches it, so two
     * stops on one run show different clock times. Keying seats on the time would split
     * one bus into as many pools as it has stops.
     */
    public function test_the_run_is_the_position_in_the_legs_own_timetable(): void
    {
        $this->fakeCatalog();

        // 11:00 is the second RETURN departure from Tunjungan (04:30, then 11:00).
        $this->post('/book/shuttle', ['direction' => 'to_airport', 'rateId' => 'rate-tunjungan', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '11:00', 'passengers' => 1]);

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        $this->confirmShuttle();

        // The same bus as 11:30 from Surabaya City Center — run 1 either way.
        $this->assertSame(1, $this->writtenBooking()['runIndex']);
    }

    /**
     * A time on no timetable is REFUSED, and nothing is written.
     *
     * It used to be accepted and flagged, back when a seat belonged to the whole day and
     * the departure did not matter. It does now.
     */
    public function test_a_time_on_no_timetable_writes_nothing(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '03:15', 'passengers' => 1])
            ->assertSessionHasErrors('pickupTime');

        $this->signInCustomer();
        $this->fakeCatalog();
        $this->fakeBookingWrites();

        // The draft never got a departure, so the last step sends them back to the start.
        $this->confirmShuttle()->assertRedirect('/book/shuttle');

        $this->assertNull($this->writtenBooking());
    }

    /**
     * Nothing may be written by a visitor who is not signed in.
     *
     * The charter draft is filled in FIRST, because that is the case that matters: a
     * visitor who has reached the last step, seen the price and pressed Confirm is sent
     * to sign in, not turned back to step 1. An empty draft is sent to step 1 instead,
     * which is a different answer to a different question.
     */
    public function test_confirming_requires_an_account(): void
    {
        $this->fakeCatalog();

        $this->post('/book/charter', [
            'pickupAddress' => 'A', 'dropoffAddresses' => ['B'], 'distanceKm' => 10,
        ]);

        $this->post('/book/charter/vehicle', [
            'passengers' => 4,
            'vehicleTypeId' => 'type-large',
            'tripType' => 'one_way',
            'travelDate' => $this->date(),
            'pickupTime' => '09:00',
        ]);

        $this->confirmCharter()->assertRedirect('/login');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'documents:commit'));
    }
}

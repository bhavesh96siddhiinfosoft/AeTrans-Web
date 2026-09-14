<?php

namespace Tests\Feature;

use App\Services\Booking\BookingDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Filling in a booking as a visitor, and being asked to sign in only at the end.
 *
 * The client's instruction of 2026-08-21: a customer can complete every step without
 * an account, and the sign-in is asked for once, on the screen with the Confirm button.
 * That narrows spec §9, which allowed guest checkout outright.
 *
 * The draft surviving the trip through the login screen is the part most likely to
 * break silently — a customer who has to fill the form in twice does not fill it in
 * twice, they leave. It is pinned below.
 */
class BookingFlowTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private function date(int $days = 3): string
    {
        return now()->addDays($days)->toDateString();
    }

    // ---- Charter --------------------------------------------------------------

    /**
     * Step 1: the route. The distance is the ONE-WAY figure the browser measured.
     *
     * @param  array<string, mixed>  $override
     */
    private function describeRoute(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/book/charter', array_merge([
            'pickupAddress' => 'Jl. Raya Ngawi 1',
            'dropoffAddresses' => ['Juanda Airport'],
            'distanceKm' => 120,
        ], $override));
    }

    /**
     * Step 2: the party, the vehicle, the trip type, the dates and the pickup time —
     * one screen, as the app has it.
     *
     * @param  array<string, mixed>  $override
     */
    private function chooseTrip(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/book/charter/vehicle', array_merge([
            'passengers' => 4,
            'vehicleTypeId' => 'type-large',
            'tripType' => 'one_way',
            'travelDate' => $this->date(),
            'pickupTime' => '08:30',
        ], $override));
    }

    public function test_a_visitor_with_no_account_can_reach_the_details_step(): void
    {
        $this->fakeCatalog();

        $this->get('/book/charter')->assertOk();

        $this->describeRoute()->assertRedirect('/book/charter/vehicle');
        $this->chooseTrip()->assertRedirect('/book/charter/details');

        $this->assertGuest();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('Hiace Commuter')
            // 1 day at 1,000,000 + 120 km at 3,000.
            ->assertSee('Rp 1,360,000')
            // …and the sign-in prompt where the details form would be.
            ->assertSee('Log in');
    }

    /**
     * The party size filters the car types in the BROWSER, so the seat count has to
     * travel with each option or the filter has nothing to read.
     */
    public function test_the_car_types_carry_their_seats_for_the_party_filter(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->get('/book/charter/vehicle')
            ->assertOk()
            ->assertSee('Hiace Commuter')
            ->assertSee('Xenia')
            ->assertSee('data-seats="14"', false)
            ->assertSee('data-seats="6"', false);
    }

    /**
     * The vehicle field, and the searchable dropdowns drawn over both selects.
     *
     * The selects are what post, and are what a customer with no JavaScript uses; the
     * script hides them and mounts a Select2-style dropdown over each. If the mounts go
     * missing the page still works, which is exactly why it needs pinning.
     */
    public function test_the_vehicle_field_is_offered_with_a_searchable_dropdown(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->get('/book/charter/vehicle')
            ->assertOk()
            ->assertSee('name="vehicleTypeId"', false)
            ->assertSee('name="vehicleUnitId"', false)
            ->assertSee('Any available vehicle')
            ->assertSee('id="vehicle-type-picker"', false)
            ->assertSee('id="vehicle-unit-picker"', false)
            ->assertSee('js/searchable-select.js', false);
    }

    /**
     * The phone number is taken with its country code, as the panel takes one.
     *
     * The hidden input is what posts — the FULL international number — so the server's
     * rules do not change and the number the dispatcher rings is unambiguous.
     */
    public function test_the_phone_number_is_asked_for_with_a_country_code(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();
        $this->chooseTrip();

        // Signing in replaces the whole stub set, so the catalogue is staged again
        // afterwards — the details step re-reads it to price the booking.
        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('id="customerPhone" name="customerPhone"', false)
            ->assertSee('id="customerPhone-picker"', false)
            ->assertSee('js/country-select.js', false)
            ->assertSee('js/dropdown-position.js', false)
            // The list itself, and the flag it draws each row with. The base URL is
            // inside a `@json`, where a slash is escaped — `flags\/120`.
            ->assertSee('Indonesia')
            ->assertSee('flags', false);
    }

    /** Every step replaces the browser's validation bubbles with the site's messages. */
    public function test_every_step_carries_the_field_validation(): void
    {
        $this->fakeCatalog();

        $this->get('/book/charter')->assertSee('js/form-validate.js', false);

        $this->describeRoute();
        $this->get('/book/charter/vehicle')->assertSee('js/form-validate.js', false);

        $this->chooseTrip();
        $this->get('/book/charter/details')->assertSee('js/form-validate.js', false);
    }

    /**
     * The client's rule: more than seven passengers and the seven-seater cannot be
     * selected. The list never offers a vehicle too small — but the value arrives in a
     * form field, and a form field is whatever the browser sends.
     */
    public function test_a_vehicle_too_small_is_refused_even_when_posted_directly(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->from('/book/charter/vehicle')
            ->chooseTrip(['passengers' => 9, 'vehicleTypeId' => 'type-small'])
            ->assertRedirect('/book/charter/vehicle')
            ->assertSessionHasErrors('vehicleTypeId');
    }

    /**
     * A charter is an itinerary. Every stop is kept, in order — the van drives through
     * them in that order and the distance was measured through them in that order.
     */
    public function test_several_drop_offs_are_kept_in_order_with_their_coordinates(): void
    {
        $this->fakeCatalog();

        $this->describeRoute([
            'pickupAddress' => 'Juanda Airport',
            'pickupLat' => -7.3798, 'pickupLng' => 112.7869,
            'dropoffAddresses' => ['Hotel Majapahit', 'Tunjungan Plaza', 'Ngawi'],
            'dropoffLat' => [-7.2575, -7.2620, -7.4034],
            'dropoffLng' => [112.7521, 112.7380, 111.4464],
            'distanceKm' => 185.1,
        ])->assertSessionHasNoErrors();

        $this->chooseTrip();

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSeeInOrder(['Hotel Majapahit', 'Tunjungan Plaza', 'Ngawi'])
            ->assertSee('185.1 km');
    }

    /**
     * A return trip drives the same road back, so it bills for twice it — the rule the
     * admin panel applies, and the reason the trip type is a question of its own rather
     * than being inferred from the presence of a return date.
     */
    public function test_a_round_trip_bills_twice_the_distance(): void
    {
        $this->fakeCatalog();
        $this->describeRoute(['distanceKm' => 100]);

        $this->chooseTrip([
            'tripType' => 'round_trip',
            'travelDate' => $this->date(),
            'returnDate' => $this->date(4),
        ])->assertSessionHasNoErrors();

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('200 km')
            // Two days away, 200 km: 2 × 1,000,000 + 200 × 3,000.
            ->assertSee('Rp 2,600,000');
    }

    public function test_a_round_trip_without_a_return_date_is_refused(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->from('/book/charter/vehicle')
            ->chooseTrip(['tripType' => 'round_trip'])
            ->assertSessionHasErrors('returnDate');
    }

    /**
     * The address boxes start EMPTY.
     *
     * `config('bookings.default_address')` is where the MAP opens when a field has no
     * pin yet — it is not a value put into the field. Prefilling was built first and
     * withdrawn on the client's correction the same day: a box carrying an address the
     * customer never chose reads as their answer, and with both boxes holding the same
     * place the trip measured 0 km and could be booked that way.
     */
    public function test_the_address_boxes_start_empty_and_the_default_is_only_the_maps(): void
    {
        $this->fakeCatalog();

        $default = config('bookings.default_address');

        $this->get('/book/charter')
            ->assertOk()
            ->assertDontSee($default['address'])
            ->assertSee('name="pickupLat" value=""', false);

        // It reaches the picker instead, through the endpoint the loader reads.
        $this->getJson('/maps-key')
            ->assertOk()
            ->assertJsonPath('defaultPlace.address', $default['address'])
            ->assertJsonPath('defaultPlace.lat', $default['lat'])
            ->assertJsonPath('defaultPlace.lng', $default['lng']);
    }

    /**
     * The measured loop survives step 1 and reaches the price.
     *
     * The client's correction of 2026-09-09: a return trip is A -> B -> C -> A, not the
     * outbound distance doubled. Step 1 measures both and this is the one a round trip
     * is billed on.
     */
    public function test_a_round_trip_is_billed_on_the_measured_loop(): void
    {
        $this->fakeCatalog();

        // Two stops, so the loop and the doubled figure genuinely differ.
        $this->describeRoute([
            'dropoffAddresses' => ['Malang', 'Batu'],
            'distanceKm' => 121.8,
            'roundTripKm' => 230.1,
        ]);

        $this->chooseTrip([
            'tripType' => 'round_trip',
            'travelDate' => $this->date(),
            // The day after: two days away, so the DATE days and the DISTANCE days do
            // not mask each other in the total below.
            'returnDate' => $this->date(4),
        ])->assertSessionHasNoErrors();

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('230.1 km')
            // 2 days x 1,000,000 + 230.1 x 3,000
            ->assertSee('Rp 2,690,300')
            // Not the doubled figure, nor what it would have cost.
            ->assertDontSee('243.6 km')
            ->assertDontSee('Rp 2,730,800');
    }

    /**
     * With no Directions API the customer types one distance by hand, and there is no
     * loop to measure. Doubling stays for that case — it is the only answer available.
     */
    public function test_a_hand_typed_distance_still_doubles_for_a_return_trip(): void
    {
        $this->fakeCatalog();
        $this->describeRoute(['distanceKm' => 100, 'roundTripKm' => null]);

        $this->chooseTrip([
            'tripType' => 'round_trip',
            'travelDate' => $this->date(),
            // The day after: two days away, so the DATE days and the DISTANCE days do
            // not mask each other in the total below.
            'returnDate' => $this->date(4),
        ])->assertSessionHasNoErrors();

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('200 km');
    }

    /**
     * There and back on the SAME DAY, the client's request of 2026-09-07.
     *
     * 120 km each way is 240 there and back — one day's driving — so the customer may
     * name the day they leave as the day they come back. Before this they had to put
     * tomorrow and be billed for two days.
     */
    public function test_a_short_round_trip_can_come_back_the_same_day(): void
    {
        $this->fakeCatalog();
        $this->describeRoute(['distanceKm' => 120]);

        $day = $this->date();

        $this->chooseTrip([
            'tripType' => 'round_trip',
            'travelDate' => $day,
            'returnDate' => $day,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/charter/details');

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            // One day billed, not two: 1,000,000 + 240 × 3,000.
            ->assertSee('Rp 1,720,000');
    }

    /**
     * And refused when the road is too long for it, on the SERVER — the calendar will
     * not offer it, but the calendar is a suggestion and the form field is whatever
     * arrived.
     */
    public function test_a_long_round_trip_cannot_come_back_the_same_day(): void
    {
        $this->fakeCatalog();
        // 400 each way is 800 there and back: two days of driving whatever the calendar
        // says, so there is no same-day version of this trip to sell.
        $this->describeRoute(['distanceKm' => 400]);

        $day = $this->date();

        $this->from('/book/charter/vehicle')
            ->chooseTrip([
                'tripType' => 'round_trip',
                'travelDate' => $day,
                'returnDate' => $day,
            ])
            ->assertSessionHasErrors('returnDate');
    }

    /** A one-way booking must not carry a return date it would then be billed for. */
    public function test_a_return_date_is_dropped_from_a_one_way_trip(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->chooseTrip(['tripType' => 'one_way', 'returnDate' => $this->date(6)])
            ->assertSessionHasNoErrors();

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertDontSee('Return date');
    }

    /**
     * The distance arrives in a hidden input, which is exactly as forgeable as a
     * visible one — so it is validated as if a person had typed it.
     */
    public function test_a_nonsense_distance_is_refused(): void
    {
        $this->fakeCatalog();

        $this->from('/book/charter')
            ->describeRoute(['distanceKm' => 'quite far'])
            ->assertSessionHasErrors('distanceKm');
    }

    /** At least one drop-off, always: a trip with no destination is not a trip. */
    public function test_a_trip_with_no_destination_is_refused(): void
    {
        $this->fakeCatalog();

        $this->from('/book/charter')
            ->describeRoute(['dropoffAddresses' => []])
            ->assertSessionHasErrors('dropoffAddresses');
    }

    /**
     * Coordinates are optional. When maps are unavailable the fields degrade to plain
     * text and the customer types a distance — that booking must still go through.
     */
    public function test_a_booking_without_coordinates_still_completes(): void
    {
        $this->fakeCatalog();

        $this->describeRoute([
            'pickupAddress' => 'Ngawi bus station',
            'dropoffAddresses' => ['Juanda Airport'],
            'distanceKm' => 190,
        ])->assertSessionHasNoErrors()->assertRedirect('/book/charter/vehicle');
    }

    /**
     * Refreshing a step starts the booking over — the client's rule of 2026-08-21.
     *
     * The browser is what notices the refresh (a reload is byte-for-byte the same GET
     * as a link, so the server cannot tell), and it sends the customer here. This pins
     * the half the server owns: arriving throws the draft away and returns to step 1.
     */
    public function test_restarting_throws_the_booking_away(): void
    {
        $this->fakeCatalog();

        $this->describeRoute();
        $this->chooseTrip();

        $this->get('/book/charter/restart')->assertRedirect('/book/charter');

        // Nothing left to carry the customer past step 1.
        $this->get('/book/charter/vehicle')->assertRedirect('/book/charter');
        $this->get('/book/charter')->assertOk()->assertDontSee('Jl. Raya Ngawi 1');
    }

    /** Every step carries the detector, or a refresh on that step would be missed. */
    public function test_every_step_can_notice_a_refresh(): void
    {
        $this->fakeCatalog();

        $this->get('/book/charter')->assertSee('book/charter/restart', false);

        $this->describeRoute();
        $this->get('/book/charter/vehicle')->assertSee('book/charter/restart', false);

        $this->chooseTrip();
        $this->get('/book/charter/details')->assertSee('book/charter/restart', false);
    }

    /**
     * The reason the detector keys on `reload` and nothing else: coming back from the
     * sign-in screen is an ordinary navigation, and it must not lose the booking.
     */
    public function test_the_sign_in_round_trip_still_keeps_the_draft(): void
    {
        $this->fakeCatalog();

        $this->describeRoute();
        $this->chooseTrip();

        // The POST is the gate, not the page: a visitor pressing Confirm is sent to
        // sign in, having already been shown what they were signing in for.
        $this->post('/book/charter/details', [
            'customerName' => 'Budi', 'customerPhone' => '+62 811 1111 1111',
        ])->assertRedirect('/login');

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')->assertOk()->assertSee('Jl. Raya Ngawi 1');
    }

    /**
     * A visitor SEES this step — the summary, the price, and a sign-in button where the
     * form would be. Middleware would have bounced them to the login screen without
     * ever showing them what they were about to pay for.
     */
    public function test_the_details_step_shows_a_visitor_the_price_and_a_way_in(): void
    {
        $this->fakeCatalog();

        $this->describeRoute();
        $this->chooseTrip();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('Rp 1,360,000')
            ->assertSee('Log in')
            ->assertSee('Create an account')
            // The form itself is not there to be filled in.
            ->assertDontSee('name="customerPhone"', false);
    }

    /** Signing in from that button comes back here, not to the home page. */
    public function test_a_visitor_is_returned_to_the_details_step_after_signing_in(): void
    {
        $this->fakeCatalog();

        $this->describeRoute();
        $this->chooseTrip();

        $this->get('/book/charter/details')->assertOk();

        $this->assertSame(route('book.charter.details'), session('url.intended'));
    }

    /**
     * The whole point of holding the draft in the session: a customer who signs in
     * comes back to a filled-in summary, not an empty form.
     */
    public function test_the_draft_survives_signing_in(): void
    {
        $this->fakeCatalog();

        $this->describeRoute();
        $this->chooseTrip();

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('Hiace Commuter')
            ->assertSee('Jl. Raya Ngawi 1')
            ->assertSee('Rp 1,360,000');
    }

    /**
     * The name and phone the dispatcher rings on the morning, filled in from the
     * profile the panel reads and writes. The phone is not mirrored into the local
     * `users` table, so that document is the only place it can come from.
     */
    public function test_the_details_form_is_filled_in_from_the_customers_profile(): void
    {
        $this->fakeCatalog();

        $this->describeRoute();
        $this->chooseTrip();

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/charter/details')
            ->assertOk()
            ->assertSee('+62 811 2233 4455')
            ->assertSee('Test Customer');
    }

    public function test_a_half_finished_draft_cannot_reach_the_details_step(): void
    {
        $this->fakeCatalog();
        $this->actingAsCustomer();

        $this->describeRoute();

        $this->get('/book/charter/details')->assertRedirect('/book/charter');
    }

    /**
     * No same-day travel, on either service — the client's rule of 2026-08-21.
     *
     * A website booking arrives as `pending` and has to be read, priced and accepted by
     * a person before a driver is given the job, none of which can be relied on to
     * happen before a customer expects collecting this afternoon.
     */
    public function test_todays_date_is_refused_on_a_charter(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->from('/book/charter/vehicle')
            ->chooseTrip(['travelDate' => now()->toDateString()])
            ->assertSessionHasErrors('travelDate');
    }

    public function test_todays_date_is_refused_on_a_shuttle(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', [
                'travelDate' => now()->toDateString(),
                'pickupTime' => '09:00',
                'passengers' => 1,
            ])->assertSessionHasErrors('travelDate');
    }

    /** Tomorrow is the first date that works — the rule is a lead time, not a ban. */
    public function test_tomorrow_is_accepted_on_both_services(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->chooseTrip(['travelDate' => now()->addDay()->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/charter/details');

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->post('/book/shuttle/trip', [
            'travelDate' => now()->addDay()->toDateString(),
            'pickupTime' => '09:00',
            'passengers' => 1,
        ])->assertSessionHasNoErrors();
    }

    /** Yesterday too, obviously — and by the same rule rather than a separate one. */
    public function test_a_past_date_is_refused(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->from('/book/charter/vehicle')
            ->chooseTrip(['travelDate' => now()->subDay()->toDateString()])
            ->assertSessionHasErrors('travelDate');
    }

    /** The picker must not offer a date the server will refuse. */
    public function test_the_date_pickers_do_not_offer_today(): void
    {
        $this->fakeCatalog();

        $tomorrow = now()->addDay()->toDateString();

        $this->describeRoute();
        $this->get('/book/charter/vehicle')->assertOk()->assertSee('min="'.$tomorrow.'"', false);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->get('/book/shuttle/trip')->assertOk()->assertSee('min="'.$tomorrow.'"', false);
    }

    public function test_a_date_beyond_the_planning_window_is_refused(): void
    {
        $this->fakeCatalog();
        $this->describeRoute();

        $this->from('/book/charter/vehicle')
            ->chooseTrip(['travelDate' => now()->addDays(400)->toDateString()])
            ->assertSessionHasErrors('travelDate');
    }

    // ---- Shuttle --------------------------------------------------------------

    public function test_a_visitor_can_choose_a_stop_and_a_departure(): void
    {
        $this->fakeCatalog();

        $this->get('/book/shuttle')
            ->assertOk()
            ->assertSee('Juanda Surabaya Airport')
            // The CITY is named alongside the stop, because the city is what the seats
            // belong to — every Surabaya stop shares one bus.
            ->assertSee('Surabaya City')
            ->assertSee('Surabaya City Center')
            ->assertSee('Rp 250,000');

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1'])
            ->assertRedirect('/book/shuttle/trip');

        $this->get('/book/shuttle/trip')
            ->assertOk()
            // This stop's published outbound departures, and only those.
            ->assertSee('09:00')
            ->assertSee('13:15');

        $this->assertGuest();
    }

    /**
     * The timetable shown is the one for the LEG being booked.
     *
     * A route is one document carrying both, and `departureTimes` is keyed by direction.
     * Reading the wrong half offers the customer times no bus runs in their direction.
     */
    public function test_each_leg_shows_its_own_timetable(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'to_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->get('/book/shuttle/trip')
            ->assertOk()
            ->assertSee('05:00')
            ->assertSee('11:30')
            // The outbound times belong to the other leg.
            ->assertDontSee('13:15');
    }

    /**
     * THE DEPARTURE IS A CLOSED LIST, and this is the rule that replaced the free field.
     *
     * A time on no timetable has no `runIndex`, and the contract is emphatic that a
     * booking without one "looks complete, it can be confirmed, and it holds no seat at
     * all". The panel closed the same field on 2026-08-25 for the same reason.
     */
    public function test_a_time_on_no_timetable_is_refused(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', [
                'travelDate' => $this->date(5),
                'pickupTime' => '03:15',
                'passengers' => 2,
            ])
            ->assertSessionHasErrors('pickupTime');
    }

    /** And a time from the OTHER leg's timetable is refused for the same reason. */
    public function test_a_time_from_the_other_leg_is_refused(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', [
                'travelDate' => $this->date(5),
                // A real departure — but only on the way back.
                'pickupTime' => '05:00',
                'passengers' => 2,
            ])
            ->assertSessionHasErrors('pickupTime');
    }

    // ---- What the stop is called ----------------------------------------------

    /**
     * GOING TO THE AIRPORT, THE STOP IS A PICKUP POINT.
     *
     * It is the same row of the timetable in both directions, but the customer is set
     * down at it on the way out of the airport and collected from it on the way in.
     * "Drop point" on the return leg told them the opposite of what happens.
     * Client's report, 2026-09-02.
     */
    public function test_the_stop_is_a_pickup_point_on_the_way_to_the_airport(): void
    {
        $this->fakeCatalog();

        $this->get('/book/shuttle?direction=to_airport')
            ->assertOk()
            ->assertSee(__('lang.pickup_point'))
            ->assertDontSee(__('lang.drop_point'));
    }

    /** And a drop point on the way out of it, which is what it always was. */
    public function test_the_stop_is_a_drop_point_on_the_way_from_the_airport(): void
    {
        $this->fakeCatalog();

        $this->get('/book/shuttle?direction=from_airport')
            ->assertOk()
            ->assertSee(__('lang.drop_point'))
            ->assertDontSee(__('lang.pickup_point'));
    }

    /**
     * The option names the stop and NOT its city.
     *
     * Every option in the list is in the city chosen in the field above, so the prefix
     * was identical on all of them — and on a phone it was what pushed the actual stop
     * out of view: "Ngawi City · Ngaw…". Client's report, 2026-09-02.
     */
    public function test_a_stop_is_listed_without_its_city_in_front_of_it(): void
    {
        $this->fakeCatalog();

        $this->get('/book/shuttle?direction=from_airport')
            ->assertOk()
            ->assertSee('Ngawi (Pasar Karangjati)')
            // The prefix that used to sit in front of it, and swallowed the phone's width.
            ->assertDontSee('Ngawi City · Ngawi (Pasar Karangjati)');
    }

    // ---- The terminal ---------------------------------------------------------

    /**
     * The airport's terminals are OFFERED, and one of them is kept.
     *
     * The panel has let an admin name an airport's terminals since it was built, and
     * until 2026-09-01 nothing read them — not this site, not the spec, not the mobile
     * app contract. A shuttle collects a passenger AT a terminal; the driver needs to
     * know which.
     */
    public function test_the_airports_terminals_are_offered_and_stored(): void
    {
        $this->fakeCatalog();

        $this->get('/book/shuttle?direction=from_airport')
            ->assertOk()
            ->assertSee('Terminal 1')
            ->assertSee('Terminal 2');

        $this->post('/book/shuttle', [
            'direction' => 'from_airport',
            'rateId' => 'rate-city',
            'terminal' => 'Terminal 2',
        ])->assertRedirect('/book/shuttle/trip');

        $this->assertSame('Terminal 2', app(BookingDraft::class)->get('shuttle', 'terminal'));
    }

    /**
     * A terminal that is not on the airport's list is refused.
     *
     * The field is a dropdown, so anything else was typed by hand — and a terminal the
     * driver cannot find is worse than none at all.
     */
    public function test_a_terminal_the_airport_does_not_have_is_refused(): void
    {
        $this->fakeCatalog();

        $this->from('/book/shuttle')
            ->post('/book/shuttle', [
                'direction' => 'from_airport',
                'rateId' => 'rate-city',
                'terminal' => 'Terminal 9',
            ])
            ->assertSessionHasErrors('terminal');
    }

    /** And so is leaving it out, when the airport publishes some. */
    public function test_a_terminal_is_required_where_the_airport_has_them(): void
    {
        $this->fakeCatalog();

        $this->from('/book/shuttle')
            ->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city'])
            ->assertSessionHasErrors('terminal');
    }

    /**
     * An airport with NO terminals does not ask, and does not block.
     *
     * The client's rule, 2026-09-01: *"check any Airport having a Terminal if yes then
     * show Terminals dropdown otherwise don't show that dropdown"*. A required field
     * with no possible answer would make that airport unbookable.
     */
    public function test_an_airport_without_terminals_does_not_ask(): void
    {
        $catalog = $this->catalogWithoutTerminals();

        $this->fakeCatalog($catalog);

        $this->get('/book/shuttle?direction=from_airport')
            ->assertOk()
            ->assertDontSee('Terminal 1');

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/shuttle/trip');

        $this->assertSame('', app(BookingDraft::class)->get('shuttle', 'terminal'));
    }

    /** The same airport, with the `terminals` field taken off it. */
    private function catalogWithoutTerminals(): array
    {
        return ['airports' => [
            'apt-sub' => [
                'id' => 'apt-sub', 'name' => 'Juanda Surabaya Airport', 'city' => 'Surabaya',
                'code' => 'SUB', 'enable' => true, 'order' => 1,
            ],
        ]];
    }

    /**
     * A leg can be SUSPENDED — dropped from `direction` while its timetable stays.
     *
     * That is how an admin stops a direction running without losing the schedule, so a
     * non-empty timetable on its own does not mean the leg is sellable.
     */
    public function test_a_stop_that_does_not_run_the_leg_is_not_offered(): void
    {
        $this->fakeCatalog();

        // Ngawi runs outbound only.
        $this->get('/book/shuttle?direction=from_airport')->assertOk()->assertSee('Ngawi');
        $this->get('/book/shuttle?direction=to_airport')->assertOk()->assertDontSee('Ngawi');

        $this->from('/book/shuttle')
            ->post('/book/shuttle', ['direction' => 'to_airport', 'rateId' => 'rate-ngawi'])
            ->assertSessionHasErrors('rateId');
    }

    /**
     * The stop list the route step's dropdown narrows itself with.
     *
     * Catalogue only — what the business sells, already printed on the page. No booking,
     * no customer, no seat count: that is the line the calendar endpoints stay behind.
     */
    public function test_the_stop_list_can_be_fetched_for_a_leg(): void
    {
        $this->fakeCatalog();

        $this->getJson('/book/shuttle/stops?direction=from_airport')
            ->assertOk()
            ->assertJsonCount(3, 'stops')
            ->assertJsonPath('stops.0.city', 'Surabaya City')
            // The timetable for THIS leg, in run order.
            ->assertJsonPath('stops.0.times', ['09:00', '13:15']);

        // Narrowed to a city.
        $this->getJson('/book/shuttle/stops?direction=from_airport&city=city-ngw')
            ->assertOk()
            ->assertJsonCount(1, 'stops')
            ->assertJsonPath('stops.0.name', 'Ngawi (Pasar Karangjati)');

        // And the leg Ngawi does not run returns the two Surabaya stops only.
        $this->getJson('/book/shuttle/stops?direction=to_airport')
            ->assertOk()
            ->assertJsonCount(2, 'stops');
    }

    /** It carries nothing a booking does — no seats, no customers, no counts. */
    public function test_the_stop_list_carries_only_the_catalogue(): void
    {
        $this->fakeCatalog();

        $body = $this->getJson('/book/shuttle/stops?direction=from_airport')->getContent();

        foreach (['seatsBooked', 'seatsTotal', 'customerName', 'userId', 'status'] as $field) {
            $this->assertStringNotContainsString($field, $body);
        }
    }

    /**
     * A rate with no city belongs to no seat pool.
     *
     * The panel leaves those out of its own calendar; selling one would produce a booking
     * nobody can place.
     */
    public function test_a_stop_with_no_city_is_not_offered(): void
    {
        $this->fakeCatalog(['shuttle_rates' => [
            'rate-orphan' => [
                'id' => 'rate-orphan', 'airportId' => 'apt-sub', 'airportName' => 'Juanda Surabaya Airport',
                'dropPoint' => 'Nowhere In Particular', 'fixCost' => 100000,
                'direction' => ['from_airport'],
                'departureTimes' => ['from_airport' => ['09:00']],
                'enable' => true, 'order' => 1,
            ],
        ]]);

        $this->get('/book/shuttle')->assertOk()->assertDontSee('Nowhere In Particular');

        $this->from('/book/shuttle')
            ->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-orphan'])
            ->assertSessionHasErrors('rateId');
    }

    /**
     * THE CLIENT'S RULE, 2026-08-27, in their own words:
     *
     *   *"If only 10 seats are available, the customer cannot proceed with the process if
     *   they attempt to book more than 10 seats. If 3 seats are available, customers
     *   cannot book more than 3 seats."*
     *
     * It replaced a softer line — take the request anyway and let an operator answer it —
     * which made sense while a seat belonged to a whole day, and stopped making sense once
     * it belonged to one bus.
     */
    public function test_more_seats_than_the_departure_has_are_refused(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => [
                'id' => 'trip-1', 'airportId' => 'apt-sub', 'cityGroupId' => 'city-sby',
                'direction' => 'from_airport', 'date' => $this->date(5),
                'runIndex' => 0, 'seatsTotal' => 10, 'seatsBooked' => 8,
                'status' => 'scheduled',
            ],
        ]]);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        // Two left, three asked for.
        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', [
                'travelDate' => $this->date(5),
                'pickupTime' => '09:00',
                'passengers' => 3,
            ])
            ->assertSessionHasErrors('passengers');
    }

    /**
     * A cancelled departure is refused, and refused BY NAME.
     *
     * The client saw 30 August drawn "Sold out" on a departure they had cancelled
     * themselves (2026-08-27). The refusal is the same either way; the sentence is not,
     * and a customer told "fully booked" comes back tomorrow to the same stopped bus.
     *
     * The error lands on `pickupTime`, not `passengers` — nothing is wrong with the party
     * size, and there is no number of people that would make this departure run.
     */
    public function test_a_cancelled_departure_is_refused_as_cancelled(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => [
                'id' => 'trip-1', 'airportId' => 'apt-sub', 'cityGroupId' => 'city-sby',
                'direction' => 'from_airport', 'date' => $this->date(5),
                'runIndex' => 0, 'seatsTotal' => 10, 'seatsBooked' => 0,
                'status' => 'cancelled',
            ],
        ]]);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', [
                'travelDate' => $this->date(5),
                'pickupTime' => '09:00',
                'passengers' => 1,
            ])
            ->assertSessionHasErrors(['pickupTime' => __('lang.cancelled_departure')]);
    }

    /** Calling off one run leaves the others alone — the day is not closed. */
    public function test_another_run_on_a_partly_cancelled_day_still_sells(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => [
                'id' => 'trip-1', 'airportId' => 'apt-sub', 'cityGroupId' => 'city-sby',
                'direction' => 'from_airport', 'date' => $this->date(5),
                'runIndex' => 0, 'seatsTotal' => 10, 'seatsBooked' => 0,
                'status' => 'cancelled',
            ],
        ]]);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        // 13:15 is run 1, which nobody has cancelled.
        $this->post('/book/shuttle/trip', [
            'travelDate' => $this->date(5),
            'pickupTime' => '13:15',
            'passengers' => 2,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/shuttle/details');
    }

    /** Exactly what is left is fine — the rule is "no more than", not "fewer than". */
    public function test_taking_the_last_seats_is_allowed(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => [
                'id' => 'trip-1', 'airportId' => 'apt-sub', 'cityGroupId' => 'city-sby',
                'direction' => 'from_airport', 'date' => $this->date(5),
                'runIndex' => 0, 'seatsTotal' => 10, 'seatsBooked' => 8,
                'status' => 'scheduled',
            ],
        ]]);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->post('/book/shuttle/trip', [
            'travelDate' => $this->date(5),
            'pickupTime' => '09:00',
            'passengers' => 2,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/shuttle/details');
    }

    /** A run with a document but no room refuses every size. */
    public function test_a_full_departure_takes_no_booking_at_all(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => [
                'id' => 'trip-1', 'airportId' => 'apt-sub', 'cityGroupId' => 'city-sby',
                'direction' => 'from_airport', 'date' => $this->date(5),
                'runIndex' => 0, 'seatsTotal' => 10, 'seatsBooked' => 10,
                'status' => 'scheduled',
            ],
        ]]);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', [
                'travelDate' => $this->date(5),
                'pickupTime' => '09:00',
                'passengers' => 1,
            ])
            ->assertSessionHasErrors('passengers');
    }

    /**
     * The cap counts the CHOSEN run, not the day.
     *
     * Run 0 is nearly full and run 1 is empty; a party of five belongs on the second and
     * must not be refused because of the first.
     */
    public function test_the_cap_follows_the_departure_that_was_chosen(): void
    {
        $this->fakeCatalog(['shuttle_trips' => [
            'trip-1' => [
                'id' => 'trip-1', 'airportId' => 'apt-sub', 'cityGroupId' => 'city-sby',
                'direction' => 'from_airport', 'date' => $this->date(5),
                'runIndex' => 0, 'seatsTotal' => 10, 'seatsBooked' => 9,
                'status' => 'scheduled',
            ],
        ]]);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        // 09:00 is run 0, with one seat left.
        $this->from('/book/shuttle/trip')
            ->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 5])
            ->assertSessionHasErrors('passengers');

        // 13:15 is run 1, which nobody has booked.
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '13:15', 'passengers' => 5])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/shuttle/details');
    }

    /**
     * An unreadable seat count is NOT a full bus.
     *
     * Firestore being unreachable must never refuse a booking: that turns an outage into a
     * lost sale and tells the customer something false on the way out.
     */
    public function test_an_unreadable_count_does_not_refuse_the_booking(): void
    {
        // No trip documents at all, which `SeatAvailability` reports as unknown.
        $this->fakeCatalog(['shuttle_trips' => []]);

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);

        $this->post('/book/shuttle/trip', [
            'travelDate' => $this->date(5),
            'pickupTime' => '09:00',
            'passengers' => 9,
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/book/shuttle/details');
    }

    /** Spec §6: the customer is told this at the point of booking, not afterwards. */
    public function test_the_details_step_says_a_seat_is_not_held_until_the_order_is_accepted(): void
    {
        $this->fakeCatalog();

        $this->post('/book/shuttle', ['direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1']);
        $this->post('/book/shuttle/trip', ['travelDate' => $this->date(5), 'pickupTime' => '09:00', 'passengers' => 2]);

        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/shuttle/details')
            ->assertOk()
            ->assertSee(__('lang.seat_held_on_acceptance'));
    }

    /**
     * An airport the admin has switched off must take its routes with it. Found live:
     * Soeta Airport was disabled while its four rate rows stayed enabled, and the site
     * kept selling flights out of it.
     */
    public function test_a_disabled_airport_withdraws_its_routes(): void
    {
        $this->fakeCatalog([
            'airports' => [
                'apt-sub' => ['id' => 'apt-sub', 'name' => 'Juanda Surabaya Airport', 'enable' => false, 'order' => 1],
            ],
        ]);

        $this->get('/book/shuttle')
            ->assertOk()
            ->assertDontSee('Surabaya City Center')
            ->assertSee('No routes are published in this direction yet.');
    }

    /** Signs a customer in without walking the whole Firebase login. */
    private function actingAsCustomer(): void
    {
        $this->actingAs(\App\Models\User::factory()->create());
    }
}

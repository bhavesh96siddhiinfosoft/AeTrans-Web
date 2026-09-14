<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\Booking\BookingDraft;
use App\Services\Booking\BookingFailed;
use App\Services\Booking\Coupons;
use App\Services\Booking\BookingReservation;
use App\Services\Booking\SeatAvailability;
use App\Services\Booking\ShuttleCalendar;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\FirestoreDocuments;
use App\Services\Site\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Booking a seat on an airport shuttle, in four steps.
 *
 *   1. the route    — which way, which airport, which city, which stop
 *   2. the trip     — the date, the departure, how many seats
 *   3. your details — the summary, who you are, and any notes; SIGN-IN REQUIRED here
 *   4. sent         — the reference, and how to pay
 *
 * The same shape as the charter flow, for the same reasons: the route first so the fare
 * is known, the customer last so nobody is asked for an account before seeing a price.
 *
 * ── THE SEAT RULE ───────────────────────────────────────────────────────────
 *
 * A seat pool is `airportId + cityGroupId + direction + date + runIndex` — a CITY and a
 * DEPARTURE, not a day. Every stop in a city shares the same seats on a given run. See
 * `SeatAvailability`, which explains why a run is a position and not a clock time.
 *
 * A seat is taken when an operator ACCEPTS the order, not when it arrives, so a booking
 * made here holds nothing and a customer can book the last seat and still be turned down.
 * The flow says so at the point of booking rather than leaving it to an email.
 *
 * ── THE DEPARTURE IS A CLOSED LIST ──────────────────────────────────────────
 *
 * It was a free field until today, on the client's instruction of 2026-08-21 — back when
 * seats belonged to the whole day and the departure did not matter. It does now: a time
 * that is on no timetable has no `runIndex`, and a booking without one belongs to no pool.
 * The panel's own form closed this on 2026-08-25 and the contract asks every channel to
 * do the same, so the customer picks from the stop's published departures.
 */
class ShuttleBookingController extends Controller
{
    private const TYPE = 'shuttle';

    private const DIRECTIONS = ['from_airport', 'to_airport'];

    public function __construct(
        private readonly BookingDraft $draft,
        private readonly Catalog $catalog,
        private readonly SeatAvailability $seats,
        private readonly ShuttleCalendar $calendar,
        private readonly Coupons $coupons,
    ) {}

    /** Throw the booking away and start again — see the charter controller. */
    public function restart(): RedirectResponse
    {
        $this->draft->clear(self::TYPE);

        return redirect()->route('book.shuttle');
    }

    // ---- Step 1: the route ----------------------------------------------------

    /**
     * Step 1 — which way, which airport, which city, which stop.
     *
     * The CITY is asked for before the stop because the city is what seats are counted
     * against: all eight Ngawi stops share one bus and one row of seats. Choosing a stop
     * without knowing its city would let a customer compare two stops that were never
     * competing for different seats.
     */
    public function route(Request $request): View
    {
        $draft = $this->draft->all(self::TYPE);
        $direction = $request->query('direction', $draft['direction'] ?? 'from_airport');

        if (! in_array($direction, self::DIRECTIONS, true)) {
            $direction = 'from_airport';
        }

        return view('booking.shuttle.route', [
            'draft' => $draft,
            'direction' => $direction,
            'airports' => $this->catalog->airportsWithRates($direction),
            'rates' => $rates = $this->catalog->ratesFor($direction),
            // The view prints each stop's timetable into a data attribute, which is the
            // fallback the dropdown degrades to when the stop endpoint cannot be reached.
            'catalog' => $this->catalog,
            /*
             * The cities reachable on this leg, for the filter the script builds. Made
             * here rather than in the view: a `@json()` wrapping a closure that builds
             * arrays is a Blade directive whose brackets Blade parses, and it does not
             * always get it right.
             */
            /*
             * Each airport's terminals, for the dropdown the script fills in once an
             * airport is chosen. Built here for the same reason `cityOptions` is: a
             * `@json()` wrapping a closure that builds arrays is a Blade directive whose
             * brackets Blade parses, and it does not always get it right.
             *
             * An airport with no terminals gets an empty list, and the script hides the
             * field entirely rather than showing an empty dropdown.
             */
            'terminalOptions' => collect($this->catalog->airportsWithRates($direction))
                ->map(fn (array $airport, string $id) => $this->catalog->terminalsOf($id))
                ->all(),
            'cityOptions' => collect($rates)
                ->map(fn (array $rate) => [
                    'id' => (string) ($rate['cityGroupId'] ?? ''),
                    'name' => (string) ($rate['cityGroupName'] ?? ''),
                    'airport' => (string) ($rate['airportId'] ?? ''),
                ])
                ->unique('id')
                ->values()
                ->all(),
        ]);
    }

    public function saveRoute(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'direction' => ['required', 'in:'.implode(',', self::DIRECTIONS)],
            'rateId' => ['required', 'string'],
            // Checked against the airport's own list below, once the rate says which
            // airport this is. A free-text terminal would reach the driver as a
            // instruction to go somewhere that does not exist.
            'terminal' => ['nullable', 'string', 'max:120'],
        ], [
            // Ours, not the framework's "The rate id field is required" — the customer
            // chose a DROP POINT, and has never seen the words "rate id".
            'rateId.required' => __('lang.choose_a_stop'),
        ]);

        $rate = $this->catalog->rate($validated['rateId']);

        /*
         * The rate has to actually RUN the leg that was posted with it. A route is one
         * document carrying both directions now, and a leg can be suspended — dropped
         * from `direction` while its timetable stays — so a rate existing is not the same
         * as that leg being sellable.
         */
        if (! $rate || ! $this->catalog->rateRuns($rate, $validated['direction'])) {
            return back()->withInput()->withErrors(['rateId' => __('lang.route_withdrawn')]);
        }

        /*
         * The terminal, checked against the list the admin published for THIS airport.
         *
         * Required when the airport has any, and refused when it is not one of them —
         * the field is a dropdown, so anything else arrived by hand. An airport with no
         * terminals never shows the field and stores an empty string.
         */
        $terminals = $this->catalog->terminalsOf((string) ($rate['airportId'] ?? ''));
        $terminal = trim((string) ($validated['terminal'] ?? ''));

        if ($terminals !== [] && ! in_array($terminal, $terminals, true)) {
            return back()->withInput()->withErrors([
                'terminal' => __('lang.choose_a_terminal'),
            ]);
        }

        $before = $this->draft->get(self::TYPE, 'rateId');

        $this->draft->fill(self::TYPE, [
            'direction' => $validated['direction'],
            'rateId' => $validated['rateId'],
            'airportId' => $rate['airportId'] ?? null,
            'cityGroupId' => $rate['cityGroupId'] ?? null,
            'terminal' => $terminals === [] ? '' : $terminal,
        ]);

        /*
         * A different stop has a different timetable, and its run 1 is a different bus.
         * Carrying the old departure forward would keep a time that is either not on the
         * new timetable at all or, worse, is — and means something else.
         */
        if ($before !== $validated['rateId']) {
            $this->draft->forgetFields(self::TYPE, 'pickupTime');
        }

        return redirect()->route('book.shuttle.trip');
    }

    // ---- Step 2: the date and the departure -----------------------------------

    /** Step 2 — when, on which bus, and how many seats. */
    public function trip(): RedirectResponse|View
    {
        if (! $this->draft->has(self::TYPE, 'direction', 'rateId')) {
            return redirect()->route('book.shuttle');
        }

        $draft = $this->draft->all(self::TYPE);
        $rate = $this->rateOrNull($draft);

        if (! $rate) {
            return $this->routeWithdrawn();
        }

        $direction = (string) $draft['direction'];

        return view('booking.shuttle.trip', [
            'draft' => $draft,
            'rate' => $rate,
            'direction' => $direction,
            // In `runIndex` order. The POSITION is what a seat is counted against.
            'times' => $this->catalog->departureTimes($rate, $direction),
            'calendar' => $this->calendar->payload($rate, $direction),
            'minDate' => $this->calendar->earliest(),
            'maxDate' => $this->calendar->latest(),
            'fare' => (float) ($rate['fixCost'] ?? 0),
        ]);
    }

    public function saveTrip(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'travelDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->calendar->earliest(), 'before_or_equal:'.$this->calendar->latest()],
            'pickupTime' => ['required', 'date_format:H:i'],
            'passengers' => ['required', 'integer', 'min:1', 'max:30'],
            'luggage' => ['nullable', 'integer', 'min:0', 'max:30'],
            'flightDetails' => ['nullable', 'string', 'max:120'],
        ]);

        $draft = $this->draft->all(self::TYPE);
        $rate = $this->rateOrNull($draft);

        if (! $rate) {
            return $this->routeWithdrawn();
        }

        $direction = (string) $draft['direction'];

        /*
         * The departure must be one of THIS stop's published times for THIS leg. The form
         * only offers those, but the value arrives in a form field: a time on no timetable
         * has no run, and a booking with no run holds no seat while looking complete.
         */
        $runIndex = $this->catalog->runIndex($rate, $direction, $validated['pickupTime']);

        if ($runIndex === null) {
            return back()->withInput()->withErrors([
                'pickupTime' => __('lang.departure_not_scheduled'),
            ]);
        }

        /*
         * A customer may not ask for more seats than the departure has left. The client's
         * rule, 2026-08-27: *"if 3 seats are available, customers cannot book more than
         * 3 seats"*.
         *
         * It replaces a softer line the flow used to take — record the request anyway and
         * let an operator sort it out — which was reasonable while a seat belonged to a
         * whole day and is not now that it belongs to one bus.
         */
        if ($this->isCancelled($rate, $direction, $validated['travelDate'], $runIndex)) {
            return back()->withInput()->withErrors([
                'pickupTime' => __('lang.cancelled_departure'),
            ]);
        }

        $left = $this->seatsLeft($rate, $direction, $validated['travelDate'], $runIndex);

        if ($left !== null && (int) $validated['passengers'] > $left) {
            return back()->withInput()->withErrors([
                'passengers' => $left > 0
                    ? __('lang.only_seats_left', ['count' => $left])
                    : __('lang.sold_out_departure'),
            ]);
        }

        $validated['luggage'] = (int) ($validated['luggage'] ?? 0);
        $this->draft->fill(self::TYPE, $validated);

        return redirect()->route('book.shuttle.details');
    }

    // ---- Step 3: the customer -------------------------------------------------

    /**
     * Step 3 — the summary, and who the booking belongs to.
     *
     * Renders for a visitor, with a sign-in button where the details form would be. Not
     * `auth` middleware: bouncing them to the login screen would ask for an account
     * without ever showing them what they were signing in for.
     */
    public function details(Request $request, FirebaseSession $firebase, FirestoreDocuments $documents): RedirectResponse|View
    {
        if (! $this->ready()) {
            return redirect()->route('book.shuttle');
        }

        $draft = $this->draft->all(self::TYPE);
        $rate = $this->rateOrNull($draft);

        if (! $rate) {
            return $this->routeWithdrawn();
        }

        if (! $request->user()) {
            // Where a visitor comes back to after signing in. Nothing else records it,
            // because this step is not behind middleware.
            $request->session()->put('url.intended', route('book.shuttle.details'));
        }

        $passengers = (int) $draft['passengers'];
        $fare = (float) ($rate['fixCost'] ?? 0);
        $direction = (string) $draft['direction'];

        return view('booking.shuttle.details', [
            'couponCode' => (string) ($draft['couponCode'] ?? ''),
            /*
             * The discount as it stands right now, for the summary. Worked out fresh on
             * every render rather than kept on the draft: a coupon that has expired or
             * been spent since it was applied must stop showing a discount the customer
             * is not going to get.
             */
            'discount' => $this->previewDiscount($request, $draft, $fare * $passengers),
            'draft' => $draft,
            'rate' => $rate,
            'passengers' => $passengers,
            'fare' => $fare,
            'total' => $fare * $passengers,
            'customer' => $this->knownDetails($request, $firebase, $documents),
            /*
             * Shown for information, NOT as a promise. Seats are only taken when an order
             * is accepted, so this number can fall between now and the operator looking at
             * it — and a customer who books the last seat can still be turned down. The
             * screen says that in words.
             */
            'seatsLeft' => $this->seats->remaining(
                (string) ($rate['airportId'] ?? ''),
                (string) ($rate['cityGroupId'] ?? ''),
                $direction,
                (string) $draft['travelDate'],
                (int) $this->catalog->runIndex($rate, $direction, (string) $draft['pickupTime']),
            ),
            /*
             * Step 2 refuses a cancelled departure, so this is only reachable when the
             * admin calls the run off WHILE the customer is filling the form in. Rare, and
             * it has to be right: without it the page reads the 0 seats and says "sold
             * out" about a bus that is not running.
             */
            'cancelled' => $this->isCancelled(
                $rate,
                $direction,
                (string) $draft['travelDate'],
                (int) $this->catalog->runIndex($rate, $direction, (string) $draft['pickupTime']),
            ),
        ]);
    }

    /**
     * Send the booking.
     *
     * No seat is taken and no lock is written: a shuttle seat is only consumed when an
     * operator ACCEPTS the order. This is a request, and the screen before it says so.
     */
    public function confirm(Request $request, BookingReservation $reservation): RedirectResponse
    {
        if (! $this->ready()) {
            return redirect()->route('book.shuttle');
        }

        if (! $request->user()) {
            $request->session()->put('url.intended', route('book.shuttle.details'));

            return redirect()->route('login');
        }

        /*
         * ONE NAME PER SEAT.
         *
         * The client's rule, 2026-09-02: *"When booking a transfer service for five
         * people, the names of all five passengers must also be entered (just as in the
         * app)."* It is a shared vehicle — the driver has a list and calls people onto
         * it, so a seat with no name is a seat nobody can be checked into.
         *
         * `passengerNames` is the mobile app's own field, read off real bookings in the
         * client's Firestore: a plain array of strings, one per seat. It is SEPARATE
         * from `customerName` — one live booking has "Test User" booking three seats for
         * "Test Customer 1", "2" and "3" — so the person paying is not assumed to be
         * travelling.
         *
         * The count is fixed by step 2 and cannot be edited here, so `size` is exact
         * rather than a minimum: a list that does not match the seats bought is a
         * booking nobody can act on.
         */
        $seats = max(1, (int) ($this->draft->get(self::TYPE, 'passengers') ?? 1));

        $validated = $request->validate([
            'customerName' => ['required', 'string', 'max:120'],
            'customerPhone' => ['required', 'string', 'max:32'],
            'customerEmail' => ['nullable', 'email', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'passengerNames' => ['required', 'array', 'size:'.$seats],
            'passengerNames.*' => ['required', 'string', 'max:120'],
        ], [
            // The framework's "The passenger names.0 field is required" names an index
            // the customer cannot see. Every seat gets the same sentence, under its own
            // box, which is where the customer is looking.
            'passengerNames.*.required' => __('lang.enter_passenger_name'),
            'passengerNames.required' => __('lang.enter_passenger_name'),
            'passengerNames.size' => __('lang.enter_passenger_name'),
        ]);

        // Trimmed and re-indexed, so a gap in the middle cannot reach Firestore as a
        // sparse array — which is an OBJECT there, not a list, and reads back wrong.
        $validated['passengerNames'] = array_values(array_map(
            fn ($name) => trim((string) $name),
            $validated['passengerNames'],
        ));

        // Kept in the draft too, so a customer bounced back by a failure does not retype.
        $this->draft->fill(self::TYPE, $validated);

        $draft = $this->draft->all(self::TYPE);
        $rate = $this->rateOrNull($draft);

        if (! $rate) {
            return $this->routeWithdrawn();
        }

        /*
         * Checked AGAIN here, not only on step 2. A draft can sit in a session while an
         * operator accepts somebody else's order, and the seats it was measured against
         * are not the seats it is being written against.
         */
        $direction = (string) $draft['direction'];
        $runIndex = $this->catalog->runIndex($rate, $direction, (string) $draft['pickupTime']);
        if ($runIndex !== null && $this->isCancelled($rate, $direction, (string) $draft['travelDate'], $runIndex)) {
            return redirect()->route('book.shuttle.trip')->withErrors([
                'pickupTime' => __('lang.cancelled_departure'),
            ]);
        }

        $left = $runIndex === null
            ? null
            : $this->seatsLeft($rate, $direction, (string) $draft['travelDate'], $runIndex);

        if ($left !== null && (int) $draft['passengers'] > $left) {
            return redirect()->route('book.shuttle.trip')->withErrors([
                'passengers' => $left > 0
                    ? __('lang.only_seats_left', ['count' => $left])
                    : __('lang.sold_out_departure'),
            ]);
        }

        $fare = (float) ($rate['fixCost'] ?? 0) * max(1, (int) ($draft['passengers'] ?? 1));
        $coupon = $this->applyCoupon($request, $draft, $fare);

        if ($coupon instanceof RedirectResponse) {
            return $coupon;
        }

        $this->draft->fill(self::TYPE, $coupon);

        try {
            $bookingId = $reservation->shuttle($this->draft->all(self::TYPE), $request->user());
        } catch (BookingFailed $failure) {
            return redirect()->route('book.shuttle.details')->withErrors(['booking' => $failure->customerMessage]);
        }

        // The draft has become a booking; leaving it would let a refresh send a second.
        $this->draft->clear(self::TYPE);

        return redirect()->route('book.done', ['booking' => $bookingId]);
    }

    // ---- Shared ---------------------------------------------------------------

    /**
     * What the draft's coupon is worth right now, or zero.
     *
     * For DISPLAY only. The number that reaches a booking is worked out again in
     * `applyCoupon()` at confirm; this one exists so the summary and the button agree.
     *
     * @param  array<string, mixed>  $draft
     */
    private function previewDiscount(Request $request, array $draft, float $amount): float
    {
        $code = trim((string) ($draft['couponCode'] ?? ''));

        if ($code === '') {
            return 0.0;
        }

        $service = $this->catalog->service('airport-shuttle');

        return $this->coupons
            ->check($code, (string) ($service['id'] ?? ''), $amount, $request->user())
            ->discount;
    }

    /**
     * Re-checks the draft's coupon, and returns what to write with the booking.
     *
     * THE CODE IS NOT TRUSTED FROM THE DRAFT — see the charter controller's copy for
     * what can change between the details screen and this button. Same rules, same
     * refusal, and the code is dropped rather than left offering a discount that has
     * just been refused.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|RedirectResponse the fields to merge, or the way back
     */
    private function applyCoupon(Request $request, array $draft, float $amount): array|RedirectResponse
    {
        $code = trim((string) ($draft['couponCode'] ?? ''));

        if ($code === '') {
            return ['couponId' => '', 'couponCode' => '', 'discountAmount' => 0.0];
        }

        $service = $this->catalog->service('airport-shuttle');
        $check = $this->coupons->check($code, (string) ($service['id'] ?? ''), $amount, $request->user());

        if (! $check->ok) {
            $this->draft->forgetFields(self::TYPE, 'couponCode');

            return redirect()->route('book.shuttle.details')->withErrors(['code' => $check->message]);
        }

        return [
            'couponId' => $check->id(),
            'couponCode' => $check->code(),
            'discountAmount' => $check->discount,
        ];
    }

    /**
     * Seats left on one run, or null when the number could not be read.
     *
     * Null is NOT zero, and the difference decides whether a booking is refused. Firestore
     * being unreachable must never read as "sold out" — that turns an outage into a lost
     * sale, and the customer is told something false on the way.
     *
     * @param  array<string, mixed>  $rate
     */
    private function seatsLeft(array $rate, string $direction, string $date, int $runIndex): ?int
    {
        return $this->seats->remaining(
            (string) ($rate['airportId'] ?? ''),
            (string) ($rate['cityGroupId'] ?? ''),
            $direction,
            $date,
            $runIndex,
        );
    }

    /**
     * Whether the admin has called this departure off.
     *
     * Asked BEFORE the seat count, because both refuse the booking and only this one can
     * explain itself: a cancelled run has nought seats free, so a check that looked at
     * the number alone would send the customer away with "fully booked" and have them
     * come back tomorrow to the same cancelled bus.
     *
     * @param  array<string, mixed>  $rate
     */
    private function isCancelled(array $rate, string $direction, string $date, int $runIndex): bool
    {
        return $this->seats->isCancelled(
            (string) ($rate['airportId'] ?? ''),
            (string) ($rate['cityGroupId'] ?? ''),
            $direction,
            $date,
            $runIndex,
        );
    }

    /** Everything a booking cannot be written without. */
    private function ready(): bool
    {
        return $this->draft->has(self::TYPE, 'direction', 'rateId', 'travelDate', 'pickupTime', 'passengers');
    }

    /**
     * The chosen rate, if it is still sellable on the chosen leg.
     *
     * Re-read on every step rather than trusted from the draft: an admin can withdraw a
     * route, suspend a leg or clear a timetable while a customer is filling the form in.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|null
     */
    private function rateOrNull(array $draft): ?array
    {
        $rate = $this->catalog->rate((string) ($draft['rateId'] ?? ''));

        return $rate && $this->catalog->rateRuns($rate, (string) ($draft['direction'] ?? ''))
            ? $rate
            : null;
    }

    private function routeWithdrawn(): RedirectResponse
    {
        $this->draft->forgetFields(self::TYPE, 'rateId');

        return redirect()->route('book.shuttle')->withErrors(['rateId' => __('lang.route_withdrawn')]);
    }

    /**
     * What we already know about the customer, to fill the details form in.
     *
     * The phone comes from the Firestore profile the panel reads and writes — it is not
     * mirrored into the local `users` table. Anything already typed into the draft wins.
     *
     * @return array{customerName: string, customerPhone: string, customerEmail: string}
     */
    private function knownDetails(Request $request, FirebaseSession $firebase, FirestoreDocuments $documents): array
    {
        $user = $request->user();

        if (! $user) {
            return ['customerName' => '', 'customerPhone' => '', 'customerEmail' => ''];
        }

        $profile = [];
        $uid = $firebase->uid();
        $idToken = $firebase->idToken();

        if ($uid && $idToken) {
            // A profile that cannot be read is not an error: the form opens empty.
            $profile = $documents->get('users/'.$uid, $idToken) ?? [];
        }

        return [
            'customerName' => (string) $this->draft->get(self::TYPE, 'customerName')
                ?: (string) ($profile['displayName'] ?? $user->name),
            'customerPhone' => (string) $this->draft->get(self::TYPE, 'customerPhone')
                ?: (string) ($profile['phone'] ?? ''),
            'customerEmail' => (string) $this->draft->get(self::TYPE, 'customerEmail')
                ?: (string) ($profile['email'] ?? $user->email),
        ];
    }
}

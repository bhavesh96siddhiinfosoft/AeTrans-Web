<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\Booking\BookingDraft;
use App\Services\Booking\BookingFailed;
use App\Services\Booking\BookingReservation;
use App\Services\Booking\CharterCalendar;
use App\Services\Booking\CharterQuote;
use App\Services\Booking\Coupons;
use App\Services\Booking\VehicleAvailability;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\FirestoreDocuments;
use App\Services\Site\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Booking a car with a driver, in four steps.
 *
 *   1. the route    — pickup, the stops, the measured distance
 *   2. the trip     — party, vehicle type, one way or return, dates and pickup time
 *   3. your details — the summary, who you are, and any notes; SIGN-IN REQUIRED here
 *   4. sent         — the reference, and how to pay
 *
 * ── WHY THE ROUTE COMES FIRST ───────────────────────────────────────────────
 *
 * Reordered on 2026-08-26 to follow the mobile app, which asks for the route, then the
 * vehicle and dates, then the customer. The old order asked for passengers and dates
 * before the customer had described a trip, which meant the price — the thing they came
 * for — could not be shown until the third screen. With the distance known first, step 2
 * can price every vehicle as it is chosen.
 *
 * ── WHY THE ACCOUNT IS ASKED FOR ON STEP 3 AND NOT BEFORE ───────────────────
 *
 * A visitor fills in steps 1 and 2 with no account, and step 3 RENDERS for them: they
 * see the summary and the price, with a sign-in button where the details form would be.
 * The client's instruction of 2026-08-21, narrowing spec §9. Someone who has not yet
 * seen a price has no reason to hand over an email address.
 *
 * The step is not `auth` middleware, because middleware would bounce a visitor to the
 * login screen without ever showing them what they were about to pay for. The POST is
 * guarded instead — that is where an account actually becomes necessary.
 *
 * Every step re-reads the catalogue rather than trusting the draft, so a vehicle type
 * the admin retires half way through a customer's session stops being offered — and,
 * more importantly, cannot be booked from a stale form.
 */
class CharterBookingController extends Controller
{
    private const TYPE = 'charter';

    public function __construct(
        private readonly BookingDraft $draft,
        private readonly Catalog $catalog,
        private readonly VehicleAvailability $availability,
        private readonly CharterCalendar $calendar,
        private readonly Coupons $coupons,
    ) {}

    /**
     * Throw the booking away and start again.
     *
     * Reached only from the reload detector on a wizard step — see
     * booking/partials/reset-on-reload.blade.php for why the browser has to be the one
     * to notice. Landing here directly is harmless: it clears a draft that is the
     * customer's own and sends them to step 1.
     */
    public function restart(): RedirectResponse
    {
        $this->draft->clear(self::TYPE);

        return redirect()->route('book.charter');
    }

    // ---- Step 1: the route ----------------------------------------------------

    /** Step 1 — where from, where to, and how far. */
    public function trip(): View
    {
        return view('booking.charter.trip', ['draft' => $this->draft->all(self::TYPE)]);
    }

    public function saveTrip(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pickupAddress' => ['required', 'string', 'max:500'],

            /*
             * Coordinates travel with every address. They are what the distance was
             * measured from, and what the panel's map draws the route on — an address
             * with no pin behind it is text nobody can navigate to.
             *
             * Nullable, because the fields degrade to plain text when maps are
             * unavailable and a booking taken that way must still go through.
             */
            'pickupLat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickupLng' => ['nullable', 'numeric', 'between:-180,180'],

            // A charter is an itinerary: one stop or several, kept in the order given.
            'dropoffAddresses' => ['required', 'array', 'min:1', 'max:12'],
            'dropoffAddresses.*' => ['required', 'string', 'max:500'],
            'dropoffLat' => ['nullable', 'array'],
            'dropoffLat.*' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoffLng' => ['nullable', 'array'],
            'dropoffLng.*' => ['nullable', 'numeric', 'between:-180,180'],

            /*
             * Measured from the coordinates by the browser, not typed — the field is
             * read-only and the value arrives in a hidden input beside it. It is still
             * validated as if a person had typed it, because a hidden input is exactly
             * as forgeable as a visible one.
             *
             * This is the ONE-WAY distance. A return trip drives the same road back and
             * bills for twice it, and that doubling happens in `CharterQuote` where the
             * trip type is known — see step 2.
             *
             * The operator re-checks the real distance before accepting the booking,
             * which is what the pending status is for.
             */
            'distanceKm' => ['required', 'numeric', 'min:0', 'max:20000'],
            /*
             * The round trip measured as a LOOP — the same stops, then home from the
             * last one (A -> B -> C -> A). The client's correction of 2026-09-09: a
             * return journey is not the outbound distance doubled, which bills for
             * driving back through every stop.
             *
             * Nullable on purpose. A customer with no Directions API types the distance
             * by hand and has only one figure, and Google occasionally routes a leg one
             * way but not back. `CharterQuote` doubles when this is absent, which is
             * the old behaviour kept exactly where it is still the only answer
             * available.
             */
            'roundTripKm' => ['nullable', 'numeric', 'min:0', 'max:40000'],
        ]);

        // Re-indexed so the address, its latitude and its longitude keep the same
        // position after a customer removes a stop from the middle of the list.
        $validated['dropoffAddresses'] = array_values($validated['dropoffAddresses']);
        $validated['dropoffLat'] = array_values($validated['dropoffLat'] ?? []);
        $validated['dropoffLng'] = array_values($validated['dropoffLng'] ?? []);

        $this->draft->fill(self::TYPE, $validated);

        return redirect()->route('book.charter.vehicle');
    }

    // ---- Step 2: the vehicle and the dates ------------------------------------

    /**
     * Step 2 — who is travelling, in what, and when.
     *
     * One screen, because the four answers are one decision: the party decides which
     * vehicles are offered, the vehicle and the dates decide the price, and the trip
     * type decides both the dates asked for and the distance billed. Splitting them put
     * a page load between a customer changing their mind and seeing what it cost.
     */
    public function vehicle(): RedirectResponse|View
    {
        if (! $this->draft->has(self::TYPE, 'pickupAddress', 'dropoffAddresses')) {
            return redirect()->route('book.charter');
        }

        $draft = $this->draft->all(self::TYPE);

        return view('booking.charter.vehicle', [
            'draft' => $draft,
            'minDate' => $this->calendar->earliest(),
            'maxDate' => $this->calendar->latest(),
            'calendar' => $this->calendar->payload(),
            /*
             * Every charter type with a vehicle behind it, priced per day. The party
             * size filters the list in the BROWSER, so changing it does not cost a page
             * load — but it is re-checked on the way in, because a form field is
             * whatever the browser sends.
             */
            'types' => $types = $this->sellableTypes(),
            'distanceKm' => (float) ($draft['distanceKm'] ?? 0),
            /*
             * The measured loop, A -> B -> C -> A, for the running estimate on this
             * screen. Zero when step 1 could not measure one, and the script doubles as
             * `CharterQuote` does — the estimate and the invoice have to answer the same
             * way about the same trip.
             */
            'roundTripKm' => (float) ($draft['roundTripKm'] ?? 0),
            /*
             * The same types again, reduced to the numbers the running estimate needs.
             * Built here rather than in the view: a `@json()` holding a closure that
             * builds arrays is a Blade directive whose brackets Blade has to parse, and
             * it does not always get it right.
             */
            'rates' => collect($types)->map(fn (array $type, string $id) => [
                'id' => $id,
                'name' => (string) ($type['name'] ?? ''),
                'seats' => (int) ($type['seatCapacity'] ?? 0),
                'dailyRate' => (float) ($type['dailyRate'] ?? 0),
                'perKmRate' => (float) ($type['perKmRate'] ?? 0),
                'minDailyRental' => max(1, (int) ($type['minDailyRental'] ?? 1)),
            ])->values()->all(),
        ]);
    }

    public function saveVehicle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'passengers' => ['required', 'integer', 'min:1', 'max:60'],
            'vehicleTypeId' => ['required', 'string'],
            /*
             * Optional. Blank means "any available vehicle", which is what a customer
             * with no JavaScript always sends and what most customers want — one van of
             * a type is the same as another to them, and naming one means their booking
             * can be refused while a identical van stands free.
             */
            'vehicleUnitId' => ['nullable', 'string'],
            'tripType' => ['required', 'in:one_way,round_trip'],
            'travelDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$this->calendar->earliest(), 'before_or_equal:'.$this->calendar->latest()],
            /*
             * Required for a return trip and forbidden nothing else: a one-way booking
             * that arrives carrying a return date would be billed for days the customer
             * did not ask for.
             */
            'returnDate' => ['nullable', 'required_if:tripType,round_trip', 'date_format:Y-m-d', 'after_or_equal:travelDate', 'before_or_equal:'.$this->calendar->latest()],
            'pickupTime' => ['required', 'date_format:H:i'],
        ], [
            'returnDate.required_if' => __('lang.choose_return_day'),
        ]);

        if ($validated['tripType'] === 'one_way') {
            $validated['returnDate'] = null;
        }

        /*
         * Leaving and coming back the SAME DAY is allowed only for a trip short enough
         * to drive in one — the client's rule of 2026-09-07. `after_or_equal` above lets
         * the equal case through the rules; whether it is a sensible trip is a question
         * about the distance, which the rules cannot see.
         *
         * Checked here as well as in the calendar for the reason capacity and
         * availability are: the grid is a suggestion, the form field is whatever
         * arrived.
         */
        $route = $this->draft->all(self::TYPE);

        if ($validated['tripType'] === 'round_trip'
            && $validated['returnDate'] === $validated['travelDate']
            && ! CharterQuote::sameDayReturnFits(
                (float) ($route['distanceKm'] ?? 0),
                // The measured loop, so this rule and the price answer for the same
                // journey — offering a same-day return and then billing two days for
                // it is exactly what sharing the figure prevents.
                isset($route['roundTripKm']) ? (float) $route['roundTripKm'] : null,
            )) {
            return back()->withInput()->withErrors([
                'returnDate' => __('lang.same_day_return_too_far', [
                    'km' => (int) config('bookings.distance_day_threshold_km', 500),
                ]),
            ]);
        }

        $type = $this->catalog->vehicleType($validated['vehicleTypeId']);
        $passengers = (int) $validated['passengers'];

        /*
         * Re-checked on the way in, not only when the list was drawn. The list never
         * offers a vehicle too small, but the value arrives in a form field and a form
         * field is whatever the browser sends. The client's rule — "more than seven
         * passengers, the 7-seater cannot be selected" — has to be unable to be dodged,
         * not merely inconvenient to dodge.
         */
        if (! $type || (int) ($type['seatCapacity'] ?? 0) < $passengers) {
            return back()->withInput()->withErrors([
                'vehicleTypeId' => __('lang.vehicle_too_small', ['count' => $passengers]),
            ]);
        }

        // Availability is checked here as well as on the calendar, for the same reason
        // capacity is: the grid is a suggestion, the form field is what arrived.
        $days = $this->availability->daysBetween($validated['travelDate'], $validated['returnDate'] ?? null);

        $free = $this->availability->freeUnits($validated['vehicleTypeId'], $days);

        if ($free === []) {
            return back()->withInput()->withErrors([
                'vehicleTypeId' => __('lang.vehicle_not_free'),
            ]);
        }

        /*
         * A named van has to be one of that type's, and still free. Checked here as well
         * as in the dropdown for the usual reason: the list is a suggestion and the form
         * field is whatever arrived.
         */
        // `??` because a nullable field that was never posted is absent from the
        // validated set, not null — which is the no-JavaScript case, and the common one.
        $validated['vehicleUnitId'] = (string) ($validated['vehicleUnitId'] ?? '');

        if ($validated['vehicleUnitId'] !== '' && ! in_array($validated['vehicleUnitId'], $free, true)) {
            return back()->withInput()->withErrors([
                'vehicleUnitId' => __('lang.vehicle_no_longer_free'),
            ]);
        }

        $this->draft->fill(self::TYPE, $validated);

        return redirect()->route('book.charter.details');
    }

    // ---- Step 3: the customer -------------------------------------------------

    /**
     * Step 3 — the summary, and who the booking belongs to.
     *
     * Renders for a visitor as well as a customer. A visitor sees everything except the
     * details form, and a sign-in button in its place; Laravel's `intended` redirect
     * brings them back here with the draft untouched in their session.
     */
    public function details(Request $request, FirebaseSession $firebase, FirestoreDocuments $documents): RedirectResponse|View
    {
        if (! $this->ready()) {
            return redirect()->route('book.charter');
        }

        $draft = $this->draft->all(self::TYPE);
        $type = $this->catalog->vehicleType($draft['vehicleTypeId']);

        // The admin retired the vehicle while this customer was filling the form.
        if (! $type) {
            $this->draft->forgetFields(self::TYPE, 'vehicleTypeId');

            return redirect()->route('book.charter.vehicle')->withErrors([
                'vehicleTypeId' => __('lang.vehicle_withdrawn'),
            ]);
        }

        /*
         * Where a visitor comes back to after signing in. Set here because this step is
         * NOT behind `auth` middleware — nothing else would have recorded an intended
         * destination, and Laravel would return them to the home page having forgotten
         * what they were in the middle of.
         */
        if (! $request->user()) {
            $request->session()->put('url.intended', route('book.charter.details'));
        }

        $quote = $this->quote($draft, $type);

        return view('booking.charter.details', [
            'draft' => $draft,
            'vehicleType' => $type,
            'quote' => $quote,
            'customer' => $this->knownDetails($request, $firebase, $documents),
            'couponCode' => (string) ($draft['couponCode'] ?? ''),
            /*
             * The discount as it stands right now, for the summary. Worked out fresh on
             * every render rather than kept on the draft: a coupon that has expired or
             * been spent since it was applied must stop showing a discount the customer
             * is not going to get.
             */
            'discount' => $this->previewDiscount($request, $draft, $quote->total),
        ]);
    }

    /**
     * Send the booking.
     *
     * Everything shown on the summary is re-derived here — the vehicle, the price, the
     * free unit — because a draft is what the customer typed, not what they get. The
     * write itself is one Firestore transaction that also takes the vehicle's day
     * locks, so two customers cannot be sold the same van.
     */
    public function confirm(Request $request, BookingReservation $reservation): RedirectResponse
    {
        if (! $this->ready()) {
            return redirect()->route('book.charter');
        }

        /*
         * The gate, and the only place an account is actually required. A visitor who
         * presses Confirm is sent to sign in and returned to this step, not to step 1.
         */
        if (! $request->user()) {
            $request->session()->put('url.intended', route('book.charter.details'));

            return redirect()->route('login');
        }

        /*
         * ONE NAME PER PASSENGER, as on the shuttle. The client's rule of 2026-09-02,
         * extended to charter the same day.
         *
         * `passengerNames` is the mobile app's own field and shape — a plain array of
         * strings — and one real charter booking in the client's Firestore already
         * carries it, so a booking taken here reads identically to one taken in the app.
         *
         * The count is set on step 2 and cannot be edited here, so `size` is exact: a
         * list that does not match the party is a list the driver cannot use.
         */
        $party = max(1, (int) ($this->draft->get(self::TYPE, 'passengers') ?? 1));

        $validated = $request->validate([
            'customerName' => ['required', 'string', 'max:120'],
            /*
             * Required, where the app leaves email optional. A charter is a driver
             * meeting somebody at an address: the dispatcher rings the customer on the
             * morning, and a booking with no number is one the operator cannot run.
             */
            'customerPhone' => ['required', 'string', 'max:32'],
            'customerEmail' => ['nullable', 'email', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'passengerNames' => ['required', 'array', 'size:'.$party],
            'passengerNames.*' => ['required', 'string', 'max:120'],
        ], [
            // The framework's "The passenger names.0 field is required" names an index
            // no customer can see. Each box gets the sentence, under itself.
            'passengerNames.*.required' => __('lang.enter_passenger_name'),
            'passengerNames.required' => __('lang.enter_passenger_name'),
            'passengerNames.size' => __('lang.enter_passenger_name'),
        ]);

        // Re-indexed, so a gap cannot reach Firestore as a sparse array — which encodes
        // there as an OBJECT rather than a list, and reads back wrong in the app.
        $validated['passengerNames'] = array_values(array_map(
            fn ($name) => trim((string) $name),
            $validated['passengerNames'],
        ));

        // Kept in the draft as well, so a customer bounced back by a failed reservation
        // does not retype them.
        $this->draft->fill(self::TYPE, $validated);

        $draft = $this->draft->all(self::TYPE);
        $type = $this->catalog->vehicleType($draft['vehicleTypeId']);

        if (! $type) {
            return redirect()->route('book.charter.vehicle')->withErrors([
                'vehicleTypeId' => __('lang.vehicle_withdrawn'),
            ]);
        }

        $coupon = $this->applyCoupon($request, $draft, $this->quote($draft, $type)->total);

        if ($coupon instanceof RedirectResponse) {
            return $coupon;
        }

        $draft = array_merge($draft, $coupon);

        try {
            $bookingId = $reservation->charter($draft, $request->user(), $this->quote($draft, $type));
        } catch (BookingFailed $failure) {
            /*
             * `taken` and `sold_out` are the system working: somebody else got there
             * first between this customer seeing the vehicle offered and pressing
             * Confirm. They go back to choose, with the rest of the booking intact.
             */
            $back = in_array($failure->reason, ['taken', 'sold_out'], true)
                ? 'book.charter.vehicle'
                : 'book.charter.details';

            return redirect()->route($back)->withErrors(['booking' => $failure->customerMessage]);
        }

        // The draft has become a booking; leaving it in the session would let a refresh
        // send a second one.
        $this->draft->clear(self::TYPE);

        return redirect()->route('book.done', ['booking' => $bookingId]);
    }

    // ---- Shared ---------------------------------------------------------------

    /** Everything a booking cannot be priced or written without. */
    private function ready(): bool
    {
        return $this->draft->has(
            self::TYPE,
            'pickupAddress',
            'dropoffAddresses',
            'passengers',
            'vehicleTypeId',
            'travelDate',
            'pickupTime',
        );
    }

    /**
     * The price, from the draft.
     *
     * In one place because three screens ask for it — the step 2 estimate, the summary
     * and the write — and a quote that differed between them would be a price the
     * customer agreed to and was then charged past.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $type
     */
    private function quote(array $draft, array $type): CharterQuote
    {
        return CharterQuote::for(
            $type,
            (string) $draft['travelDate'],
            $draft['returnDate'] ?? null,
            (float) ($draft['distanceKm'] ?? 0),
            (string) ($draft['tripType'] ?? 'one_way'),
            // A -> B -> C -> A, measured on step 1. Null when it could not be, and the
            // quote doubles instead.
            isset($draft['roundTripKm']) ? (float) $draft['roundTripKm'] : null,
        );
    }

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

        $service = $this->catalog->service('charter');

        return $this->coupons
            ->check($code, (string) ($service['id'] ?? ''), $amount, $request->user())
            ->discount;
    }

    /**
     * Re-checks the draft's coupon, and returns what to write with the booking.
     *
     * THE CODE IS NOT TRUSTED FROM THE DRAFT. What the draft holds is what the customer
     * typed; between the details screen and this button a coupon can expire, be switched
     * off, be spent by somebody else down to its last use — or, the one the draft could
     * never know, this customer may have signed in, making the one-per-person and
     * first-booking-only rules answerable for the first time.
     *
     * A code that no longer works does NOT quietly fall off. The customer is sent back
     * and told, because a booking made at a price they did not expect is worse than a
     * booking they have to make twice.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>|RedirectResponse the fields to merge, or the way back
     */
    private function applyCoupon(Request $request, array $draft, float $amount): array|RedirectResponse
    {
        $code = trim((string) ($draft['couponCode'] ?? ''));

        // No code is the ordinary case, and it writes zeroes rather than nothing — so a
        // booking says plainly that no discount was applied.
        if ($code === '') {
            return ['couponId' => '', 'couponCode' => '', 'discountAmount' => 0.0];
        }

        $service = $this->catalog->service('charter');
        $check = $this->coupons->check($code, (string) ($service['id'] ?? ''), $amount, $request->user());

        if (! $check->ok) {
            // Dropped from the draft, so the screen they land on is not still offering a
            // discount that has just been refused.
            $this->draft->forgetFields(self::TYPE, 'couponCode');

            return redirect()->route('book.charter.details')->withErrors(['code' => $check->message]);
        }

        return [
            'couponId' => $check->id(),
            'couponCode' => $check->code(),
            'discountAmount' => $check->discount,
        ];
    }

    /**
     * Charter types the admin has enabled that have a vehicle behind them.
     *
     * A type with no working unit cannot be sold however good the rate looks, and
     * offering it means a customer choosing it and being refused at the last step.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sellableTypes(): array
    {
        return collect($this->catalog->vehicleTypesFor(1))
            ->filter(fn (array $type, string $id) => $this->catalog->unitsOfType($id) !== [])
            ->all();
    }

    /**
     * What we already know about the customer, to fill the details form in.
     *
     * The name and email come from the account. The PHONE comes from the Firestore
     * profile the panel reads and writes — it is not mirrored into the local `users`
     * table, and asking a returning customer to retype a number the operator already
     * has is the kind of friction that loses a booking on a phone keyboard.
     *
     * Anything already typed into the draft wins: a customer who corrected the number
     * and was bounced back by a failed reservation must not have their correction
     * overwritten by the stored one.
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
            // A profile that cannot be read is not an error here: the form simply opens
            // empty and the customer types the number.
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

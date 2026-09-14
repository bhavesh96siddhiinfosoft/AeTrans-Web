<?php

namespace App\Services\Booking;

use App\Models\User;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\FirestoreWriter;
use App\Services\Firebase\GeoPoint;
use App\Services\Firebase\IdentityToolkitException;
use App\Services\Site\Catalog;
use App\Services\Mail\CustomerMail;
use App\Services\Site\Currency;
use Illuminate\Support\Facades\Log;

/**
 * Writes a booking. THE ONLY PLACE THAT MAY.
 *
 * Spec §8: nothing today stops the website and the mobile app double-selling the same
 * vehicle, because a check made before a write is advisory — the other channel sells in
 * the gap between the check and the save. This closes that gap for the website by writing
 * the booking and its locks in ONE atomic commit:
 *
 *   1. work out which units are free
 *   2. commit the booking and one lock per day TOGETHER, each lock with a
 *      "must not already exist" precondition
 *   3. a refused precondition means somebody else took that van — try the next one
 *
 * The lock ids are DETERMINISTIC — `{unitId}_{day}` — and that precondition IS the lock.
 * Two customers pressing Confirm in the same second for the last van both try to create
 * the same lock document; Firestore accepts one commit and refuses the other, and the
 * second customer is told the van has gone rather than being sold it as well. The panel
 * uses auto-ids and settles for an advisory check, so this still does better.
 *
 * ── WHY NOT A READ-WRITE TRANSACTION ────────────────────────────────────────
 *
 * It was one until 2026-08-26, and it never worked outside the tests: Firestore's REST
 * API refuses `documents:beginTransaction` with PERMISSION_DENIED for anything that is
 * not a Google service-account credential. A Firebase customer ID token is fine for
 * reading, querying and committing under the security rules — but opening a transaction
 * is an admin-level operation, and no rule can grant it. Every real Confirm failed with
 * "we could not save your booking just now" and a PERMISSION_DENIED in the log.
 *
 * A commit with no transaction is still ALL-OR-NOTHING, and Firestore enforces each
 * write's precondition server-side, so the guarantee above is unchanged. What is lost is
 * querying the locks INSIDE the transaction — which was only ever used to choose a unit,
 * and the precondition is what actually prevents the double sale. So the candidates are
 * worked out first and tried in turn.
 *
 * SHUTTLE IS DIFFERENT AND HOLDS NOTHING. A seat is taken when the order is accepted,
 * not when it arrives — the client's rule — so a pending shuttle booking writes the
 * booking and no lock at all. The seat count the panel keeps is its to change when an
 * operator accepts.
 *
 * Written AS THE CUSTOMER, with their Firebase ID token: they are signed in by the time
 * they reach Confirm, and a customer writing their own booking is the right authority
 * for it. That is also what will keep working when the Firestore rules are tightened —
 * and, unlike a transaction, it is something a customer token is actually allowed to do.
 */
class BookingReservation
{
    public function __construct(
        private readonly FirestoreWriter $writer,
        private readonly FirebaseSession $firebase,
        private readonly VehicleAvailability $availability,
        private readonly Catalog $catalog,
        private readonly Currency $currency,
        private readonly CustomerMail $mail,
    ) {}

    /**
     * @param  array<string, mixed>  $draft
     * @return string the new booking's id
     *
     * @throws BookingFailed
     */
    public function charter(array $draft, User $customer, CharterQuote $quote): string
    {
        $idToken = $this->idToken();
        $type = $this->catalog->vehicleType((string) $draft['vehicleTypeId']);

        if (! $type) {
            throw BookingFailed::soldOut();
        }

        $days = $this->availability->daysBetween($draft['travelDate'], $draft['returnDate'] ?? null);
        $candidates = $this->availability->freeUnits((string) $draft['vehicleTypeId'], $days);

        /*
         * A customer who NAMED a van gets that van or nothing.
         *
         * Narrowing the candidates rather than ignoring the choice: silently sending a
         * different vehicle than the one on the screen would be a booking the customer
         * did not make. The cost is that their confirm can fail while an identical van
         * stands free — which is why the field offers "any available vehicle" first, and
         * why `taken` sends them back to the vehicle step rather than to an error.
         */
        $wanted = (string) ($draft['vehicleUnitId'] ?? '');

        if ($wanted !== '') {
            $candidates = in_array($wanted, $candidates, true) ? [$wanted] : [];
        }

        if ($candidates === []) {
            throw BookingFailed::soldOut();
        }

        $bookingId = $this->writer->newId();
        $service = $this->catalog->service('charter');

        $payload = array_merge($this->common($draft, $customer, $service, 'charter'), [
            'vehicleTypeId' => (string) $draft['vehicleTypeId'],
            'vehicleTypeName' => (string) ($type['name'] ?? ''),
            'passengers' => (int) $draft['passengers'],
            // One name per passenger, the same field and shape the shuttle writes and
            // the mobile app already uses. See the shuttle payload below.
            'passengerNames' => array_values(array_filter(array_map(
                fn ($name) => trim((string) $name),
                (array) ($draft['passengerNames'] ?? []),
            ), fn (string $name) => $name !== '')),
            'pickupAddress' => (string) $draft['pickupAddress'],
            // A list even when there is one, because the panel reads a list and a
            // charter is an itinerary.
            'dropoffAddresses' => array_values((array) ($draft['dropoffAddresses'] ?? [])),
            /*
             * The coordinates the panel draws the route from.
             *
             * `null`, never a point at 0, 0, when the customer typed an address without
             * picking it from the suggestions or the map — that spot is in the Gulf of
             * Guinea and would draw on the operator's map as if it were real. The panel's
             * own booking form carries the same warning in the same words.
             */
            'pickupPoint' => GeoPoint::from($draft['pickupLat'] ?? null, $draft['pickupLng'] ?? null),
            'dropoffPoints' => $this->dropoffPoints($draft),
            'returnDate' => $this->timestamp($draft['returnDate'] ?? null),
            /*
             * The customer's own choice from step 2, not inferred from whether a return
             * date is present. They mean the same thing today — a round trip is the only
             * way to get a return date — but the panel reads `tripType` to decide how the
             * booking is priced and dispatched, and inferring it would silently rewrite
             * the customer's answer if that ever stopped being true.
             */
            'tripType' => (string) ($draft['tripType'] ?? (! empty($draft['returnDate']) ? 'round_trip' : 'one_way')),
        ], $quote->toArray());

        $payload = $this->withPayable($payload);

        /*
         * Each candidate is TRIED, not checked. `freeUnits()` said this van looked free a
         * moment ago; the commit's preconditions are what settle it, and a van somebody
         * else took in that moment refuses the commit and the next candidate is offered
         * the same way.
         *
         * Capped at three, because beyond that the honest answer is to tell the customer
         * rather than to keep grinding through a fleet that is evidently being sold out
         * from under them.
         */
        foreach (array_slice($candidates, 0, 3) as $unitId) {
            $written = array_merge($payload, [
                'id' => $bookingId,
                'vehicleUnitId' => $unitId,
            ]);

            $writes = array_merge(
                [$this->writer->set('bookings/'.$bookingId, $written)],
                $this->redemption($draft),
            );

            foreach ($days as $day) {
                // Deterministic id — this IS the lock. See the class comment.
                $lockId = $unitId.'_'.$day;

                $writes[] = $this->writer->createOnly('availability_locks/'.$lockId, [
                    'id' => $lockId,
                    'vehicleUnitId' => $unitId,
                    'day' => $day,
                    'bookingId' => $bookingId,
                    'createdAt' => now()->toDateTimeImmutable(),
                ]);
            }

            try {
                $this->writer->commit($writes, $idToken);

                // The van this customer just took must not still read as free on the way
                // back through the flow. See `availability_cache_seconds`.
                $this->availability->forget();

                /*
                 * The invoice, from the document that WAS written rather than from a
                 * re-read. Re-reading would cost a round trip and could answer with a
                 * booking an operator had already edited in the seconds between — the
                 * customer's receipt must say what they agreed to, not what it became.
                 *
                 * After the booking is safe, and it cannot throw: see `CustomerMail`.
                 */
                $this->mail->bookingReceived($bookingId, $written);

                return $bookingId;
            } catch (IdentityToolkitException $e) {
                /*
                 * FAILED_PRECONDITION: a lock already existed — somebody else holds this
                 * van. ABORTED: contention on the same documents. Both mean "try the next
                 * van", and both are ordinary. Anything else is a fault.
                 */
                if (! in_array($e->errorCode, ['ABORTED', 'FAILED_PRECONDITION'], true)) {
                    Log::error('Could not write a charter booking.', ['code' => $e->errorCode]);

                    throw BookingFailed::unavailable();
                }
            }
        }

        throw BookingFailed::taken();
    }

    /**
     * One point per drop-off, in the SAME ORDER and of the SAME LENGTH as the addresses.
     *
     * A `null` wherever a stop was typed but never picked. Dropping the unpicked ones
     * instead would shorten the list and hang every later stop's pin on the wrong
     * address — which looks right on the map and is not. The panel reads
     * `dropoffPoints[i]` against `dropoffAddresses[i]` and says so where it writes them.
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, GeoPoint|null>
     */
    private function dropoffPoints(array $draft): array
    {
        $latitudes = array_values((array) ($draft['dropoffLat'] ?? []));
        $longitudes = array_values((array) ($draft['dropoffLng'] ?? []));

        return array_map(
            fn (int $index) => GeoPoint::from($latitudes[$index] ?? null, $longitudes[$index] ?? null),
            array_keys(array_values((array) ($draft['dropoffAddresses'] ?? []))),
        );
    }

    /**
     * A shuttle booking. No lock, no seat taken — see the class comment.
     *
     * @param  array<string, mixed>  $draft
     *
     * @throws BookingFailed
     */
    public function shuttle(array $draft, User $customer): string
    {
        $idToken = $this->idToken();
        $rate = $this->catalog->rate((string) $draft['rateId']);

        if (! $rate) {
            throw BookingFailed::soldOut();
        }

        $direction = (string) $draft['direction'];
        $cityGroupId = (string) ($rate['cityGroupId'] ?? '');
        $runIndex = $this->catalog->runIndex($rate, $direction, (string) $draft['pickupTime']);

        /*
         * A booking with no city or no run BELONGS TO NO SEAT POOL, and the contract is
         * emphatic that one must never be written: it "looks complete, it can be
         * confirmed, and it holds no seat at all", and the panel has to report those
         * separately rather than guess. A confirmed five-passenger booking once showed as
         * 0 of 10 on the operator's calendar for exactly this reason.
         *
         * Refusing here is a guard, not the mechanism. The flow only ever offers real
         * departures from the chosen stop's own timetable, which is what makes this
         * unreachable — but it arrives in a form field, and a form field is whatever the
         * browser sends.
         */
        if ($cityGroupId === '' || $runIndex === null) {
            throw BookingFailed::soldOut();
        }

        $bookingId = $this->writer->newId();
        $service = $this->catalog->service('airport-shuttle');
        $passengers = (int) $draft['passengers'];
        $fare = (float) ($rate['fixCost'] ?? 0);

        $payload = array_merge($this->common($draft, $customer, $service, 'shuttle'), [
            'id' => $bookingId,
            // A plain string HERE, even though `shuttle_rates.direction` is an array:
            // writing an array on a booking would break the seat count.
            'direction' => $direction,
            'airportId' => (string) ($rate['airportId'] ?? ''),
            'airportName' => (string) ($rate['airportName'] ?? ''),
            /*
             * Which terminal the passenger is collected from, or dropped at.
             *
             * The admin has been able to name an airport's terminals since the panel was
             * built and nothing had ever read them — not this site, not the spec, not the
             * app contract. A shuttle meets somebody AT a terminal, so the driver needs
             * it. Empty for an airport that publishes none.
             *
             * NOT SHOWN BY THE PANEL YET: its booking screen has no terminal row, so
             * until one is added the operator reads this off the notification email
             * instead. Flagged to the client 2026-09-01.
             */
            'terminal' => (string) ($draft['terminal'] ?? ''),
            /*
             * The city and the run are what the seat pool is keyed on, and both are
             * REQUIRED on a shuttle booking as of 2026-08-26.
             *
             * `runIndex` is STORED, never recomputed later: if the admin edits a timetable
             * afterwards, a recomputed index would silently move existing passengers onto
             * a different bus and leave two seat counts wrong at once.
             */
            'cityGroupId' => $cityGroupId,
            'cityGroupName' => (string) ($rate['cityGroupName'] ?? ''),
            'runIndex' => $runIndex,
            'dropPoint' => (string) ($rate['dropPoint'] ?? ''),
            'shuttleRateId' => (string) $draft['rateId'],
            'passengers' => $passengers,
            /*
             * One name per seat, the mobile app's own field and shape — a plain array of
             * strings, read off real bookings in the client's Firestore.
             *
             * NOT the same as `customerName`: a hotel books a guest's transfer, and the
             * driver's list is of who is travelling rather than who paid.
             *
             * `array_values` so it reaches Firestore as a LIST. A sparse PHP array
             * encodes as an object there, and the app reading it back would find keys
             * where it expects positions.
             */
            'passengerNames' => array_values(array_filter(array_map(
                fn ($name) => trim((string) $name),
                (array) ($draft['passengerNames'] ?? []),
            ), fn (string $name) => $name !== '')),
            'luggage' => (int) ($draft['luggage'] ?? 0),
            'flightDetails' => (string) ($draft['flightDetails'] ?? ''),
            'farePerSeat' => $fare,
            'cost' => $fare * $passengers,
            'tripType' => 'one_way',
        ]);

        $payload = $this->withPayable($payload);

        try {
            /*
             * A single set, and no transaction: this booking holds no inventory, so there
             * is nothing to race against. It used to open one anyway, which was ceremony
             * suggesting a guarantee it did not make — and, as it turned out, the one
             * operation Firestore's REST API will not let a customer token perform.
             */
            $this->writer->commit(array_merge(
                [$this->writer->set('bookings/'.$bookingId, $payload)],
                $this->redemption($draft),
            ), $idToken);

            // As on the charter side: the receipt is the document that was written.
            $this->mail->bookingReceived($bookingId, $payload);

            return $bookingId;
        } catch (IdentityToolkitException $e) {
            Log::error('Could not write a shuttle booking.', ['code' => $e->errorCode]);

            throw BookingFailed::unavailable();
        }
    }


    /**
     * The write that spends a coupon, or nothing.
     *
     * Added to the SAME commit as the booking, so the two succeed or fail together: a
     * booking that loses a race for the last vehicle must not have spent the customer's
     * one-per-person discount on the way past.
     *
     * An atomic increment rather than a read-modify-write — two customers redeeming the
     * last use at the same moment would otherwise both see the old number. See
     * `FirestoreWriter::increment`.
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, array<string, mixed>>
     */
    /**
     * Adds what is actually owed, once the price is known.
     *
     * NOT part of `common()`: that runs before either service has worked out its own
     * `cost`, so the subtraction there would always be against zero. Applied to the
     * assembled payload instead, where the number is real.
     *
     * `cost` stays the FULL price — the client's decision, 2026-09-02 — so this is the
     * one field that says what to collect, and it is stored rather than left to each
     * reader to derive and possibly get wrong.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withPayable(array $payload): array
    {
        $payload['payableAmount'] = max(
            0,
            (float) ($payload['cost'] ?? 0) - (float) ($payload['discountAmount'] ?? 0),
        );

        return $payload;
    }

    private function redemption(array $draft): array
    {
        $couponId = (string) ($draft['couponId'] ?? '');

        return $couponId === '' ? [] : [$this->writer->increment('coupon/'.$couponId, 'usedCount')];
    }

    /**
     * The fields every booking carries, whichever service it is for.
     *
     * The names are the panel's, taken from its own offline-booking screen: a booking
     * this site writes has to read identically to one an operator entered by phone.
     *
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>|null  $service
     * @return array<string, mixed>
     */
    private function common(array $draft, User $customer, ?array $service, string $bookingType): array
    {
        $travelDate = $this->timestamp($draft['travelDate']);

        return [
            'bookingType' => $bookingType,
            // Marks the order as taken on the website. Without it there is no way to
            // tell web business from phone business when reconciling.
            'source' => (string) config('bookings.source', 'online'),
            'serviceId' => (string) ($service['id'] ?? ''),
            'serviceSlug' => (string) ($service['slug'] ?? ''),
            'serviceName' => $this->serviceName($service),
            'userId' => (string) ($customer->uuid ?? ''),
            /*
             * From the details step, falling back to the account. The customer may book
             * for somebody else — a hotel booking a guest's transfer — and the name and
             * number the dispatcher rings on the morning are the ones typed on step 3,
             * not the ones the account was registered with.
             */
            'customerName' => (string) ($draft['customerName'] ?? '') ?: $customer->name,
            'customerEmail' => (string) ($draft['customerEmail'] ?? '') ?: (string) $customer->email,
            'customerPhone' => (string) ($draft['customerPhone'] ?? ''),
            'travelDate' => $travelDate,
            // What the panel's lists sort on. The same instant as travelDate, kept as
            // its own field because that is what the panel reads.
            'sortAt' => $travelDate,
            'pickupTime' => (string) $draft['pickupTime'],
            /*
             * Snapshotted, never a pointer at the currency document: editing the active
             * currency later must not relabel what this customer agreed to pay.
             */
            'currencyCode' => $this->currency->code(),
            // The website never quotes off-book; an operator marks a booking a custom
            // quote if they renegotiate it.
            'isCustomQuote' => false,
            // A request, not a reservation. Accepting it is the operator's act.
            // Whatever the customer asked for in their own words. Optional, and written as
            // an empty string rather than omitted so the field always exists — the panel
            // shows it on both services.
            'notes' => (string) ($draft['notes'] ?? ''),
            /*
             * The discount, BESIDE the price rather than inside it. `cost` stays the
             * full amount — the client's decision, 2026-09-02 — so the panel, the app
             * and every existing booking keep reading that field to mean the same thing.
             * What is owed is `cost` minus `discountAmount`, and `payableAmount` carries
             * it so nobody has to do the arithmetic and reach a different answer.
             *
             * All three are written whether or not a coupon was used: a booking with
             * `discountAmount: 0` says plainly that no discount was applied, where a
             * missing field says nothing at all.
             */
            'couponId' => (string) ($draft['couponId'] ?? ''),
            'couponCode' => (string) ($draft['couponCode'] ?? ''),
            'discountAmount' => (float) ($draft['discountAmount'] ?? 0),
            'status' => (string) config('bookings.initial_status', 'pending'),
            'createdAt' => now()->toDateTimeImmutable(),
            'updatedAt' => now()->toDateTimeImmutable(),
        ];
    }

    /**
     * What the admin calls this service, snapshotted onto the booking.
     *
     * Resolving a locale map lives on `Catalog` now, because the website's own screens
     * and emails ask the same question through `SiteServices`, and two resolvers would
     * eventually answer differently.
     */
    private function serviceName(?array $service): string
    {
        return $this->catalog->localised($service['name'] ?? '');
    }

    private function timestamp(?string $date): ?\DateTimeImmutable
    {
        if (! $date) {
            return null;
        }

        /*
         * Midday, not midnight. A booking stored at 00:00 in one time zone is the day
         * before in another, and the panel's calendar would show the van going out a
         * day early. The panel writes midday for the same reason.
         */
        return new \DateTimeImmutable($date.' 12:00:00');
    }

    /** @throws BookingFailed */
    private function idToken(): string
    {
        $idToken = $this->firebase->idToken();

        if (! $idToken) {
            // The Laravel session outlived the Firebase one. Signing in again is the
            // only honest fix, and it costs the customer nothing — the draft survives.
            throw BookingFailed::signInAgain();
        }

        return $idToken;
    }

}

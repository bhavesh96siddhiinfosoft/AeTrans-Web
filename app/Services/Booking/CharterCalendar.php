<?php

namespace App\Services\Booking;

use App\Services\Site\Catalog;

/**
 * What the booking calendar knows about the next three months.
 *
 * The customer picks dates on a month grid where every day already says how many
 * vehicles are left on it — the panel's screen, and before that the mobile app's. The
 * point of drawing it that way is that a sold-out weekend is visible while the customer
 * is still choosing, instead of being discovered by being refused after they have filled
 * in an itinerary.
 *
 * ── WHY THE WHOLE WINDOW IS SENT AT ONCE ────────────────────────────────────
 *
 * The panel hands its calendar every booking in the system and lets the browser count.
 * A public site cannot: those documents carry customers' names, phone numbers and
 * addresses, and the calendar needs none of it. So the counting happens here and only
 * the counts travel — no ids, no names, nothing that says who holds a van.
 *
 * The whole booking window goes in one page render rather than a request per month.
 * It is one Firestore read for a payload of a few kilobytes, it survives a customer
 * paging back and forth through the months, and it adds no public endpoint to a site
 * whose Firestore rules are still open to anonymous reads.
 *
 * ── WHY A DAY HOLDS A LIST AND NOT A COUNT ──────────────────────────────────
 *
 * A day holds a LIST of the vehicles free on it, because the calendar has to answer "is
 * one van free for the whole of Friday to Sunday" — three counts of two could be three
 * different vans. Intersecting the lists is the only correct answer, and the browser has
 * to do it because the range changes with every cell the pointer passes.
 *
 * The lists hold POSITIONS — 0, 1, 2 — into the type's own `units` list, which is sent
 * alongside. Until 2026-08-26 the positions were all that travelled, so no plate number
 * reached the page; the customer now CHOOSES a vehicle, so the fleet's plates are on the
 * screen by design and the positions are just a compact way of writing the same thing.
 *
 * What still never travels is anything from a booking document — no names, no phone
 * numbers, no addresses. The counting happens here so that it does not have to.
 */
class CharterCalendar
{
    public function __construct(
        private readonly Catalog $catalog,
        private readonly VehicleAvailability $availability,
    ) {}

    /**
     * The earliest date a customer may travel — tomorrow, not today.
     *
     * A website booking arrives as `pending` and has to be read, priced and accepted by
     * a person before a driver is given the job. None of that can be relied on to happen
     * between a customer pressing Confirm and expecting to be collected this afternoon,
     * so same-day travel is not offered on either service.
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
     * Everything the grid needs, ready to be printed into the page as JSON.
     *
     * Grouped by vehicle TYPE and not flattened to one number a day, because the party
     * size is a field the customer is still typing: a nine-seater is inventory for a
     * party of four and not for a party of ten, and the grid has to re-colour as the
     * number changes without going back to the server for it.
     *
     * @param  string|null  $vehicleTypeId  narrow to one type — set when a customer has
     *                                      already chosen a vehicle and stepped back to
     *                                      change the dates, so the month answers the
     *                                      question they are actually asking
     * @return array{minKey: string, maxKey: string, limitedUpto: int, types: array<int, array{id: string, seats: int, units: array<int, array{id: string, name: string}>, free: array<string, array<int, int>>}>}
     */
    public function payload(?string $vehicleTypeId = null): array
    {
        $days = $this->availability->daysBetween($this->earliest(), $this->latest());
        $types = [];

        // Every charter type the admin has enabled: `vehicleTypesFor(1)` is the
        // catalogue's way of saying "seats at least one person", which every real
        // vehicle does. The party size is applied in the browser, not here.
        foreach ($this->catalog->vehicleTypesFor(1) as $id => $type) {
            if ($vehicleTypeId !== null && $id !== $vehicleTypeId) {
                continue;
            }

            $fleet = $this->catalog->unitsOfType($id);
            $units = array_keys($fleet);

            // A type with no vehicle behind it cannot be sold, so it must not colour a
            // day green either.
            if ($units === []) {
                continue;
            }

            $position = array_flip($units);
            $free = [];

            foreach ($this->availability->freeUnitDays($id, $days) as $day => $unitIds) {
                // Days where everything is free are the common case and the largest part
                // of the payload; they are still sent in full, because a missing day and
                // a day with nothing free must not look the same to the browser.
                $free[$day] = array_values(array_map(
                    fn (string $unitId) => $position[$unitId],
                    $unitIds,
                ));
            }

            $types[] = [
                'id' => $id,
                'seats' => (int) ($type['seatCapacity'] ?? 0),
                /*
                 * In the admin's own order, and labelled the way the panel labels them:
                 * the plate, then whatever the admin called that van. The customer picks
                 * one of these, so what the dispatcher says over the radio and what the
                 * customer saw on the screen are the same string.
                 */
                'units' => array_values(array_map(fn (string $unitId) => [
                    'id' => $unitId,
                    'name' => trim(
                        (string) ($fleet[$unitId]['plateNumber'] ?? $unitId)
                        .(($fleet[$unitId]['label'] ?? '') !== '' ? ' · '.$fleet[$unitId]['label'] : '')
                    ),
                ], $units)),
                'free' => $free,
            ];
        }

        return [
            'minKey' => $this->earliest(),
            'maxKey' => $this->latest(),
            'limitedUpto' => (int) config('bookings.limited_upto', 3),
            'types' => $types,
        ];
    }
}

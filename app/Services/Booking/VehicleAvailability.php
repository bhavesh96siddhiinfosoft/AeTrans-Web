<?php

namespace App\Services\Booking;

use App\Services\Firebase\Firestore;
use App\Services\Site\Catalog;
use Illuminate\Support\Facades\Cache;

/**
 * Which vehicles are actually free, and on which days.
 *
 *     free = enabled units − units blocked for maintenance − units already booked
 *
 * Never stored. An editable "available" number is clobbered by the next booking write
 * and drifts within a day, and once it has drifted there is no telling which of the
 * three inputs was wrong. The panel derives it the same way and says so in its own
 * availability config.
 *
 * A charter booking holds its vehicle while it is `pending`, `confirmed` or
 * `completed` — the whole time an operator might still be deciding. That is different
 * from a shuttle seat, which is only taken once the order is accepted, and the two
 * lists live in config/bookings.php so the difference is stated once.
 *
 * `cancelled` is absent from the list on purpose: a cancelled booking must give its
 * vehicle back, or the fleet silently shrinks every time a customer backs out.
 */
class VehicleAvailability
{
    private const CACHE_PREFIX = 'site.availability.';

    /**
     * The two Firestore reads, held for the life of the request.
     *
     * Every question this class answers is derived from the same two collections, and
     * a single booking screen asks it many times — once per vehicle type on the vehicle
     * step, and once per day per type when the calendar is drawn. Reading them again
     * each time turned one screen into dozens of REST calls, and the answers could not
     * even disagree usefully: they would all describe the same instant.
     */
    private ?array $blocked = null;

    private ?array $held = null;

    /**
     * Drops the shared cache as well as this request's memo.
     *
     * Called after a booking is written, so the customer who just took the last van is
     * not shown it as free on their way back through the flow — and so the tests, which
     * swap the whole fleet between assertions, are not answered from the last one.
     */
    public function forget(): void
    {
        $this->blocked = null;
        $this->held = null;

        Cache::forget(self::CACHE_PREFIX.'availability_blocks');
        Cache::forget(self::CACHE_PREFIX.'bookings');
    }

    public function __construct(
        private readonly Firestore $firestore,
        private readonly Catalog $catalog,
    ) {}

    /**
     * Every day of a trip, inclusive, as `Y-m-d` keys.
     *
     * Inclusive because a van out on Friday and back on Sunday is unavailable on
     * Saturday too — the day nobody books but the vehicle is still away.
     *
     * @return array<int, string>
     */
    public function daysBetween(string $travelDate, ?string $returnDate): array
    {
        $days = [];
        $last = ($returnDate && $returnDate >= $travelDate) ? $returnDate : $travelDate;

        $cursor = \DateTimeImmutable::createFromFormat('!Y-m-d', $travelDate);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $last);

        if (! $cursor || ! $end) {
            return [];
        }

        while ($cursor <= $end) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');

            // A malformed range must not spin forever; the booking window is the
            // natural ceiling and anything past it is a typo.
            if (count($days) > 400) {
                break;
            }
        }

        return $days;
    }

    /**
     * The units of one vehicle type that are free on EVERY day of the range.
     *
     * Every day, not any: a customer booking Friday to Sunday needs one van for the
     * whole trip, not a different one each morning.
     *
     * @return array<int, string> unit ids, in the admin's order
     */
    public function freeUnits(string $vehicleTypeId, array $days): array
    {
        $units = array_keys($this->catalog->unitsOfType($vehicleTypeId));

        if ($units === [] || $days === []) {
            return [];
        }

        $blocked = $this->blockedUnitDays();
        $held = $this->heldUnitDays();

        return array_values(array_filter($units, function (string $unitId) use ($days, $blocked, $held) {
            foreach ($days as $day) {
                if (isset($blocked[$unitId][$day]) || isset($held[$unitId][$day])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** True when at least one vehicle of this type can do the whole trip. */
    public function hasFreeUnit(string $vehicleTypeId, array $days): bool
    {
        return $this->freeUnits($vehicleTypeId, $days) !== [];
    }

    /**
     * Which units of a type are free on each SINGLE day of a span.
     *
     * Deliberately per-day, where `freeUnits()` is per-trip. The calendar has to answer
     * a question the trip version cannot: a customer dragging a return date from Friday
     * to Sunday is asking about a different range with every cell, and the only honest
     * answer is "which van is free on Friday, and Saturday, and Sunday" — the ranges
     * are then intersected wherever the question is actually asked.
     *
     * The alternative, calling `freeUnits()` once per candidate range, is ninety-odd
     * ranges per month and grows with the square of the window.
     *
     * @param  array<int, string>  $days
     * @return array<string, array<int, string>> day => unit ids, in the admin's order
     */
    public function freeUnitDays(string $vehicleTypeId, array $days): array
    {
        $units = array_keys($this->catalog->unitsOfType($vehicleTypeId));
        $blocked = $this->blockedUnitDays();
        $held = $this->heldUnitDays();
        $free = [];

        foreach ($days as $day) {
            $free[$day] = array_values(array_filter(
                $units,
                fn (string $unitId) => ! isset($blocked[$unitId][$day]) && ! isset($held[$unitId][$day]),
            ));
        }

        return $free;
    }

    /**
     * Maintenance and other operator blocks, as `[unitId][day] => true`.
     *
     * `availability_blocks` carries `vehicleUnitId`, `startDate` and `endDate`, which
     * may be plain `Y-m-d` strings or timestamps depending on how they were written —
     * the panel reads both and so does this.
     *
     * @return array<string, array<string, true>>
     */
    private function blockedUnitDays(): array
    {
        if ($this->blocked !== null) {
            return $this->blocked;
        }

        $map = [];

        foreach ($this->documents('availability_blocks') as $block) {
            $unitId = (string) ($block['vehicleUnitId'] ?? '');
            $start = $this->dayKey($block['startDate'] ?? null);

            if ($unitId === '' || $start === null) {
                continue;
            }

            $end = $this->dayKey($block['endDate'] ?? null) ?? $start;

            foreach ($this->daysBetween($start, $end) as $day) {
                $map[$unitId][$day] = true;
            }
        }

        return $this->blocked = $map;
    }

    /**
     * Days already held by a booking, as `[unitId][day] => true`.
     *
     * Read from `bookings` rather than from `availability_locks`, because the mobile
     * app writes bookings with no lock at all — the very gap the reserve transaction
     * exists to close. Trusting the locks alone would show a van as free that the app
     * has already sold.
     *
     * @return array<string, array<string, true>>
     */
    private function heldUnitDays(): array
    {
        if ($this->held !== null) {
            return $this->held;
        }

        $consuming = (array) config('bookings.charter_consuming_statuses', []);
        $map = [];

        foreach ($this->documents('bookings') as $booking) {
            $unitId = (string) ($booking['vehicleUnitId'] ?? '');

            if ($unitId === '' || ! in_array($booking['status'] ?? '', $consuming, true)) {
                continue;
            }

            $start = $this->dayKey($booking['travelDate'] ?? null);

            if ($start === null) {
                continue;
            }

            $end = $this->dayKey($booking['returnDate'] ?? null) ?? $start;

            foreach ($this->daysBetween($start, max($start, $end)) as $day) {
                $map[$unitId][$day] = true;
            }
        }

        return $this->held = $map;
    }

    /**
     * One of the two availability collections, briefly cached.
     *
     * The seconds are in config/bookings.php with the reasoning; the short version is
     * that these two reads were four of the five seconds the booking screen took, and
     * what can go stale in thirty seconds is somebody else's booking arriving — which
     * the reserve transaction catches anyway, inside the transaction, where it counts.
     *
     * @return array<string, array<string, mixed>>
     */
    private function documents(string $collection): array
    {
        $seconds = (int) config('bookings.availability_cache_seconds', 30);

        if ($seconds < 1) {
            return $this->firestore->collection($collection);
        }

        return Cache::remember(
            self::CACHE_PREFIX.$collection,
            $seconds,
            fn () => $this->firestore->collection($collection),
        );
    }

    /**
     * A `Y-m-d` key from whatever the field holds.
     *
     * Firestore timestamps arrive as RFC-3339 strings through the REST API; older rows
     * hold a plain date string. Both are accepted, and anything else is ignored rather
     * than guessed at — a block whose dates cannot be read must not silently free a
     * vehicle that is in the workshop.
     */
    private function dayKey(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}

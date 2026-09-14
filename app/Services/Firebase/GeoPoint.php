<?php

namespace App\Services\Firebase;

/**
 * A Firestore GeoPoint.
 *
 * Its own type, not a `['latitude' => …, 'longitude' => …]` array, because the two encode
 * differently and mean different things to the reader. The panel draws a booking's route
 * from `pickupPoint` and `dropoffPoints`, and it reads them as GeoPoints — an array would
 * arrive as a plain map and, while the panel's own reader happens to tolerate that today,
 * writing a shape the other side merely tolerates is how a contract quietly drifts.
 *
 * ── THERE IS NO ZERO POINT ──────────────────────────────────────────────────
 *
 * `null` is the answer when an address was typed but never picked from the suggestions or
 * the map. It must never be a GeoPoint at 0, 0: that is a spot in the Gulf of Guinea, and
 * it would draw on the operator's map as if it were real. The panel's own booking form
 * carries the same warning in the same words.
 */
final class GeoPoint
{
    public function __construct(
        public readonly float $latitude,
        public readonly float $longitude,
    ) {}

    /**
     * A point from a pair that may be missing, blank or nonsense.
     *
     * Returns null unless BOTH halves are real numbers in range. A latitude with no
     * longitude is not half a location, it is no location.
     */
    public static function from(mixed $latitude, mixed $longitude): ?self
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return new self($latitude, $longitude);
    }
}

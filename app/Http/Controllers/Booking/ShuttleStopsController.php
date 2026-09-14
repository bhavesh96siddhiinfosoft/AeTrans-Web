<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\Site\Catalog;
use App\Services\Site\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The published stops on one leg, for the route step's dropdown.
 *
 * ── WHY THIS IS SAFE TO EXPOSE, WHEN THE BOOKING COUNTS ARE NOT ─────────────
 *
 * Everything here is the CATALOGUE — what the business sells, printed on the booking page
 * already. There is no customer in it, no booking, and no seat count. That is the line:
 * `CharterCalendar` counts vehicles on the server precisely so that booking documents
 * never reach the browser, and the same reasoning says a list of published stops and
 * fares may.
 *
 * ── AND WHY THE PAGE STILL RENDERS THE LIST ─────────────────────────────────
 *
 * The stop `<select>` arrives filled in, from the same catalogue. This endpoint refreshes
 * it as the customer narrows by airport and city; it does not supply it. A browser with
 * no JavaScript, or one where this request fails, keeps the full list and can still book
 * — the server re-reads the chosen stop either way.
 */
class ShuttleStopsController extends Controller
{
    private const DIRECTIONS = ['from_airport', 'to_airport'];

    public function __invoke(Request $request, Catalog $catalog, Currency $money): JsonResponse
    {
        $direction = (string) $request->query('direction', 'from_airport');

        if (! in_array($direction, self::DIRECTIONS, true)) {
            $direction = 'from_airport';
        }

        $airportId = $this->filter($request->query('airport'));
        $cityGroupId = $this->filter($request->query('city'));

        $stops = [];

        foreach ($catalog->ratesFor($direction, $airportId, $cityGroupId) as $id => $rate) {
            $custom = ($rate['isCustomQuote'] ?? false) === true;
            $fare = (float) ($rate['fixCost'] ?? 0);

            $stops[] = [
                'id' => $id,
                'name' => (string) ($rate['dropPoint'] ?? ''),
                'city' => (string) ($rate['cityGroupName'] ?? ''),
                'cityId' => (string) ($rate['cityGroupId'] ?? ''),
                'airport' => (string) ($rate['airportId'] ?? ''),
                // Already formatted: the currency's symbol, digits and separators are the
                // admin's, and a browser reformatting a number is a browser guessing.
                'fare' => $custom || $fare <= 0
                    ? null
                    // With the unit: a number on its own beside a drop point could be a
                    // distance, a duration or a price for the whole vehicle.
                    : $money->format($fare).' '.__('lang.per_seat'),
                /*
                 * The timetable for THIS leg, in run order. Shown under the chosen stop so
                 * a customer can see the departures before committing to the next step —
                 * and so a stop with an empty timetable is visibly different from one that
                 * simply has not been chosen yet.
                 */
                'times' => $catalog->departureTimes($rate, $direction),
            ];
        }

        return response()->json(['stops' => $stops]);
    }

    /** An empty query parameter means "no filter", not a filter on the empty string. */
    private function filter(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}

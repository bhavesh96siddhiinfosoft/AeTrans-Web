<?php

namespace App\Services\Site;

use App\Services\Firebase\Firestore;
use Illuminate\Support\Facades\Cache;

/**
 * What the booking flows can offer: services, vehicle types, airports and shuttle rates.
 *
 * Every list is filtered to `enable` and sorted by the admin's own `order`, because the
 * panel lets an operator retire a vehicle or an airport by unticking a box and expects
 * the website to stop offering it the same minute.
 *
 * Cached like Global Settings — a customer stepping through five screens must not cost
 * five round trips to Google for a list that changes weekly.
 *
 * Field names are the panel's, read off its own screens:
 *
 *   services        slug (`charter` / `airport-shuttle`), name (LOCALE MAP), enable, order
 *   vehicle_types   name, seatCapacity, dailyRate, perKmRate, minDailyRental,
 *                   serviceId, logo, enable, order
 *   vehicle_units   vehicleTypeId, label, plateNumber, images[], enable, order
 *   airports        name, city, code, terminals[], latitude, longitude, enable, order
 *   city_groups     name, code, enable, order — the cities seats are counted against
 *   shuttle_rates   airportId, airportName, cityGroupId, cityGroupName, dropPoint,
 *                   direction[], departureTimes{direction: [HH:MM]}, fixCost,
 *                   isCustomQuote, enable, order
 *
 * ── THE SHUTTLE SHAPE CHANGED ON 2026-08-26 ─────────────────────────────────
 *
 * `docs/shuttle-app-integration.md` in the panel repo is the contract, and it supersedes
 * everything this class assumed before. A route used to be TWO documents, one per
 * direction, with `direction` a plain string and `departureTimes` a flat array. It is now
 * ONE document per route: `direction` is an array of the legs it runs, and
 * `departureTimes` is a map keyed by leg.
 *
 * Seats moved with it — they belong to a CITY and a RUN, not to a day. See
 * `SeatAvailability`.
 *
 * Live data is being migrated gradually, so the older shapes are tolerated here rather
 * than assumed away; the panel does the same. What is NOT tolerated is a rate with no
 * `cityGroupId`: its seats belong to no pool, the panel leaves it out of the calendar,
 * and selling it would produce a booking nobody can place.
 */
class Catalog
{
    private const CACHE_PREFIX = 'site.catalog.';

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $memo = [];

    public function __construct(
        private readonly Firestore $firestore,
        private readonly int $cacheSeconds = 300,
    ) {}

    public function forget(): void
    {
        $this->memo = [];

        foreach (['services', 'vehicle_types', 'vehicle_units', 'airports', 'city_groups', 'shuttle_rates'] as $collection) {
            Cache::forget(self::CACHE_PREFIX.$collection);
        }
    }

    // ---- Services -------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function service(string $slug): ?array
    {
        foreach ($this->enabled('services') as $service) {
            if (($service['slug'] ?? null) === $slug) {
                return $service;
            }
        }

        return null;
    }

    /**
     * A `name` from the panel, in the reader's language.
     *
     * `services.name` is a LOCALE MAP on documents written by the current panel
     * (`{"en": "Rental With Driver", "id": "…"}`) and a plain string on older ones, so
     * both are read here rather than assumed away.
     *
     * The order — this locale, then any language the panel has — is deliberate. A name
     * the admin typed in one language only must still show up everywhere, because the
     * point of naming a service in the panel is that renaming it there changes the
     * website. A caller that wants the site's own translated wording instead supplies it
     * as a fallback for the empty case; see `SiteServices`.
     */
    public function localised(mixed $name): string
    {
        if (is_array($name)) {
            $name = $name[app()->getLocale()] ?? collect($name)->first(fn ($value) => trim((string) $value) !== '');
        }

        return trim((string) $name);
    }

    // ---- Charter --------------------------------------------------------------

    /**
     * Vehicle types that can carry this many people, largest party first.
     *
     * The client's rule, in their words: *"more than seven passengers, the 7-seater
     * cannot be selected."* Filtering here rather than showing everything and refusing
     * later is the difference between a customer choosing from what they can have and a
     * customer being told no after they have chosen.
     *
     * @return array<string, array<string, mixed>>
     */
    public function vehicleTypesFor(int $passengers): array
    {
        $charter = $this->service('charter');

        return collect($this->enabled('vehicle_types'))
            ->filter(fn (array $type) => ! $charter || ($type['serviceId'] ?? null) === $charter['id'])
            ->filter(fn (array $type) => (int) ($type['seatCapacity'] ?? 0) >= $passengers)
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function vehicleType(string $id): ?array
    {
        return $this->enabled('vehicle_types')[$id] ?? null;
    }

    /**
     * The units of one type that the admin has not retired.
     *
     * The customer never picks a unit — which van goes out is the dispatcher's call —
     * but the flow has to know whether ANY exists, because a type with no working
     * vehicle behind it cannot be sold.
     *
     * @return array<string, array<string, mixed>>
     */
    public function unitsOfType(string $vehicleTypeId): array
    {
        return collect($this->enabled('vehicle_units'))
            ->filter(fn (array $unit) => ($unit['vehicleTypeId'] ?? null) === $vehicleTypeId)
            ->all();
    }

    // ---- Shuttle --------------------------------------------------------------

    /**
     * The legs a rate actually runs, as a list of direction keys.
     *
     * Four shapes are live at once while the data migrates, and the panel reads all of
     * them:
     *
     *   ["from_airport", "to_airport"]              the current shape
     *   {"from_airport": true, "to_airport": false} a brief intermediate — true means runs
     *   "to_airport"                                the old two-document shape
     *   absent                                      an outbound fare
     *
     * @param  array<string, mixed>  $rate
     * @return array<int, string>
     */
    public function directionsOf(array $rate): array
    {
        $direction = $rate['direction'] ?? null;

        if (is_string($direction) && $direction !== '') {
            return [$direction];
        }

        if (! is_array($direction)) {
            return $direction === null ? ['from_airport'] : [];
        }

        // A list has integer keys; the intermediate map has direction keys and booleans.
        if (array_is_list($direction)) {
            return array_values(array_filter($direction, 'is_string'));
        }

        return array_keys(array_filter($direction, fn ($runs) => $runs === true));
    }

    /**
     * The timetable for one leg of a rate, sorted and deduplicated.
     *
     * The POSITION in this list is the `runIndex` a booking is counted against, so the
     * order has to be stable and has to match what the panel computes. Sorted ascending,
     * as the contract specifies.
     *
     * Tolerates the old flat array, which belonged to that document's own single
     * direction.
     *
     * @param  array<string, mixed>  $rate
     * @return array<int, string> `HH:MM`
     */
    public function departureTimes(array $rate, string $direction): array
    {
        $times = $rate['departureTimes'] ?? [];

        if (! is_array($times)) {
            return [];
        }

        if (array_is_list($times)) {
            // The old shape. It is this document's own timetable, so it applies only to
            // the leg the document was for.
            $times = in_array($direction, $this->directionsOf($rate), true) ? $times : [];
        } else {
            $times = $times[$direction] ?? [];
        }

        $times = array_values(array_unique(array_filter(
            (array) $times,
            fn ($time) => is_string($time) && preg_match('/^\d{2}:\d{2}$/', $time),
        )));

        sort($times);

        return $times;
    }

    /**
     * Which run of the timetable a time is, or null when it is not on it.
     *
     * NEVER returns -1. The contract is emphatic about this: `indexOf` returning -1 is a
     * failure marker, and a booking stored with `runIndex: -1` belongs to no seat pool —
     * "it looks complete, it can be confirmed, and it holds no seat at all". A confirmed
     * five-passenger booking once showed as 0 of 10 on the panel's calendar because of
     * exactly that. Null forces the caller to refuse rather than to write one.
     *
     * @param  array<string, mixed>  $rate
     */
    public function runIndex(array $rate, string $direction, string $time): ?int
    {
        $position = array_search($time, $this->departureTimes($rate, $direction), true);

        return $position === false ? null : (int) $position;
    }

    /**
     * Whether a rate can be sold on one leg.
     *
     * All four have to hold. A leg can be SUSPENDED — dropped from `direction` while its
     * timetable stays — which is how an admin stops a direction running without losing
     * the schedule, so a non-empty timetable on its own does not mean the leg runs.
     *
     * The `cityGroupId` is ours to add: without it the booking has no seat pool.
     *
     * @param  array<string, mixed>  $rate
     */
    public function rateRuns(array $rate, string $direction): bool
    {
        return ($rate['enable'] ?? true) !== false
            && in_array($direction, $this->directionsOf($rate), true)
            && $this->departureTimes($rate, $direction) !== []
            && (string) ($rate['cityGroupId'] ?? '') !== '';
    }

    /**
     * Enabled rates that run this leg, optionally narrowed to an airport and a city.
     *
     * @return array<string, array<string, mixed>>
     */
    public function ratesFor(string $direction, ?string $airportId = null, ?string $cityGroupId = null): array
    {
        $airports = $this->enabled('airports');
        $cities = $this->enabled('city_groups');

        return collect($this->enabled('shuttle_rates'))
            ->filter(fn (array $rate) => $this->rateRuns($rate, $direction))
            ->filter(fn (array $rate) => $airportId === null || ($rate['airportId'] ?? null) === $airportId)
            ->filter(fn (array $rate) => $cityGroupId === null || ($rate['cityGroupId'] ?? null) === $cityGroupId)
            /*
             * The rate's AIRPORT must be enabled too, not just the rate.
             *
             * Found live: the client had disabled Soeta Airport but left its four rate
             * rows enabled, and the site offered flights from an airport the panel no
             * longer lists. Switching an airport off has to withdraw everything that
             * departs from it, or the operator's off switch does not mean what it says.
             */
            ->filter(fn (array $rate) => isset($airports[$rate['airportId'] ?? '']))
            // And the same for the city, for the same reason.
            ->filter(fn (array $rate) => isset($cities[$rate['cityGroupId'] ?? '']))
            ->all();
    }

    /**
     * Airports with at least one sellable route on this leg.
     *
     * @return array<string, array<string, mixed>>
     */
    public function airportsWithRates(string $direction): array
    {
        $airportIds = collect($this->ratesFor($direction))->pluck('airportId')->unique()->all();

        return collect($this->enabled('airports'))
            ->filter(fn (array $airport, string $id) => in_array($id, $airportIds, true))
            ->all();
    }

    /**
     * The cities reachable from an airport on this leg.
     *
     * The customer chooses a CITY before a stop, because the city is what seats are
     * counted against — every stop in it shares the same ten seats on a given run.
     *
     * @return array<string, array<string, mixed>>
     */
    public function cityGroupsFor(string $direction, string $airportId): array
    {
        $cityIds = collect($this->ratesFor($direction, $airportId))->pluck('cityGroupId')->unique()->all();

        return collect($this->enabled('city_groups'))
            ->filter(fn (array $city, string $id) => in_array($id, $cityIds, true))
            ->all();
    }

    /**
     * An airport's terminals, in the admin's own order.
     *
     * The panel has let an admin name these since it was built — Juanda has "Terminal 1"
     * and "Terminal 2" — and until 2026-09-01 NOTHING read them: not this site, not the
     * spec, not the mobile app contract, and not the panel's own booking screens. A
     * shuttle collects a passenger AT a terminal, so the driver needs to know which one.
     *
     * Stored as a plain array of strings on the airport document. A comma-separated
     * string is tolerated because that is what the panel's own input looks like while it
     * is being typed, and a document saved mid-edit should not empty the list.
     *
     * @return array<int, string>
     */
    public function terminalsOf(string $airportId): array
    {
        $terminals = $this->airport($airportId)['terminals'] ?? [];

        if (is_string($terminals)) {
            $terminals = explode(',', $terminals);
        }

        if (! is_array($terminals)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($terminal) => trim((string) $terminal),
            $terminals,
        ), fn (string $terminal) => $terminal !== ''));
    }

    /** @return array<string, mixed>|null */
    public function cityGroup(string $id): ?array
    {
        return $this->enabled('city_groups')[$id] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function airport(string $id): ?array
    {
        return $this->enabled('airports')[$id] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function rate(string $id): ?array
    {
        return $this->enabled('shuttle_rates')[$id] ?? null;
    }

    // ---- Reading --------------------------------------------------------------

    /**
     * One collection, enabled rows only, in the admin's order.
     *
     * `enable` missing is treated as ENABLED. Older documents predate the flag, and
     * hiding a vehicle type because a field was added after it was created would take
     * a live product off the site for no reason the admin could see.
     *
     * @return array<string, array<string, mixed>>
     */
    private function enabled(string $collection): array
    {
        return collect($this->documents($collection))
            ->filter(fn (array $row) => ($row['enable'] ?? true) !== false)
            ->sortBy(fn (array $row) => (int) ($row['order'] ?? 0))
            ->all();
    }

    /**
     * The collection as it came back, BEFORE `enable` is applied.
     *
     * Separate from `enabled()` because "switched off" and "we could not read it" are
     * different answers and callers have to tell them apart. `Firestore::collection()`
     * returns an EMPTY ARRAY when the read fails — it does not throw — so after
     * filtering, a Google outage and an admin unticking every box look identical.
     * `published()` below is what makes them distinguishable again.
     *
     * @return array<string, array<string, mixed>>
     */
    private function documents(string $collection): array
    {
        if (isset($this->memo[$collection])) {
            return $this->memo[$collection];
        }

        return $this->memo[$collection] = Cache::remember(
            self::CACHE_PREFIX.$collection,
            $this->secondsFor($collection),
            fn () => $this->firestore->collection($collection),
        );
    }

    /**
     * `services` is held for far less than the rest of the catalogue.
     *
     * Its `enable` field is the switch that takes a service off the website, and an
     * admin flips it and looks straight at the site. Five minutes of the old answer
     * reads as the switch not working — which is exactly how it was reported on
     * 2026-09-09, a minute after being flipped.
     *
     * Everything else here is a fleet or a timetable: edited weekly, and nobody stands
     * over the site waiting for it.
     */
    private function secondsFor(string $collection): int
    {
        return $collection === 'services'
            ? (int) config('firebase.services_cache_seconds', 30)
            : $this->cacheSeconds;
    }

    /**
     * Did this collection come back with anything at all, switched on or off?
     *
     * False means the read produced nothing — Firestore unreachable, the rules refusing,
     * or a project with no data — and NOT that the admin has retired everything. The two
     * cannot be told apart any other way, and they call for opposite behaviour: a
     * service switched off must disappear from the site, while an unreadable catalogue
     * must not empty the shop over a bad second at Google.
     *
     * The panel calls charter and airport-shuttle core services that cannot be removed,
     * so `services` coming back completely empty is never a legitimate state.
     */
    public function published(string $collection): bool
    {
        return $this->documents($collection) !== [];
    }
}

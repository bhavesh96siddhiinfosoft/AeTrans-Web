<?php

/*
|--------------------------------------------------------------------------
| Bookings
|--------------------------------------------------------------------------
|
| These values are shared with the admin panel, which holds the same numbers in its
| own config/bookings.php and config/availability.php. They are duplicated here
| because the two applications are deployed separately — but they are a CONTRACT, not
| a local preference. Change one without the other and the website quotes a price the
| panel disagrees with, or frees inventory the panel thinks is held.
|
*/

return [

    /*
     * The statuses a booking can hold. The website only ever writes `pending`: an
     * order it takes is a request, and accepting it is the operator's act.
     */
    'statuses' => ['pending', 'confirmed', 'completed', 'cancelled'],

    /* What the website writes on a new booking, and what `source` marks it as. */
    'initial_status' => 'pending',

    'source' => 'online',

    /*
    |--------------------------------------------------------------------------
    | Charter rental duration from distance
    |--------------------------------------------------------------------------
    |
    | The client's rule, confirmed 2026-08-11: a charter booking whose total distance
    | EXCEEDS this many kilometres bills as an extra day, in multiples.
    |
    |     distanceDays = max(1, ceil(distanceKm / threshold))
    |
    | STRICTLY greater, in the client's own boundaries:
    |
    |       500 km -> 1 day        1,000 km -> 2 days
    |       501 km -> 2 days       1,001 km -> 3 days
    |
    | `ceil()` gives exactly that: 500/500 = 1.0 ceils to 1, and 501/500 = 1.002 ceils
    | to 2. Writing it as `floor(km / t) + 1` would bill two days for a 500 km trip.
    */
    'distance_day_threshold_km' => 500,

    /*
    |--------------------------------------------------------------------------
    | Which statuses hold inventory
    |--------------------------------------------------------------------------
    |
    | Charter and shuttle differ, and the difference is the client's, not an oversight.
    |
    | A charter booking holds its VEHICLE from the moment it arrives — the van cannot
    | be promised to anyone else while an operator decides.
    |
    | A shuttle SEAT is only taken when the order is accepted: *"available seats
    | decrease when an order is received (and has been paid for and accepted by the
    | admin)"*. So a pending shuttle order holds no seat, and a customer can book a
    | seat and still be refused — which the flow has to say at the point of booking,
    | not afterwards.
    |
    | `cancelled` is absent from both: a cancelled booking must release what it held,
    | or the fleet silently shrinks every time a customer backs out. `completed` still
    | consumes, because it consumed the vehicle on the day it ran.
    */
    'charter_consuming_statuses' => ['pending', 'confirmed', 'completed'],

    'shuttle_consuming_statuses' => ['confirmed', 'completed'],

    /*
     * Seats on a shuttle RUN with no `shuttle_trips` document of its own.
     *
     * A seat pool is `airportId + cityGroupId + direction + date + runIndex` — a city and
     * a departure, not a whole day. Every drop point in the city shares these ten on that
     * run. Changed 2026-08-26 with the panel's shuttle contract; it was one pool per
     * airport + direction + day before.
     *
     * A missing document means this default, not zero: the admin only writes one when
     * they change a run's capacity or cancel it, so an unplanned run is a full one.
     */
    'default_run_seats' => 10,

    /*
    |--------------------------------------------------------------------------
    | Lead time
    |--------------------------------------------------------------------------
    |
    | The earliest a customer may travel, counted in days from today. ONE means the
    | first bookable date is tomorrow — the client's rule of 2026-08-21: no same-day
    | bookings on either service.
    |
    | The reason is operational, not technical. Every website booking arrives as
    | `pending` and has to be read, priced and accepted by a person, and a driver has
    | to be given the job — none of which can be relied on to happen before a customer
    | expects to be collected this afternoon.
    |
    | Kept here rather than written into each screen: the two services, four date
    | fields and their validation rules all read it, and a lead time expressed in four
    | places is a lead time that will eventually differ between them.
    */
    'lead_time_days' => 1,

    /*
     * How far ahead a customer may book. Matches the panel's `availability.window_days`,
     * which also governs how far ahead shuttle trips are materialised — a date beyond
     * it has no trip document and no operator planning behind it.
     */
    'booking_window_days' => 90,

    /*
    |--------------------------------------------------------------------------
    | When a day stops looking comfortable
    |--------------------------------------------------------------------------
    |
    | At or below this many vehicles left, a day on the booking calendar is amber
    | rather than green. The panel holds the same number as `availability.limited_upto`
    | and draws its own calendar from it, so the two must agree: a customer shown a
    | green day that the operator's screen calls limited is being told two things.
    |
    | The grid and its own legend both read this, which is what keeps a key saying
    | "3 or fewer" from sitting over a month that ambers at four.
    */
    'limited_upto' => 3,

    /*
    |--------------------------------------------------------------------------
    | How long availability may be reused
    |--------------------------------------------------------------------------
    |
    | `availability_blocks` and `bookings` are read to work out which vans are free.
    | They are the two collections nothing else caches, and they cost about two seconds
    | each over the REST API — which was the whole of the booking screen's five-second
    | load.
    |
    | Cached for SECONDS, not minutes, and deliberately shorter than everything else the
    | site caches. What goes stale here is somebody else's booking arriving, and the
    | worst it can do is show a van as free for a few seconds after it was taken. The
    | customer is not sold anything by the calendar: the reserve transaction re-reads the
    | locks inside the transaction and refuses, which is what actually prevents
    | double-selling. Cleared the moment this site writes a booking, so a customer never
    | sees their own van still on offer.
    |
    | Set to 0 to read live every time.
    */
    'availability_cache_seconds' => 30,

    /*
    |--------------------------------------------------------------------------
    | Where the map picker opens when there is no pin yet
    |--------------------------------------------------------------------------
    |
    | The client's instruction, 2026-09-08. This is the MAP's starting point, NOT a
    | value put into the address boxes: those stay empty until the customer chooses
    | something. Opening the picker on an empty field lands here with the pin down,
    | instead of at the country-wide view it used to show.
    |
    | Prefilling the boxes was tried first and is deliberately not what this is. A field
    | carrying an address the customer never chose reads as their answer — and with both
    | boxes holding the same place the trip measured 0 km, which the customer could book
    | without noticing.
    |
    | The COORDINATES are the point of it, because the distance is measured from the pin
    | rather than from the words. Geocoded from the address the client supplied and
    | returned ROOFTOP — the building itself.
    |
    | Change the address and the coordinates TOGETHER: text that no longer matches its
    | pin is exactly the failure this step exists to prevent.
    |
    | Set `address` to an empty string to go back to the region-wide opening view.
    |
    */
    'default_address' => [
        'address' => 'Jl. Minangkabau Barat No.2f, RT.1/RW.10, Kuningan, Ps. Manggis, Kecamatan Setiabudi, Kota Jakarta Selatan, Daerah Khusus Ibukota Jakarta 12970, Indonesia',
        'lat' => '-6.208973',
        'lng' => '106.8454',
    ],

];

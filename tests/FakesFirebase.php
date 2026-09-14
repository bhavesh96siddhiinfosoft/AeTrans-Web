<?php

namespace Tests;

use App\Models\User;
use App\Services\Booking\SeatAvailability;
use App\Services\Booking\VehicleAvailability;
use App\Services\Firebase\FirestoreValue;
use App\Services\Site\Catalog;
use App\Services\Site\CmsPages;
use App\Services\Site\Currency;
use App\Services\Site\Languages;
use App\Services\Site\SiteSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Stands in for Firebase Auth and Firestore in the test suite.
 *
 * The tests below the auth layer are about OUR rules — that a UID is mirrored, that a
 * blocked customer is refused, that a failed sign-in is throttled — and none of them
 * should need a network or a live Firebase project to answer.
 *
 * The fakes speak Google's wire format rather than a tidied-up version of it, down to
 * `integerValue` arriving as a string and `error.message` carrying a trailing
 * explanation after a colon. A fake that is neater than the real API tests a client
 * we do not have.
 */
trait FakesFirebase
{
    /** A real-shaped Firebase UID: 28 base62 characters, not a UUID. */
    protected string $firebaseUid = 'g7I1L1sOb6dsCn0O1HmAcBgBfg83';

    /** The catalogue stubs, kept so `fakeBookingWrites()` can be layered on top. */
    protected array $catalogStubs = [];

    /**
     * Single Firestore documents, keyed by path — `['bookings/abc123' => [...fields]]`.
     *
     * For the reads that fetch ONE document rather than a collection. Set before calling
     * `fakeCatalog()`; see the ordering note there for why they cannot simply be layered
     * on afterwards.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $firestoreDocuments = [];

    /**
     * @param  array{uid?: string, email?: string, name?: string, provider?: string,
     *               emailVerified?: bool, disabled?: bool, blocked?: bool|null}  $options
     */
    protected function fakeFirebase(array $options = []): void
    {
        $uid = $options['uid'] ?? $this->firebaseUid;
        $email = $options['email'] ?? 'customer@example.com';
        $name = $options['name'] ?? 'Test Customer';
        // Google's own vocabulary here: `password`, `google.com`, `phone`.
        $provider = $options['provider'] ?? 'password';
        $verified = $options['emailVerified'] ?? false;
        $disabled = $options['disabled'] ?? false;
        /*
         * `blocked` says what Firestore answers with:
         *   null    — no profile document yet, the common case after registering here
         *   true    — an operator has suspended this customer
         *   false   — a profile document that allows them through
         *   'error' — Firestore is down, so the check cannot be made at all
         */
        $blocked = $options['blocked'] ?? null;

        $tokens = [
            'idToken' => 'fake-id-token',
            'refreshToken' => 'fake-refresh-token',
            'expiresIn' => '3600',
            'localId' => $uid,
            'email' => $email,
        ];

        $this->stub([
            '*identitytoolkit.googleapis.com/v1/accounts:signUp*' => Http::response($tokens),
            '*identitytoolkit.googleapis.com/v1/accounts:signInWithPassword*' => Http::response($tokens),
            '*identitytoolkit.googleapis.com/v1/accounts:update*' => Http::response($tokens),
            '*identitytoolkit.googleapis.com/v1/accounts:sendOobCode*' => Http::response(['email' => $email]),
            '*identitytoolkit.googleapis.com/v1/accounts:lookup*' => Http::response([
                'users' => [[
                    'localId' => $uid,
                    'email' => $email,
                    'displayName' => $name,
                    'emailVerified' => $verified,
                    'disabled' => $disabled,
                    'providerUserInfo' => [['providerId' => $provider]],
                ]],
            ]),
            '*securetoken.googleapis.com*' => Http::response([
                'id_token' => 'fake-id-token-2',
                'refresh_token' => 'fake-refresh-token-2',
                'expires_in' => '3600',
            ]),
            /*
             * Firestore is one host serving two different calls: the GET that reads the
             * profile, and the PATCH that writes it back. They need different answers —
             * a missing document is a 404 on read and a perfectly ordinary success on
             * write, since a masked patch creates it.
             */
            '*firestore.googleapis.com*' => function ($request) use ($blocked) {
                if ($request->method() === 'PATCH') {
                    return $blocked === 'error'
                        ? Http::response(['error' => ['message' => 'backend error']], 503)
                        : Http::response(['name' => 'projects/test/databases/(default)/documents/users/x']);
                }

                return match (true) {
                    $blocked === null => Http::response(['error' => ['message' => 'Document not found.']], 404),
                    $blocked === 'error' => Http::response(['error' => ['message' => 'backend error']], 503),
                    default => Http::response(['fields' => ['blocked' => ['booleanValue' => $blocked]]]),
                };
            },
        ]);
    }

    /**
     * Stands in for the `settings` collection the header, footer and meta tags read.
     *
     * Takes the plain shape an admin would recognise — `['globalValue' => ['web_logo'
     * => '…']]` — and returns it in Firestore's `documents` envelope, so the reader is
     * exercised on the JSON it will really meet rather than on a convenience format.
     *
     * The cache is dropped first: it is shared for the whole test process, and without
     * this the second test in a file would quietly assert against the first one's
     * settings.
     *
     * The `languages` and `cms_pages` collections are answered here as well, because the
     * shell reads all three and an unanswered call is a stray request the base TestCase
     * refuses. English is
     * the default in tests — real life has Bahasa Indonesia there, but pinning the
     * tests to `/en` keeps the assertions readable in the language they are written in.
     *
     * @param  array<string, array<string, mixed>>  $documents
     * @param  array<string, array<string, mixed>>|null  $languages
     */
    protected function fakeSettings(array $documents, ?array $languages = null, array $cmsPages = []): void
    {
        app(SiteSettings::class)->forget();
        app(Languages::class)->forget();
        app(CmsPages::class)->forget();

        $languages ??= [
            'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true, 'isRtl' => false],
            'id-doc' => ['code' => 'id', 'name' => 'Bahasa Indonesia', 'enable' => true, 'isDefault' => false, 'isRtl' => false],
        ];

        $this->stub([
            '*firestore.googleapis.com*/documents/settings*' => Http::response($this->documentList('settings', $documents)),
            '*firestore.googleapis.com*/documents/languages*' => Http::response($this->documentList('languages', $languages)),
            // The footer lists the admin's CMS pages, so this is a third collection the
            // shell cannot render without. Empty unless a test is about the pages.
            '*firestore.googleapis.com*/documents/cms_pages*' => Http::response($this->documentList('cms_pages', $cmsPages)),
        ]);
    }

    /** Wraps plain arrays in the `documents` envelope the REST API really returns. */
    private function documentList(string $collection, array $documents): array
    {
        return [
            'documents' => collect($documents)->map(fn (array $fields, string $id) => [
                'name' => 'projects/test/databases/(default)/documents/'.$collection.'/'.$id,
                'fields' => FirestoreValue::encodeFields($fields),
            ])->values()->all(),
        ];
    }

    /**
     * The collections the booking flows read: what the admin has published.
     *
     * One enabled vehicle type of each size, one enabled airport with two drop points,
     * and Rupiah — enough to exercise the capacity rule, the pricing and both
     * directions without a live project. `$extra` merges more collections in or
     * replaces these.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $extra
     */
    /**
     * What `users/{uid}` answers with while the catalogue is stubbed.
     *
     * A test that needs a customer with no stored phone number sets this to `[]` before
     * calling `fakeCatalog()`.
     *
     * @var array<string, mixed>
     */
    protected array $firestoreProfile = [
        'displayName' => 'Test Customer',
        'phone' => '+62 811 2233 4455',
        'email' => 'customer@example.com',
    ];

    protected function fakeCatalog(array $extra = []): void
    {
        app(Catalog::class)->forget();
        app(Currency::class)->forget();
        // Availability is cached for seconds in real life; between two assertions in one
        // test it would answer the second fleet with the first one's vans, or the second
        // timetable with the first one's seats.
        app(VehicleAvailability::class)->forget();
        app(SeatAvailability::class)->forget();

        $collections = array_merge([
            'services' => [
                'svc-charter' => ['id' => 'svc-charter', 'slug' => 'charter', 'name' => 'Rental + Driver', 'enable' => true, 'order' => 1],
                'svc-shuttle' => ['id' => 'svc-shuttle', 'slug' => 'airport-shuttle', 'name' => 'Travel Shuttle', 'enable' => true, 'order' => 2],
            ],
            'vehicle_types' => [
                'type-small' => [
                    'id' => 'type-small', 'name' => 'Xenia', 'serviceId' => 'svc-charter',
                    'seatCapacity' => 6, 'dailyRate' => 600000, 'perKmRate' => 1100,
                    'minDailyRental' => 1, 'enable' => true, 'order' => 1,
                ],
                'type-large' => [
                    'id' => 'type-large', 'name' => 'Hiace Commuter', 'serviceId' => 'svc-charter',
                    'seatCapacity' => 14, 'dailyRate' => 1000000, 'perKmRate' => 3000,
                    'minDailyRental' => 1, 'enable' => true, 'order' => 2,
                ],
            ],
            'vehicle_units' => [
                'unit-a' => ['id' => 'unit-a', 'vehicleTypeId' => 'type-small', 'plateNumber' => '001', 'enable' => true, 'order' => 1],
                'unit-b' => ['id' => 'unit-b', 'vehicleTypeId' => 'type-large', 'plateNumber' => '002', 'enable' => true, 'order' => 2],
            ],
            'airports' => [
                // `terminals` is what the shuttle's step 1 asks about — the live Juanda
                // document carries exactly these two.
                'apt-sub' => ['id' => 'apt-sub', 'name' => 'Juanda Surabaya Airport', 'city' => 'Surabaya', 'code' => 'SUB', 'terminals' => ['Terminal 1', 'Terminal 2'], 'enable' => true, 'order' => 1],
            ],
            /*
             * The cities seats are counted against. One row of seats per city per run —
             * every stop in a city shares it. See docs/shuttle-app-integration.md in the
             * panel repo, which is the contract this shape comes from.
             */
            'city_groups' => [
                'city-sby' => ['id' => 'city-sby', 'name' => 'Surabaya City', 'code' => 'SBY', 'enable' => true, 'order' => 1],
                'city-ngw' => ['id' => 'city-ngw', 'name' => 'Ngawi City', 'code' => 'NGW', 'enable' => true, 'order' => 2],
            ],
            /*
             * ONE document per route, carrying BOTH legs: `direction` is an array of the
             * legs it runs and `departureTimes` is a map keyed by leg. Two stops in
             * Surabaya so the city filter has something to do, and one in Ngawi.
             *
             * The outbound and return timetables differ on purpose — coming back, the
             * same bus reaches each stop at its own time, and run 1 is run 1 either way.
             */
            'shuttle_rates' => [
                'rate-city' => [
                    'id' => 'rate-city', 'airportId' => 'apt-sub', 'airportName' => 'Juanda Surabaya Airport',
                    'cityGroupId' => 'city-sby', 'cityGroupName' => 'Surabaya City',
                    'dropPoint' => 'Surabaya City Center', 'fixCost' => 250000,
                    'direction' => ['from_airport', 'to_airport'],
                    'departureTimes' => [
                        'from_airport' => ['09:00', '13:15'],
                        'to_airport' => ['05:00', '11:30'],
                    ],
                    'enable' => true, 'order' => 1,
                ],
                'rate-tunjungan' => [
                    'id' => 'rate-tunjungan', 'airportId' => 'apt-sub', 'airportName' => 'Juanda Surabaya Airport',
                    'cityGroupId' => 'city-sby', 'cityGroupName' => 'Surabaya City',
                    'dropPoint' => 'Tunjungan Plaza', 'fixCost' => 250000,
                    'direction' => ['from_airport', 'to_airport'],
                    'departureTimes' => [
                        'from_airport' => ['09:00', '13:15'],
                        // The same two buses, collected later on the way back.
                        'to_airport' => ['04:30', '11:00'],
                    ],
                    'enable' => true, 'order' => 2,
                ],
                'rate-ngawi' => [
                    'id' => 'rate-ngawi', 'airportId' => 'apt-sub', 'airportName' => 'Juanda Surabaya Airport',
                    'cityGroupId' => 'city-ngw', 'cityGroupName' => 'Ngawi City',
                    'dropPoint' => 'Ngawi (Pasar Karangjati)', 'fixCost' => 400000,
                    'direction' => ['from_airport'],
                    'departureTimes' => ['from_airport' => ['16:00']],
                    'enable' => true, 'order' => 3,
                ],
            ],
            'shuttle_trips' => [],
            /*
             * Empty by default: a fleet with nothing in the workshop and nothing sold.
             * `VehicleAvailability` reads both to work out which vans are free, so a
             * test that wants a taken vehicle passes rows in through `$extra`.
             */
            'availability_blocks' => [],
            'bookings' => [],
            /*
             * The footer lists the admin's CMS pages, so every rendered page reads this
             * collection. Empty by default — a test about a booking should not have to
             * know what is on the About page — and passed in through `$extra` by the
             * tests that are about the pages themselves.
             */
            'cms_pages' => [],
            'currency' => [
                'cur-idr' => ['id' => 'cur-idr', 'code' => 'IDR', 'symbol' => 'Rp', 'name' => 'Indonesian Rupiah', 'decimalDigits' => 0, 'symbolAtRight' => false, 'enable' => true],
            ],
        ], $extra);

        $stubs = [];

        // SINGLE documents first, and the order is the point.
        //
        // `Http::fake()` answers with the FIRST pattern that matches, and a collection
        // pattern ending in `bookings` followed by a wildcard matches
        // `/documents/bookings/abc123` just as happily as it matches the collection read.
        // Registered after the loop below, a single-document stub would be dead — the
        // controller would get an empty collection envelope and report "no such booking".
        //
        // (Line comments rather than a block: the patterns this is about contain the
        // two characters that close one.)
        foreach ($this->firestoreDocuments as $path => $fields) {
            $stubs['*firestore.googleapis.com*/documents/'.$path] = Http::response([
                'name' => 'projects/test/databases/(default)/documents/'.$path,
                'fields' => FirestoreValue::encodeFields($fields),
            ]);
        }

        foreach ($collections as $name => $documents) {
            $stubs['*firestore.googleapis.com*/documents/'.$name.'*'] = Http::response($this->documentList($name, $documents));
        }

        /*
         * The signed-in customer's own profile document — `users/{uid}`, a SINGLE
         * document rather than a collection envelope, which is what the details step
         * reads to prefill the name and the phone number.
         *
         * Registered here because `stub()` replaces the whole set: a test that signs a
         * customer in and then re-arms the catalogue would otherwise leave this read
         * unanswered, and the base TestCase refuses a stray request.
         */
        $stubs['*firestore.googleapis.com*/documents/users/*'] = Http::response([
            'name' => 'projects/test/databases/(default)/documents/users/'.$this->firebaseUid,
            'fields' => FirestoreValue::encodeFields($this->firestoreProfile),
        ]);

        /*
         * The shell still needs its own two collections; without them every page in
         * these tests would fall back and the header would say the wrong thing.
         *
         * `settings` may be passed in through `$extra` like any other collection — the
         * booking's last screen reads the published bank account out of it — and only
         * defaults to empty when a test says nothing about it.
         */
        $stubs['*firestore.googleapis.com*/documents/settings*'] = Http::response(
            $this->documentList('settings', $extra['settings'] ?? []),
        );
        $stubs['*firestore.googleapis.com*/documents/languages*'] = Http::response($this->documentList(
            'languages',
            // Overridable like `settings`: a test about translated content has to be able
            // to enable the language it is testing, or `SetLocale` falls back to English
            // and the test silently checks nothing.
            $extra['languages'] ?? [
                'en-doc' => ['code' => 'en', 'name' => 'English', 'enable' => true, 'isDefault' => true, 'isRtl' => false],
            ],
        ));

        app(SiteSettings::class)->forget();
        app(Languages::class)->forget();

        $this->catalogStubs = $stubs;

        $this->stub($stubs);
    }

    /**
     * The Firestore transaction endpoints a booking is written through.
     *
     * `$locks` is what `runQuery` finds already held — the way to stage a vehicle that
     * somebody else took while this customer was filling the form in. `$commitFails`
     * makes the commit answer ABORTED, which is what Firestore really says when another
     * writer got there first.
     *
     * Call AFTER `fakeCatalog()`: it replaces the whole stub set, so the catalogue has
     * to be re-registered alongside.
     *
     * @param  array<int, array<string, mixed>>  $locks
     */
    protected function fakeBookingWrites(array $locks = [], ?string $commitFails = null): void
    {
        $existing = $this->catalogStubs ?? [];

        $this->stub(array_merge($existing, [
            '*/documents:beginTransaction*' => Http::response(['transaction' => 'fake-transaction']),

            '*/documents:runQuery*' => Http::response(array_map(fn (array $lock) => [
                'document' => [
                    'name' => 'projects/test/databases/(default)/documents/availability_locks/'.$lock['id'],
                    'fields' => FirestoreValue::encodeFields($lock),
                ],
            ], $locks)),

            '*/documents:commit*' => $commitFails
                ? Http::response(['error' => ['status' => $commitFails, 'message' => 'contention']], 409)
                : Http::response(['commitTime' => '2026-08-21T00:00:00Z']),

            '*/documents:rollback*' => Http::response([]),
        ]));
    }

    /** The last booking written, decoded from the commit that carried it. */
    /**
     * The booking as it went ON THE WIRE, before decoding.
     *
     * For assertions about the ENCODING itself — that a coordinate is a Firestore
     * GeoPoint and not a map of two numbers, say. `writtenBooking()` decodes, and a
     * decoder that is wrong in the same way as the encoder would agree with itself.
     *
     * @return array<string, array<string, mixed>>|null
     */
    /**
     * Every `Write` in the commits that were sent.
     *
     * The booking is only one of them: a charter also writes an availability lock per
     * day, and a booking with a coupon carries the increment that spends it. Tests about
     * what travels ALONGSIDE the booking read this rather than the booking itself.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function committedWrites(): array
    {
        $writes = [];

        foreach (collect(Http::recorded())->map(fn (array $pair) => $pair[0]) as $request) {
            if (! str_contains($request->url(), 'documents:commit')) {
                continue;
            }

            foreach ($request->data()['writes'] ?? [] as $write) {
                $writes[] = $write;
            }
        }

        return $writes;
    }

    protected function writtenBookingRaw(): ?array
    {
        foreach (collect(Http::recorded())->map(fn (array $pair) => $pair[0]) as $request) {
            if (! str_contains($request->url(), 'documents:commit')) {
                continue;
            }

            foreach ($request->data()['writes'] ?? [] as $write) {
                if (str_contains((string) ($write['update']['name'] ?? ''), '/bookings/')) {
                    return $write['update']['fields'] ?? [];
                }
            }
        }

        return null;
    }

    protected function writtenBooking(): ?array
    {
        foreach (collect(Http::recorded())->map(fn (array $pair) => $pair[0]) as $request) {
            if (! str_contains($request->url(), 'documents:commit')) {
                continue;
            }

            foreach ($request->data()['writes'] ?? [] as $write) {
                if (str_contains((string) ($write['update']['name'] ?? ''), '/bookings/')) {
                    return FirestoreValue::decodeFields($write['update']['fields'] ?? []);
                }
            }
        }

        return null;
    }

    /** Every availability lock written by the last commit, keyed by document id. */
    protected function writtenLocks(): array
    {
        $locks = [];

        foreach (collect(Http::recorded())->map(fn (array $pair) => $pair[0]) as $request) {
            if (! str_contains($request->url(), 'documents:commit')) {
                continue;
            }

            foreach ($request->data()['writes'] ?? [] as $write) {
                $name = (string) ($write['update']['name'] ?? '');

                if (str_contains($name, '/availability_locks/')) {
                    $locks[basename($name)] = [
                        'fields' => FirestoreValue::decodeFields($write['update']['fields'] ?? []),
                        'createOnly' => ($write['currentDocument']['exists'] ?? null) === false,
                    ];
                }
            }
        }

        return $locks;
    }

    /** Firestore unreachable, so every page has to fall back to what it knows. */
    protected function fakeSettingsUnavailable(): void
    {
        app(SiteSettings::class)->forget();
        app(Languages::class)->forget();

        $this->stub([
            '*firestore.googleapis.com*' => Http::response(['error' => ['message' => 'backend error']], 503),
        ]);
    }

    /**
     * Makes every Firebase Auth call fail with one code.
     *
     * Call it AFTER `fakeFirebase()` when a test needs the sign-in to succeed and a
     * later call to fail — the second `Http::fake()` replaces the first.
     */
    protected function fakeFirebaseRefusal(string $code, string $detail = ''): void
    {
        $this->stub([
            '*identitytoolkit.googleapis.com*' => Http::response([
                'error' => ['message' => $detail ? $code.' : '.$detail : $code],
            ], 400),
            '*firestore.googleapis.com*' => Http::response([], 404),
        ]);
    }

    /**
     * Registers stubs, REPLACING any already in place.
     *
     * `Http::fake()` merges instead of replacing, and the first stub that matches a URL
     * wins — so a second call meant to make one endpoint fail is silently ignored while
     * the earlier success stub keeps answering. Dropping the resolved factory is what
     * actually gives a clean slate, and several tests below depend on it: they sign a
     * customer in successfully and then make the NEXT Firebase call fail.
     */
    /**
     * Adds to the catalogue's stubs rather than replacing them.
     *
     * `stub()` swaps the whole set, which is right when a test is staging a different
     * world — and wrong when it only wants one more endpoint answered.
     *
     * @param  array<string, mixed>  $stubs
     */
    protected function stubWith(array $stubs): void
    {
        $this->stub(array_merge($this->catalogStubs ?? [], $stubs));
    }

    private function stub(array $stubs): void
    {
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();

        /*
         * Re-armed on the NEW factory. `preventStrayRequests()` is a flag on the
         * factory instance, and dropping the instance above drops the flag with it —
         * so without this line every stub swap quietly reopened the network, and the
         * suite went back to talking to the live Firebase project. It showed up as the
         * run time jumping from three seconds to forty.
         */
        Http::preventStrayRequests();

        Http::fake($stubs);
    }

    /**
     * Signs a customer in through the real login route, so the session carries the
     * Firebase tokens that the pages after sign-in depend on.
     *
     * `actingAs()` cannot do this: it puts a user id in the session and nothing else,
     * and the profile and password screens then behave as though the Firebase session
     * had expired.
     */
    protected function signInCustomer(array $options = []): User
    {
        $this->fakeFirebase($options);

        $this->post('/login', [
            'email' => $options['email'] ?? 'customer@example.com',
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        return User::firebaseUid($options['uid'] ?? $this->firebaseUid)->firstOrFail();
    }

    /** Asserts a Firebase endpoint was called — `accounts:sendOobCode`, say. */
    protected function assertFirebaseCalled(string $endpoint): void
    {
        Http::assertSent(fn ($request) => str_contains($request->url(), $endpoint));
    }

    protected function assertFirebaseNotCalled(string $endpoint): void
    {
        Http::assertNotSent(fn ($request) => str_contains($request->url(), $endpoint));
    }

    /** @param  TestResponse  $response */
    protected function assertRedirectedToDashboard(TestResponse $response): void
    {
        $response->assertRedirect(route('bookings', absolute: false));
    }
}

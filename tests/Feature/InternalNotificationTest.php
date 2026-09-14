<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\BookingStatusNotificationController as Endpoint;
use App\Mail\BookingStatusMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Mail;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The door the admin panel knocks on when an operator changes a booking's status.
 *
 * A shared secret is the whole of the authentication here, so most of what follows is
 * about what happens when it is wrong, missing, or never configured.
 */
class InternalNotificationTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private const SECRET = 'a-long-shared-secret-value';

    private const BOOKING = 'jBaXo2436zF8H1BPHJ5D';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.internal.secret' => self::SECRET]);
    }

    /**
     * The document the endpoint reads back, staged as a SINGLE Firestore document —
     * `bookings/{id}`, not the collection envelope.
     */
    private function fakeBooking(array $extra = []): void
    {
        // Set BEFORE `fakeCatalog()`: single-document stubs have to be registered ahead
        // of the collection patterns that would otherwise swallow them.
        $this->firestoreDocuments['bookings/'.self::BOOKING] = array_merge([
            'id' => self::BOOKING,
            'bookingType' => 'charter',
            'customerName' => 'Budi Santoso',
            'customerEmail' => 'budi@example.com',
            'travelDate' => '2026-09-14',
            'pickupTime' => '08:30',
            'pickupAddress' => 'Juanda International Airport',
            'passengers' => 11,
            'cost' => 3555300,
            'currencyCode' => 'IDR',
            'status' => 'pending',
        ], $extra);

        $this->fakeCatalog();
    }

    private function notify(array $body, ?string $secret = self::SECRET)
    {
        return $this->postJson('/internal/booking-status', $body, $secret === null ? [] : [
            Endpoint::HEADER => $secret,
        ]);
    }

    private function flushDeferred(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }

    public function test_the_panel_can_have_a_status_email_sent(): void
    {
        Mail::fake();
        $this->fakeBooking();

        $this->notify(['booking' => self::BOOKING, 'status' => 'confirmed'])
            ->assertOk()
            ->assertJson(['sent' => true, 'status' => 'confirmed']);

        $this->flushDeferred();

        Mail::assertSent(
            BookingStatusMail::class,
            fn ($mail) => $mail->hasTo('budi@example.com') && $mail->status === 'confirmed',
        );
    }

    /**
     * The status GIVEN wins over the one stored.
     *
     * The panel knows what it just wrote; the document may already have moved on, and an
     * operator correcting a mis-click within the same minute would otherwise be told
     * about the correction rather than the change.
     */
    public function test_the_status_given_beats_the_one_on_the_document(): void
    {
        Mail::fake();
        $this->fakeBooking(['status' => 'pending']);

        $this->notify(['booking' => self::BOOKING, 'status' => 'cancelled'])->assertOk();
        $this->flushDeferred();

        Mail::assertSent(BookingStatusMail::class, fn ($mail) => $mail->status === 'cancelled');
    }

    /** With none given, the document's own status is used. */
    public function test_the_status_can_be_left_to_the_document(): void
    {
        Mail::fake();
        $this->fakeBooking(['status' => 'completed']);

        $this->notify(['booking' => self::BOOKING])->assertOk()->assertJson(['status' => 'completed']);
        $this->flushDeferred();

        Mail::assertSent(BookingStatusMail::class, fn ($mail) => $mail->status === 'completed');
    }

    // ---- What protects it -----------------------------------------------------

    public function test_a_wrong_secret_is_refused(): void
    {
        Mail::fake();
        $this->fakeBooking();

        $this->notify(['booking' => self::BOOKING, 'status' => 'confirmed'], 'not-the-secret')
            ->assertStatus(401);

        $this->flushDeferred();

        Mail::assertNothingSent();
    }

    public function test_no_secret_at_all_is_refused(): void
    {
        Mail::fake();
        $this->fakeBooking();

        $this->notify(['booking' => self::BOOKING], null)->assertStatus(401);

        $this->flushDeferred();

        Mail::assertNothingSent();
    }

    /**
     * AN UNCONFIGURED SECRET REFUSES EVERYTHING.
     *
     * The opposite default — no secret configured means no check — is how an endpoint
     * ends up wide open on the day somebody deploys from `.env.example`. Here it fails
     * closed, and the refusal is logged with whether a secret was configured at all, so
     * the cause is visible rather than guessed at.
     */
    public function test_an_unconfigured_secret_refuses_everything(): void
    {
        Mail::fake();
        config(['services.internal.secret' => '']);
        $this->fakeBooking();

        // Including a caller that sends an empty signature, which would otherwise MATCH.
        $this->notify(['booking' => self::BOOKING], '')->assertStatus(401);
        $this->notify(['booking' => self::BOOKING], null)->assertStatus(401);

        $this->flushDeferred();

        Mail::assertNothingSent();
    }

    /**
     * The caller cannot name a recipient.
     *
     * This is what bounds a leaked secret: the address comes off the booking, so the
     * worst an attacker achieves is sending a real customer a true email about their own
     * booking. An `email` field in the body must be ignored, not honoured.
     */
    public function test_the_caller_cannot_choose_who_it_reaches(): void
    {
        Mail::fake();
        $this->fakeBooking();

        $this->notify([
            'booking' => self::BOOKING,
            'status' => 'confirmed',
            'email' => 'attacker@example.com',
        ])->assertOk();

        $this->flushDeferred();

        Mail::assertSent(BookingStatusMail::class, fn ($mail) => $mail->hasTo('budi@example.com'));
        Mail::assertNotSent(BookingStatusMail::class, fn ($mail) => $mail->hasTo('attacker@example.com'));
    }

    // ---- The quiet cases ------------------------------------------------------

    /**
     * A booking with no email address is NOT reported as a failure.
     *
     * An operator can enter a phone booking with no address, and there is nothing wrong
     * with it. A 500 here would put a red toast on the panel every time one of those
     * bookings was confirmed.
     */
    public function test_a_booking_with_no_address_answers_quietly(): void
    {
        Mail::fake();
        $this->fakeBooking(['customerEmail' => '']);

        $this->notify(['booking' => self::BOOKING, 'status' => 'confirmed'])
            ->assertOk()
            ->assertJson(['sent' => false]);

        $this->flushDeferred();

        Mail::assertNothingSent();
    }

    public function test_an_unknown_booking_is_a_404(): void
    {
        Mail::fake();

        // No document staged at all, which the collection stub answers as an empty list
        // and `Firestore::document()` reports as "not there".
        $this->fakeCatalog();

        $this->notify(['booking' => 'nope'])->assertStatus(404);
    }

    public function test_a_request_with_no_booking_id_is_refused(): void
    {
        $this->fakeCatalog();

        $this->notify(['status' => 'confirmed'])->assertStatus(422);
    }
}

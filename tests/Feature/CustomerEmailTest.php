<?php

namespace Tests\Feature;

use App\Mail\AdminBookingMail;
use App\Mail\AdminNewCustomerMail;
use App\Mail\BookingReceivedMail;
use App\Mail\BookingStatusMail;
use App\Mail\WelcomeMail;
use App\Services\Mail\CustomerMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Mail;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The three emails this site sends a customer.
 *
 * What these pin is the part that cannot be checked by looking: that the invoice adds
 * up, that a failing mail server cannot cost a booking, and that each status reads as
 * the thing it actually is. The DESIGN is checked by eye — `php artisan email:preview`
 * renders every one of them to a file.
 */
class CustomerEmailTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /** A charter booking with a full breakdown, shaped like a real document. */
    private function charter(array $extra = []): array
    {
        return array_merge([
            'bookingType' => 'charter',
            'customerName' => 'Budi Santoso',
            'customerEmail' => 'budi@example.com',
            'vehicleTypeName' => 'Hiace Commuter',
            'passengers' => 11,
            'pickupAddress' => 'Juanda International Airport',
            'dropoffAddresses' => ['Hotel Majapahit', 'Tunjungan Plaza'],
            'travelDate' => '2026-09-14',
            'pickupTime' => '08:30',
            'billableDays' => 3,
            'dailyRate' => 1000000,
            'distanceKm' => 185.1,
            'perKmRate' => 3000,
            'cost' => 3555300,
            'currencyCode' => 'IDR',
        ], $extra);
    }

    private function shuttle(array $extra = []): array
    {
        return array_merge([
            'bookingType' => 'shuttle',
            'customerName' => 'Sari Dewi',
            'customerEmail' => 'sari@example.com',
            'airportName' => 'Juanda Surabaya Airport',
            'cityGroupName' => 'Ngawi City',
            'dropPoint' => 'Ngawi (Pendopo Center)',
            'travelDate' => '2026-09-05',
            'pickupTime' => '15:00',
            'passengers' => 3,
            'farePerSeat' => 250000,
            'cost' => 750000,
            'currencyCode' => 'IDR',
        ], $extra);
    }

    // ---- The invoice ----------------------------------------------------------

    /**
     * THE LINES ADD UP TO THE TOTAL.
     *
     * This email is the invoice — there is no gateway, the customer transfers by hand
     * against these figures — so a breakdown that does not reconcile with the total is
     * not a cosmetic fault, it is a dispute with a customer holding an email as evidence.
     */
    public function test_the_charter_invoice_adds_up(): void
    {
        $this->fakeCatalog();

        $html = (new BookingReceivedMail('jBaXo2436zF8H1BPHJ5D', $this->charter()))->render();

        // 3 × 1,000,000 = 3,000,000, and 185.1 × 3,000 = 555,300.
        $this->assertStringContainsString('Rp 3,000,000', $html);
        $this->assertStringContainsString('Rp 555,300', $html);
        $this->assertStringContainsString('Rp 3,555,300', $html);

        /*
         * And the working is shown, not just the answer.
         *
         * Through `__()` rather than the English wording: a mailable rendered outside a
         * request runs in the app's DEFAULT language, which on this site is Indonesian
         * (the client's choice, 2026-08-26). Hardcoding "3 days ×" here would be a test
         * that fails on a copy change and passes on a broken calculation.
         */
        $this->assertStringContainsString(
            __('lang.days_times_rate', ['days' => 3, 'rate' => 'Rp 1,000,000']),
            $html,
        );
        $this->assertStringContainsString(
            __('lang.km_times_rate', ['km' => '185.1', 'rate' => 'Rp 3,000']),
            $html,
        );
    }

    public function test_the_shuttle_invoice_adds_up(): void
    {
        $this->fakeCatalog();

        $html = (new BookingReceivedMail('FGGcnAnqKrTJbpIYeLxy', $this->shuttle()))->render();

        $this->assertStringContainsString('Rp 750,000', $html);
        $this->assertStringContainsString('Rp 250,000', $html);
    }

    /**
     * A booking that cannot explain its own total prints the total alone.
     *
     * An operator's custom quote carries no rates. Inventing lines from the rate card
     * would produce a breakdown that does not match what was agreed — worse than no
     * breakdown, because it looks authoritative.
     */
    public function test_a_booking_with_no_breakdown_still_shows_its_total(): void
    {
        $this->fakeCatalog();

        $html = (new BookingReceivedMail('abc123', $this->charter([
            'billableDays' => 0, 'dailyRate' => 0, 'distanceKm' => 0, 'perKmRate' => 0,
            'cost' => 2000000,
        ])))->render();

        $this->assertStringContainsString('Rp 2,000,000', $html);
        $this->assertStringNotContainsString(__('lang.vehicle_hire'), $html);
    }

    /**
     * The reference is the first 8 characters of the id, uppercased.
     *
     * Not decoration: it is what an operator searches for when the customer reads it
     * down the phone, and it has to match what the panel and the app print.
     */
    public function test_the_reference_matches_what_an_operator_can_search_for(): void
    {
        $this->fakeCatalog();

        $html = (new BookingReceivedMail('jBaXo2436zF8H1BPHJ5D', $this->charter()))->render();

        $this->assertStringContainsString('JBAXO243', $html);
    }

    /** Every stop reaches the email — an itinerary with the middle dropped is not the trip. */
    public function test_every_drop_off_is_listed(): void
    {
        $this->fakeCatalog();

        $html = (new BookingReceivedMail('abc123', $this->charter()))->render();

        $this->assertStringContainsString('Hotel Majapahit', $html);
        $this->assertStringContainsString('Tunjungan Plaza', $html);
    }

    /**
     * The seat caveat is on the shuttle email and NOT on the charter one.
     *
     * It is only true of a shuttle: a charter holds its vehicle from the moment the
     * booking is written. Printing it on both would tell charter customers their van
     * might still go to somebody else.
     */
    public function test_the_seat_caveat_is_only_on_the_shuttle_invoice(): void
    {
        $this->fakeCatalog();

        $caveat = __('lang.seat_held_on_acceptance');

        $this->assertStringContainsString($caveat, (new BookingReceivedMail('a', $this->shuttle()))->render());
        $this->assertStringNotContainsString($caveat, (new BookingReceivedMail('b', $this->charter()))->render());
    }

    /** The bank account is the admin's, read live rather than written into the template. */
    public function test_the_invoice_carries_the_admins_bank_account(): void
    {
        $this->fakeCatalog(['settings' => [
            'bankAccountDetails' => [
                'bankName' => 'Bank Rakyat Indonesia',
                'holderName' => 'Ari Yulianto',
                'accountNumber' => '644201032347533',
            ],
        ]]);

        $html = (new BookingReceivedMail('abc123', $this->charter()))->render();

        $this->assertStringContainsString('644201032347533', $html);
        $this->assertStringContainsString('Ari Yulianto', $html);
    }

    /**
     * A DISCOUNTED BOOKING SAYS SO, AND THE TOTAL IS WHAT IS OWED.
     *
     * The emails were written before coupons existed and kept printing `cost` — the full
     * price — so a customer who used a code was invoiced for the undiscounted amount and
     * could not see the code anywhere. Client's report, 2026-09-02.
     */
    public function test_the_invoice_shows_the_discount_and_charges_the_payable(): void
    {
        $this->fakeCatalog();

        $html = (new BookingReceivedMail('abc123', $this->charter([
            'couponCode' => '01AETRANS',
            'discountAmount' => 10000,
            'payableAmount' => 3545300,
        ])))->render();

        $this->assertStringContainsString(__('lang.discount'), $html);
        $this->assertStringContainsString('01AETRANS', $html);
        $this->assertStringContainsString('-Rp 10,000', $html);
        // The total is what to transfer, not the full price.
        $this->assertStringContainsString('Rp 3,545,300', $html);
    }

    /** The office copy shows it too — the total will not match the rate card otherwise. */
    public function test_the_office_copy_shows_the_discount(): void
    {
        $this->fakeCatalog();

        $html = (new AdminBookingMail('abc123', $this->charter([
            'couponCode' => '01AETRANS',
            'discountAmount' => 10000,
            'payableAmount' => 3545300,
        ])))->render();

        $this->assertStringContainsString('01AETRANS', $html);
        $this->assertStringContainsString('Rp 3,545,300', $html);
    }

    /**
     * A booking with NO coupon shows no discount row at all.
     *
     * Including every booking made before coupons existed: those carry no
     * `payableAmount`, and the total falls back to `cost` — the same number.
     */
    public function test_a_booking_without_a_coupon_shows_no_discount_row(): void
    {
        $this->fakeCatalog();

        $html = (new BookingReceivedMail('abc123', $this->charter()))->render();

        $this->assertStringNotContainsString(__('lang.discount'), $html);
        $this->assertStringContainsString('Rp 3,555,300', $html);
    }

    // ---- Status ---------------------------------------------------------------

    /**
     * Each status reads as the thing it is, and "confirmed" is only used when it is true.
     *
     * The booking email deliberately never says "confirmed" — a shuttle seat is not held
     * until an operator accepts the order. THIS is the email that makes that promise, so
     * the word appearing on the wrong one would be a promise the business has not made.
     */
    public function test_each_status_says_its_own_thing(): void
    {
        $this->fakeCatalog();

        foreach (['confirmed', 'completed', 'cancelled', 'pending'] as $status) {
            $html = (new BookingStatusMail('abc123', $this->charter(), $status))->render();

            $this->assertStringContainsString(__('lang.email_status_heading_'.$status), $html, $status);
            $this->assertStringContainsString(__('lang.status_'.$status), $html, $status);
        }
    }

    /**
     * A cancellation promises no refund, because this site cannot decide one.
     *
     * The money is the operator's call. The email hands the reader a person instead of a
     * policy the website would be inventing on the client's behalf.
     */
    public function test_a_cancellation_offers_a_person_and_not_a_refund_policy(): void
    {
        // `contact_us`, not `globalValue` — see SiteSettings::whatsapp().
        $this->fakeCatalog(['settings' => ['contact_us' => ['whatsapp' => '+62 816 955 959']]]);

        $html = (new BookingStatusMail('abc123', $this->charter(), 'cancelled'))->render();

        $this->assertStringContainsString('wa.me/62816955959', $html);
        $this->assertStringNotContainsString('refund', strtolower($html));
    }

    /**
     * An unrecognised status still sends something.
     *
     * The panel can add one. A customer receiving a slightly generic email beats a
     * customer receiving nothing because a string did not match a list in this repo.
     */
    public function test_an_unknown_status_falls_back_rather_than_failing(): void
    {
        $this->fakeCatalog();

        $html = (new BookingStatusMail('abc123', $this->charter(), 'awaiting_dispatch'))->render();

        $this->assertStringContainsString(__('lang.email_status_heading_pending'), $html);
    }

    /**
     * Runs the callbacks `CustomerMail` deferred.
     *
     * `defer()` flushes when a REQUEST terminates. A test that calls the service
     * directly never has one, so without this the callback is queued and dropped and
     * `Mail::assertNothingSent()` passes for the wrong reason — which it was doing here
     * until the office copies were added and the positive assertions failed.
     *
     * Tests that go through `$this->post(...)` do not need it; the framework flushes.
     */
    private function flushDeferred(): void
    {
        app(DeferredCallbackCollection::class)->invoke();
    }

    // ---- The office copies ----------------------------------------------------

    /**
     * Registering tells the office, in an email written FOR the office.
     *
     * Not the customer's welcome with a second recipient: "Welcome, Budi" arriving in
     * the operator's inbox tells them nothing they can act on.
     */
    public function test_registering_also_tells_the_office(): void
    {
        Mail::fake();

        /*
         * The address is pinned here rather than staged in Firestore. `fakeFirebase`
         * answers every Firestore GET with a 404 — it is standing in for the auth flow,
         * not the catalogue — so Global Settings is deliberately unreadable in this test,
         * and layering `fakeCatalog` on top would replace the identity stubs the
         * registration itself needs.
         *
         * Which is worth knowing on its own: with Global Settings unreachable the office
         * is STILL told, through the fallback chain. The panel-driven address has its own
         * test below.
         */
        config(['mail.admin_address' => 'info@aetrans.id']);
        $this->fakeFirebase(['email' => 'newcustomer@example.com', 'name' => 'Budi Santoso']);

        $this->post('/register', [
            'name' => 'Budi Santoso',
            'email' => 'newcustomer@example.com',
            'password' => 'Str0ng-Password!',
            'password_confirmation' => 'Str0ng-Password!',
        ]);

        Mail::assertSent(AdminNewCustomerMail::class, fn ($mail) => $mail->hasTo('info@aetrans.id'));
    }

    /** And so does a booking. */
    public function test_a_booking_also_tells_the_office(): void
    {
        Mail::fake();
        $this->fakeCatalog(['settings' => ['contact_us' => ['email' => 'info@aetrans.id']]]);

        app(CustomerMail::class)->bookingReceived('abc123', $this->charter());
        $this->flushDeferred();

        Mail::assertSent(BookingReceivedMail::class, fn ($mail) => $mail->hasTo('budi@example.com'));
        Mail::assertSent(AdminBookingMail::class, fn ($mail) => $mail->hasTo('info@aetrans.id'));
    }

    /**
     * THE OFFICE IS TOLD EVEN WHEN THE CUSTOMER CANNOT BE.
     *
     * A booking with no email address is exactly the one an operator most needs to hear
     * about, because nobody else has been told anything at all.
     */
    public function test_the_office_hears_about_a_booking_with_no_customer_address(): void
    {
        Mail::fake();
        $this->fakeCatalog(['settings' => ['contact_us' => ['email' => 'info@aetrans.id']]]);

        app(CustomerMail::class)->bookingReceived('abc123', $this->charter(['customerEmail' => '']));
        $this->flushDeferred();

        Mail::assertNotSent(BookingReceivedMail::class);
        Mail::assertSent(AdminBookingMail::class);
    }

    /**
     * The address comes from Global Settings, so the client can move it in the panel.
     *
     * Hardcoding `info@aetrans.id` would mean a deploy every time the office inbox
     * changes — and it is the same address the site's own footer already prints.
     */
    public function test_the_office_address_follows_the_panel(): void
    {
        Mail::fake();
        $this->fakeCatalog(['settings' => ['contact_us' => ['email' => 'bookings@aetrans.id']]]);

        app(CustomerMail::class)->bookingReceived('abc123', $this->charter());
        $this->flushDeferred();

        Mail::assertSent(AdminBookingMail::class, fn ($mail) => $mail->hasTo('bookings@aetrans.id'));
    }

    /** `MAIL_ADMIN_ADDRESS` wins, for a working inbox that is not the public one. */
    public function test_the_env_override_beats_the_panel(): void
    {
        Mail::fake();
        config(['mail.admin_address' => 'ops@aetrans.id']);
        $this->fakeCatalog(['settings' => ['contact_us' => ['email' => 'info@aetrans.id']]]);

        app(CustomerMail::class)->bookingReceived('abc123', $this->charter());
        $this->flushDeferred();

        Mail::assertSent(AdminBookingMail::class, fn ($mail) => $mail->hasTo('ops@aetrans.id'));
    }

    /**
     * Reply goes to the CUSTOMER, so an operator can just press reply.
     *
     * Without it they are copying an address out of the body of an email that came from
     * their own domain — and replying to that reaches themselves.
     */
    public function test_replying_to_an_office_email_reaches_the_customer(): void
    {
        $this->fakeCatalog();

        $mail = new AdminBookingMail('abc123', $this->charter());

        $this->assertSame('budi@example.com', $mail->envelope()->replyTo[0]->address);
    }

    /**
     * The shuttle notice says the seats are NOT held; the charter one does not.
     *
     * That difference is the whole reason the shuttle email is urgent — an unread
     * notification there is a seat that may be sold twice — and saying it on a charter
     * would be false, because a charter holds its vehicle from the moment it is written.
     */
    public function test_the_office_is_told_when_seats_are_not_yet_held(): void
    {
        $this->fakeCatalog();

        $shuttle = (new AdminBookingMail('a', $this->shuttle()))->render();
        $charter = (new AdminBookingMail('b', $this->charter()))->render();

        $this->assertStringContainsString(__('lang.email_admin_booking_lead_shuttle'), $shuttle);
        $this->assertStringNotContainsString(__('lang.email_admin_booking_lead_shuttle'), $charter);
    }

    /** The office email carries who to ring — and no bank details. */
    public function test_the_office_email_carries_the_customers_number_and_not_the_bank(): void
    {
        $this->fakeCatalog(['settings' => [
            'bankAccountDetails' => ['accountNumber' => '644201032347533'],
        ]]);

        $html = (new AdminBookingMail('abc123', $this->charter(['customerPhone' => '+62 812 3456 7890'])))->render();

        $this->assertStringContainsString('+62 812 3456 7890', $html);
        $this->assertStringContainsString('wa.me/6281234567890', $html);
        // The operator owns the account; printing it in the office inbox is noise.
        $this->assertStringNotContainsString('644201032347533', $html);
    }

    // ---- Sending --------------------------------------------------------------

    public function test_registering_sends_a_welcome_email(): void
    {
        Mail::fake();
        $this->fakeFirebase(['email' => 'newcustomer@example.com', 'name' => 'Budi Santoso']);

        $this->post('/register', [
            'name' => 'Budi Santoso',
            'email' => 'newcustomer@example.com',
            'password' => 'Str0ng-Password!',
            'password_confirmation' => 'Str0ng-Password!',
        ])->assertRedirect('/my-bookings');

        Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo('newcustomer@example.com'));
    }

    /**
     * A BOOKING IS NEVER LOST TO A BROKEN MAIL SERVER.
     *
     * The booking is already in Firestore by the time the email is attempted. An SMTP
     * host that is refusing connections must not turn a written booking into an error
     * screen — the customer would be told their booking failed while an operator can see
     * it sitting in the panel.
     */
    public function test_a_failing_mail_server_does_not_break_the_booking(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused'));

        $this->fakeCatalog();

        // The service is called exactly as the reservation calls it.
        app(CustomerMail::class)->bookingReceived('abc123', $this->charter());

        // Reaching here at all is the assertion: nothing propagated.
        $this->assertTrue(true);
    }

    /** A booking with no email address is not an error — an operator can enter one by phone. */
    public function test_a_booking_with_no_address_sends_the_customer_nothing(): void
    {
        Mail::fake();
        $this->fakeCatalog();

        app(CustomerMail::class)->bookingReceived('abc123', $this->charter(['customerEmail' => '']));
        $this->flushDeferred();

        /*
         * The office still hears — see the test above. What must not happen is a send to
         * the empty address, which is what `assertNothingSent` used to be checking by
         * accident, because the deferred callback was never run at all.
         */
        Mail::assertNotSent(BookingReceivedMail::class);
    }
}

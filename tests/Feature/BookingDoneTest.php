<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * The screen a booking ends on.
 *
 * Two things have to be right here, because everything after this point happens off the
 * website: the reference the customer reads out to an operator, and the account they
 * transfer the fare to. There is no payment gateway — the client's answer, seen in the
 * mobile app on 2026-08-26, is a bank transfer with the proof sent on WhatsApp.
 */
class BookingDoneTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    private const BOOKING_ID = 'LQdZuXsPq1a2b3c4d5e6';

    /**
     * The reference is DERIVED, not stored.
     *
     * The panel's list column renders `row.id.slice(0, 8)` and the app prints it
     * uppercased — so the website has to arrive at the same eight characters, or a
     * customer reading theirs out sends the operator hunting for a booking that does
     * not exist under that name.
     */
    public function test_the_reference_is_the_first_eight_characters_of_the_id_uppercased(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/done/'.self::BOOKING_ID)
            ->assertOk()
            ->assertSee('LQDZUXSP')
            ->assertSee('Booking reference');
    }

    /** Not "confirmed": what has happened is that a request reached the operator. */
    public function test_the_booking_is_shown_as_pending_and_not_as_confirmed(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/done/'.self::BOOKING_ID)
            ->assertOk()
            ->assertSee('Booking received')
            ->assertSee('Pending')
            ->assertDontSee('Booking confirmed');
    }

    public function test_the_bank_account_the_admin_published_is_shown(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer();
        $this->fakeCatalog(['settings' => [
            'bankAccountDetails' => [
                'bankName' => 'Bank Central Asia',
                'holderName' => 'PT Aetrans Indonesia',
                'accountNumber' => '1234567890',
                'branchName' => 'Ngawi',
            ],
            'contact_us' => ['whatsapp' => '+62 811 2233 4455'],
        ]]);

        $this->get('/book/done/'.self::BOOKING_ID)
            ->assertOk()
            ->assertSee('Pay via bank transfer')
            ->assertSee('Bank Central Asia')
            ->assertSee('PT Aetrans Indonesia')
            ->assertSee('1234567890')
            ->assertSee('Ngawi')
            // Nothing was published for these two, so no blank rows for them.
            ->assertDontSee('SWIFT')
            ->assertDontSee('Bank code');
    }

    /**
     * An unconfigured account is not a broken page.
     *
     * The reference is what the customer actually needs from this screen, and it has to
     * survive the admin not having filled the payment section in yet.
     */
    public function test_the_page_still_works_when_no_bank_account_is_published(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer();
        $this->fakeCatalog();

        $this->get('/book/done/'.self::BOOKING_ID)
            ->assertOk()
            ->assertSee('LQDZUXSP')
            ->assertDontSee('Pay via bank transfer');
    }

    /** The WhatsApp button carries the reference, so the operator can find the order. */
    public function test_the_whatsapp_link_names_the_booking(): void
    {
        $this->fakeCatalog();
        $this->signInCustomer();
        $this->fakeCatalog(['settings' => [
            'contact_us' => ['whatsapp' => '+62 811 2233 4455'],
        ]]);

        $this->get('/book/done/'.self::BOOKING_ID)
            ->assertOk()
            ->assertSee('wa.me/6281122334455', false)
            ->assertSee('LQDZUXSP');
    }

    public function test_a_visitor_cannot_open_a_booking_page(): void
    {
        $this->fakeCatalog();

        $this->get('/book/done/'.self::BOOKING_ID)->assertRedirect('/login');
    }
}

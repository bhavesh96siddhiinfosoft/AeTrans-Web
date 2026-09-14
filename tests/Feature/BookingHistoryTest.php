<?php

namespace Tests\Feature;

use App\Services\Firebase\FirestoreValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * My bookings.
 *
 * The page a customer comes back to. It is also, since the client's request of
 * 2026-08-26, where they find the bank details for an order they have not paid for —
 * the confirmation screen is seen once and lost.
 */
class BookingHistoryTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /**
     * Stages the customer's own bookings, as `runQuery` returns them.
     *
     * Called AFTER signing in, because staging replaces the whole stub set — the
     * sign-in's own Firebase stubs are finished with by then, but these are not.
     */
    private function fakeBookings(array $bookings, array $settings = []): void
    {
        $this->fakeCatalog($settings === [] ? [] : ['settings' => $settings]);

        $this->stubWith([
            '*/documents:runQuery*' => Http::response(array_map(fn (array $booking) => [
                'document' => [
                    'name' => 'projects/test/databases/(default)/documents/bookings/'.$booking['id'],
                    'fields' => FirestoreValue::encodeFields($booking),
                ],
            ], $bookings)),
        ]);
    }

    private function booking(array $overrides = []): array
    {
        return array_merge([
            'id' => 'bk1234567890',
            'bookingType' => 'charter',
            'serviceName' => 'Rental + Driver',
            'status' => 'pending',
            'pickupAddress' => 'Jl. Raya Ngawi 1',
            'travelDate' => now()->addDays(3)->toDateString(),
            'pickupTime' => '08:30',
            'cost' => 1360000,
            'currencyCode' => 'IDR',
            'createdAt' => now()->toIso8601String(),
        ], $overrides);
    }

    /**
     * A SHUTTLE booking says where it goes from, and from which terminal.
     *
     * It carries no `pickupAddress` and no `dropoffAddresses` — those are charter fields
     * — so until 2026-09-01 this screen showed a shuttle customer nothing but the date
     * and the price. The airport is where they are collected; the terminal is where at
     * the airport, and a passenger reading this back needs both.
     */
    public function test_a_shuttle_booking_shows_its_airport_terminal_and_stop(): void
    {
        $this->signInCustomer();

        $this->fakeBookings([$this->booking([
            'bookingType' => 'shuttle',
            'serviceName' => 'Travel Shuttle',
            'pickupAddress' => null,
            'airportName' => 'Juanda Surabaya Airport',
            'terminal' => 'Terminal 2',
            'dropPoint' => 'Ngawi (Pondok Gontor)',
        ])]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSeeInOrder([
                __('lang.airport'),
                'Juanda Surabaya Airport',
                __('lang.terminal'),
                'Terminal 2',
                __('lang.drop_point'),
                'Ngawi (Pondok Gontor)',
            ]);
    }

    /**
     * A booking made BEFORE the terminal existed has no empty row.
     *
     * Every shuttle booked before 2026-09-01 is in this state. A blank "Terminal —"
     * reads as information the booking has lost, rather than a question it was never
     * asked.
     */
    public function test_a_shuttle_booked_before_terminals_shows_no_terminal_row(): void
    {
        $this->signInCustomer();

        $this->fakeBookings([$this->booking([
            'bookingType' => 'shuttle',
            'pickupAddress' => null,
            'airportName' => 'Juanda Surabaya Airport',
            'dropPoint' => 'Ngawi (Pondok Gontor)',
        ])]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSee('Juanda Surabaya Airport')
            ->assertDontSee(__('lang.terminal'));
    }

    /**
     * A DISCOUNTED BOOKING SHOWS THE CODE AND WHAT IS OWED.
     *
     * `cost` is the full price and the discount sits beside it — the client's decision,
     * 2026-09-02 — so a screen that printed only `cost` told a customer who used a code
     * the wrong number. Client's report, 2026-09-02.
     */
    public function test_a_discounted_booking_shows_the_code_and_the_payable(): void
    {
        $this->signInCustomer();

        $this->fakeBookings([$this->booking([
            'cost' => 1200000,
            'discountAmount' => 10000,
            'payableAmount' => 1190000,
            'couponCode' => '01AETRANS',
        ])]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSeeInOrder([
                __('lang.subtotal'),
                'Rp 1,200,000',
                __('lang.discount'),
                '01AETRANS',
                __('lang.total_to_pay'),
                'Rp 1,190,000',
            ]);
    }

    /**
     * A booking with no coupon reads exactly as it always did.
     *
     * Including every booking made before coupons existed: those carry no
     * `payableAmount`, and the row says "Cost" rather than a subtotal with nothing
     * taken off it.
     */
    public function test_a_booking_without_a_coupon_shows_a_plain_cost(): void
    {
        $this->signInCustomer();

        $this->fakeBookings([$this->booking(['cost' => 1200000])]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSee(__('lang.cost'))
            ->assertSee('Rp 1,200,000')
            ->assertDontSee(__('lang.discount'))
            ->assertDontSee(__('lang.total_to_pay'));
    }

    public function test_a_customer_sees_the_bookings_they_have_made(): void
    {
        $this->signInCustomer();
        $this->fakeBookings([$this->booking()]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSee('Jl. Raya Ngawi 1')
            ->assertSee('Rp 1,360,000');
    }

    /**
     * The reference is the document id's first eight characters, uppercased — what the
     * app prints and what the panel's list column shows, so an operator can search for
     * whatever the customer reads out.
     */
    public function test_each_booking_carries_the_reference_an_operator_can_search_for(): void
    {
        $this->signInCustomer();
        $this->fakeBookings([$this->booking(['id' => 'lqdzuxspAB12'])]);

        $this->get('/my-bookings')->assertOk()->assertSee('LQDZUXSP');
    }

    /**
     * The client, 2026-08-26: *"a customer hasn't made the payment yet but forgot to
     * note down the bank account number. Could the payment details also be displayed on
     * the My Orders page if the status is still pending"*.
     */
    public function test_an_unpaid_booking_shows_the_bank_details_again(): void
    {
        $this->signInCustomer();
        $this->fakeBookings([$this->booking(['status' => 'pending'])], [
            'bankAccountDetails' => [
                'bankName' => 'Bank Mandiri',
                'holderName' => 'Aetrans ID',
                'accountNumber' => '1400012345678',
            ],
        ]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSee('Bank Mandiri')
            ->assertSee('1400012345678');
    }

    /**
     * Only while it is pending. Once an operator has accepted the booking the money has
     * arrived, and leaving a "pay this" panel on it is how somebody pays twice.
     */
    public function test_an_accepted_booking_does_not_ask_for_payment_again(): void
    {
        $this->signInCustomer();
        $this->fakeBookings([$this->booking(['status' => 'confirmed'])], [
            'bankAccountDetails' => ['accountNumber' => '1400012345678'],
        ]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertDontSee('1400012345678');
    }

    public function test_a_customer_with_nothing_booked_is_offered_the_two_services(): void
    {
        $this->signInCustomer();
        $this->fakeBookings([]);

        $this->get('/my-bookings')
            ->assertOk()
            ->assertSee(__('Nothing booked yet'))
            ->assertSee(route('book.charter'), false);
    }

    /** The page belongs to an account; a visitor is sent to sign in. */
    public function test_a_visitor_cannot_read_anybodys_bookings(): void
    {
        $this->fakeCatalog();

        $this->get('/my-bookings')->assertRedirect('/login');
    }
}

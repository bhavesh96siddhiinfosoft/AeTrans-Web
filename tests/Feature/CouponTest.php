<?php

namespace Tests\Feature;

use App\Services\Booking\Coupons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\FakesFirebase;
use Tests\TestCase;

/**
 * Discount codes, as the admin panel defines them.
 *
 * A coupon is money, so most of what follows is about REFUSING one: every rule on the
 * document is a way for a code to be wrong, and the expensive failure is honouring a
 * discount the business did not offer.
 *
 * The collection is SINGULAR — `coupon` — matching `currency`, because that is what the
 * client calls it.
 */
class CouponTest extends TestCase
{
    use FakesFirebase, RefreshDatabase;

    /** The client's own live coupon, field for field. */
    private function coupon(array $overrides = []): array
    {
        return ['coupon' => ['cpn-1' => array_merge([
            'id' => 'cpn-1',
            'code' => '01AETRANS',
            'discountType' => 'fixed',
            'discountValue' => 10000,
            'maxDiscount' => null,
            'minOrderAmount' => null,
            'enable' => true,
            'startDate' => '2020-01-01',
            'endDate' => '2099-12-31',
            'serviceIds' => ['svc-charter', 'svc-shuttle'],
            'usageLimit' => 1000,
            'usageLimitPerUser' => 1,
            'usedCount' => 0,
            'firstBookingOnly' => false,
            'currencyCode' => 'IDR',
        ], $overrides)]];
    }

    private function coupons(): Coupons
    {
        return app(Coupons::class);
    }

    private function check(array $overrides = [], float $amount = 250000, string $service = 'svc-charter')
    {
        $this->fakeCatalog($this->coupon($overrides));

        return $this->coupons()->check('01AETRANS', $service, $amount, null);
    }

    // ---- Finding one ----------------------------------------------------------

    /**
     * A code is matched however it was typed.
     *
     * It is read off a poster and pasted with a trailing space. Refusing "01aetrans " is
     * refusing a real customer a discount the business is advertising.
     */
    public function test_a_code_is_found_whatever_the_case_and_spacing(): void
    {
        $this->fakeCatalog($this->coupon());

        foreach (['01AETRANS', '01aetrans', '  01AeTrans  ', "01AETRANS\n"] as $typed) {
            $this->assertSame('01AETRANS', $this->coupons()->find($typed)['code'] ?? null, $typed);
        }
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->fakeCatalog($this->coupon());

        $result = $this->coupons()->check('NOPE', 'svc-charter', 250000, null);

        $this->assertFalse($result->ok);
        $this->assertSame(__('lang.coupon_not_found'), $result->message);
    }

    // ---- The arithmetic -------------------------------------------------------

    public function test_a_fixed_amount_comes_straight_off(): void
    {
        $result = $this->check();

        $this->assertTrue($result->ok);
        $this->assertSame(10000.0, $result->discount);
    }

    public function test_a_percentage_is_worked_out_on_the_amount(): void
    {
        $this->assertSame(25000.0, $this->check([
            'discountType' => 'percent', 'discountValue' => 10,
        ])->discount);
    }

    /** `maxDiscount` caps a percentage. The panel hides the field for a fixed amount. */
    public function test_a_percentage_is_capped_by_max_discount(): void
    {
        $this->assertSame(15000.0, $this->check([
            'discountType' => 'percent', 'discountValue' => 50, 'maxDiscount' => 15000,
        ])->discount);
    }

    /**
     * A DISCOUNT NEVER EXCEEDS THE PRICE.
     *
     * Rp 10,000 off a Rp 8,000 fare makes the trip free. Without this it makes the
     * business owe the customer Rp 2,000, and `payableAmount` goes negative.
     */
    public function test_a_discount_never_exceeds_the_price(): void
    {
        $this->assertSame(8000.0, $this->check(amount: 8000)->discount);
    }

    // ---- Every way a code can be refused --------------------------------------

    public function test_a_disabled_coupon_is_refused(): void
    {
        $this->assertSame(__('lang.coupon_not_available'), $this->check(['enable' => false])->message);
    }

    public function test_a_coupon_that_has_not_started_is_refused(): void
    {
        $this->assertSame(
            __('lang.coupon_not_started'),
            $this->check(['startDate' => now()->addDay()->toDateString()])->message,
        );
    }

    public function test_an_expired_coupon_is_refused(): void
    {
        $this->assertSame(
            __('lang.coupon_expired'),
            $this->check(['endDate' => now()->subDay()->toDateString()])->message,
        );
    }

    /**
     * The window is INCLUSIVE at both ends.
     *
     * "Valid until the 20th" means the 20th is a day the customer can use it. The dates
     * are `YYYY-MM-DD` strings on the document, compared as strings.
     */
    public function test_the_last_day_of_the_window_still_works(): void
    {
        $this->assertTrue($this->check([
            'startDate' => now()->toDateString(),
            'endDate' => now()->toDateString(),
        ])->ok);
    }

    public function test_a_coupon_for_another_service_is_refused(): void
    {
        $this->assertSame(
            __('lang.coupon_wrong_service'),
            $this->check(['serviceIds' => ['svc-shuttle']], service: 'svc-charter')->message,
        );
    }

    /**
     * An EMPTY service list means every service.
     *
     * An admin who left the picker alone meant "anywhere", not "nowhere" — and a coupon
     * restricted to nothing is one no customer can ever spend.
     */
    public function test_a_coupon_with_no_services_works_on_all_of_them(): void
    {
        $this->assertTrue($this->check(['serviceIds' => []], service: 'svc-charter')->ok);
        $this->assertTrue($this->check(['serviceIds' => []], service: 'svc-shuttle')->ok);
    }

    public function test_a_booking_below_the_minimum_is_refused(): void
    {
        $this->assertSame(
            __('lang.coupon_below_minimum'),
            $this->check(['minOrderAmount' => 500000], amount: 250000)->message,
        );
    }

    public function test_a_fully_claimed_coupon_is_refused(): void
    {
        $this->assertSame(
            __('lang.coupon_used_up'),
            $this->check(['usageLimit' => 5, 'usedCount' => 5])->message,
        );
    }

    // ---- Through the booking flow ---------------------------------------------

    /**
     * A CODE APPLIED ON THE REVIEW STEP SHOWS WHAT IT TAKES OFF.
     *
     * The client's report was that nobody could find where to enter a code. Applying one
     * is its own post that comes straight back with the price redrawn — a customer types
     * a code to SEE what it does, and finding out by being charged the full amount is
     * not an answer.
     */
    public function test_a_code_applied_on_the_review_step_is_shown(): void
    {
        $this->readyToBookShuttle();

        $this->post('/book/shuttle/coupon', ['code' => '01aetrans'])
            ->assertRedirect('/book/shuttle/details')
            ->assertSessionHas('status');

        $this->get('/book/shuttle/details')
            ->assertOk()
            ->assertSee(__('lang.discount'))
            ->assertSee(__('lang.total_to_pay'))
            // 250,000 for one seat, less the Rp 10,000 code.
            ->assertSee('Rp 240,000');
    }

    /** A code that is refused is not left sitting in the box pretending to work. */
    public function test_a_refused_code_is_not_kept(): void
    {
        $this->readyToBookShuttle();

        $this->from('/book/shuttle/details')
            ->post('/book/shuttle/coupon', ['code' => 'NOPE'])
            ->assertSessionHasErrors('code');

        $this->get('/book/shuttle/details')->assertOk()->assertDontSee(__('lang.total_to_pay'));
    }

    /**
     * The Remove button takes the code off, and says so with its OWN flag.
     *
     * Not an empty code. The two used to be the same request and were indistinguishable,
     * so pressing Apply with nothing typed answered "Code removed" — about a code that
     * had never been applied. Client's report, 2026-09-02.
     */
    public function test_the_remove_button_takes_the_code_off(): void
    {
        $this->readyToBookShuttle();

        $this->post('/book/shuttle/coupon', ['code' => '01AETRANS']);
        $this->post('/book/shuttle/coupon', ['code' => '', 'remove' => '1'])->assertSessionHasNoErrors();

        $this->get('/book/shuttle/details')->assertOk()->assertDontSee(__('lang.total_to_pay'));
    }

    /**
     * PRESSING APPLY WITH AN EMPTY BOX IS AN ERROR, not a removal.
     *
     * Saying nothing would look like the site had ignored the customer, and the old
     * answer — "Code removed" — was worse: a report about something that had not
     * happened.
     */
    public function test_applying_an_empty_code_is_refused(): void
    {
        $this->readyToBookShuttle();

        $this->from('/book/shuttle/details')
            ->post('/book/shuttle/coupon', ['code' => ''])
            ->assertSessionHasErrors(['code' => __('lang.enter_a_discount_code')]);
    }

    /** And an already-applied code survives a stray empty Apply. */
    public function test_an_empty_apply_does_not_lose_a_working_code(): void
    {
        $this->readyToBookShuttle();

        $this->post('/book/shuttle/coupon', ['code' => '01AETRANS']);
        $this->from('/book/shuttle/details')->post('/book/shuttle/coupon', ['code' => '']);

        $this->get('/book/shuttle/details')
            ->assertOk()
            ->assertSee(__('lang.total_to_pay'))
            ->assertSee('Rp 240,000');
    }

    /**
     * THE BOOKING RECORDS THE REDEMPTION, AND `cost` STAYS THE FULL PRICE.
     *
     * The client's decision, 2026-09-02: the discount sits BESIDE the price rather than
     * inside it, so the panel, the app and every existing booking keep reading `cost` to
     * mean the same thing. `payableAmount` is what to collect.
     */
    public function test_a_booking_records_the_discount_beside_the_full_price(): void
    {
        $this->readyToBookShuttle();

        $this->post('/book/shuttle/coupon', ['code' => '01AETRANS']);

        $this->signInCustomer();
        $this->fakeCatalog($this->coupon());
        $this->fakeBookingWrites();

        $this->post('/book/shuttle/details', [
            'customerName' => 'Test Customer',
            'customerPhone' => '+62 811 2233 4455',
            'passengerNames' => ['Test Customer'],
        ])->assertRedirect();

        $booking = $this->writtenBooking();

        $this->assertSame(250000.0, (float) $booking['cost'], 'cost is the FULL price');
        $this->assertSame(10000.0, (float) $booking['discountAmount']);
        $this->assertSame(240000.0, (float) $booking['payableAmount']);
        $this->assertSame('01AETRANS', $booking['couponCode']);
        $this->assertSame('cpn-1', $booking['couponId']);
    }

    /**
     * SPENDING THE COUPON IS PART OF THE SAME COMMIT AS THE BOOKING.
     *
     * A booking that loses a race for the last vehicle must not have spent the
     * customer's one-per-person discount on the way past — and two customers redeeming
     * the last use at the same moment must not both see the old number, which is why it
     * is Firestore's own atomic increment rather than a read and a write.
     */
    public function test_the_coupon_is_spent_atomically_with_the_booking(): void
    {
        $this->readyToBookShuttle();

        $this->post('/book/shuttle/coupon', ['code' => '01AETRANS']);

        $this->signInCustomer();
        $this->fakeCatalog($this->coupon());
        $this->fakeBookingWrites();

        $this->post('/book/shuttle/details', [
            'customerName' => 'Test Customer',
            'customerPhone' => '+62 811 2233 4455',
            'passengerNames' => ['Test Customer'],
        ])->assertRedirect();

        $writes = $this->committedWrites();

        $increment = collect($writes)->firstWhere(fn (array $write) => isset($write['transform']));

        $this->assertNotNull($increment, 'the redemption travels with the booking');
        $this->assertStringContainsString('coupon/cpn-1', $increment['transform']['document']);
        $this->assertSame('usedCount', $increment['transform']['fieldTransforms'][0]['fieldPath']);
        $this->assertSame(['integerValue' => '1'], $increment['transform']['fieldTransforms'][0]['increment']);
    }

    /** No code means no redemption write, and zeroes that say so plainly. */
    public function test_a_booking_without_a_code_spends_nothing(): void
    {
        $this->readyToBookShuttle();

        $this->signInCustomer();
        $this->fakeCatalog($this->coupon());
        $this->fakeBookingWrites();

        $this->post('/book/shuttle/details', [
            'customerName' => 'Test Customer',
            'customerPhone' => '+62 811 2233 4455',
            'passengerNames' => ['Test Customer'],
        ])->assertRedirect();

        $booking = $this->writtenBooking();

        $this->assertSame('', $booking['couponCode']);
        $this->assertSame(0.0, (float) $booking['discountAmount']);
        $this->assertSame(250000.0, (float) $booking['payableAmount']);

        $this->assertNull(
            collect($this->committedWrites())->firstWhere(fn (array $write) => isset($write['transform'])),
        );
    }

    /**
     * A BROWSER GETS THE BLOCK BACK, NOT A REDIRECT.
     *
     * `coupon.js` swaps the returned markup in without a page load. The HTML is rendered
     * by the SAME partial the page was built from, so the script never formats money and
     * cannot drift away from the plain-form path.
     */
    public function test_applying_a_code_over_ajax_returns_the_rendered_block(): void
    {
        $this->readyToBookShuttle();

        $response = $this->postJson('/book/shuttle/coupon', ['code' => '01aetrans'])->assertOk();

        $response->assertJson(['ok' => true]);

        $html = $response->json('html');

        $this->assertStringContainsString('id="coupon-block"', $html);
        $this->assertStringContainsString(__('lang.total_to_pay'), $html);
        $this->assertStringContainsString('Rp 240,000', $html);
        // With a discount applied the useful action is taking it off.
        $this->assertStringContainsString(__('lang.remove'), $html);
    }

    /** A refused code comes back as a block carrying the reason, not an HTTP error. */
    public function test_a_refused_code_over_ajax_returns_the_reason(): void
    {
        $this->readyToBookShuttle();

        $response = $this->postJson('/book/shuttle/coupon', ['code' => 'NOPE'])->assertOk();

        $response->assertJson(['ok' => false]);

        $html = $response->json('html');

        $this->assertStringContainsString(__('lang.coupon_not_found'), $html);
        $this->assertStringNotContainsString(__('lang.total_to_pay'), $html);
    }

    /**
     * The plain form still redirects.
     *
     * This is the path a browser with no JavaScript takes, and it has to keep working —
     * the script is an enhancement over it, not a replacement for it.
     */
    public function test_the_plain_form_still_redirects(): void
    {
        $this->readyToBookShuttle();

        $this->post('/book/shuttle/coupon', ['code' => '01AETRANS'])
            ->assertRedirect('/book/shuttle/details');
    }

    /** A shuttle booked as far as the review step, with the coupon staged. */
    private function readyToBookShuttle(): void
    {
        $this->fakeCatalog($this->coupon());

        $this->post('/book/shuttle', [
            'direction' => 'from_airport', 'rateId' => 'rate-city', 'terminal' => 'Terminal 1',
        ]);

        $this->post('/book/shuttle/trip', [
            'travelDate' => now()->addDays(5)->toDateString(),
            'pickupTime' => '09:00',
            'passengers' => 1,
        ]);
    }

    /** No limit set is no limit, however many have been claimed. */
    public function test_a_coupon_with_no_usage_limit_keeps_working(): void
    {
        $this->assertTrue($this->check(['usageLimit' => null, 'usedCount' => 9999])->ok);
    }
}

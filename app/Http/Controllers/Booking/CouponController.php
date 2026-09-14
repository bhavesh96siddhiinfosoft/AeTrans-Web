<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\Booking\BookingDraft;
use App\Services\Booking\CharterQuote;
use App\Services\Booking\Coupons;
use App\Services\Site\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Putting a discount code on a booking in progress, and taking it off again.
 *
 * ── WHY THIS IS ITS OWN REQUEST AND NOT A FIELD ON CONFIRM ──────────────────
 *
 * A customer types a code to SEE what it does. Carrying it silently to the Confirm
 * button would mean finding out whether it worked only after the booking was made —
 * and finding out it did not by being charged the full amount. So applying a code is
 * its own post that comes straight back to the same screen with the price redrawn.
 *
 * ── TWO ANSWERS, ONE PIECE OF MARKUP ────────────────────────────────────────
 *
 * A browser running `coupon.js` gets JSON carrying the re-rendered coupon block, and the
 * script swaps it in without a page load. A browser without it gets the redirect and the
 * whole page. Both come from the SAME partial rendered by this controller, so the money
 * formatting, the button wording and the totals cannot drift apart between the two paths
 * — the script never formats anything.
 *
 * ── THE CODE IS NEVER TRUSTED FROM HERE ─────────────────────────────────────
 *
 * What the draft keeps is the CODE the customer typed, not the discount it was worth.
 * The amount is worked out again at confirm, against the price at that moment and the
 * customer's history at that moment — a coupon can be spent by somebody else, or expire,
 * between this screen and the button. See the confirm methods.
 */
class CouponController extends Controller
{
    /** The two draft types this can act on, and the service each one belongs to. */
    private const SERVICES = [
        'charter' => 'charter',
        'shuttle' => 'airport-shuttle',
    ];

    public function __construct(
        private readonly BookingDraft $draft,
        private readonly Coupons $coupons,
        private readonly Catalog $catalog,
    ) {}

    public function store(Request $request, string $type): RedirectResponse|JsonResponse
    {
        if (! isset(self::SERVICES[$type])) {
            abort(404);
        }

        $back = redirect()->route('book.'.$type.'.details');

        $validated = $request->validate([
            'code' => ['nullable', 'string', 'max:64'],
            'remove' => ['nullable'],
        ]);

        $code = trim((string) ($validated['code'] ?? ''));

        /*
         * REMOVE IS ITS OWN FLAG, not an empty code.
         *
         * The two used to be the same request, so pressing Apply with nothing typed
         * answered "Code removed" — about a code that had never been applied. Now an
         * empty box means one thing when the Remove button sent it and another when the
         * Apply button did, and the button says which.
         */
        if ($request->boolean('remove')) {
            $this->draft->forgetFields($type, 'couponCode');

            return $this->answer($request, $type, $back->with('status', __('lang.coupon_removed')), [
                'message' => __('lang.coupon_removed'),
            ]);
        }

        /*
         * Pressing Apply with an empty box is a mistake worth naming. Saying nothing
         * would look like the site had ignored the customer, and the old behaviour —
         * "Code removed" — was worse: an answer about something that had not happened.
         */
        if ($code === '') {
            return $this->answer($request, $type, $back->withErrors([
                'code' => __('lang.enter_a_discount_code'),
            ]), ['error' => __('lang.enter_a_discount_code')]);
        }

        $service = $this->catalog->service(self::SERVICES[$type]);
        $check = $this->coupons->check(
            $code,
            (string) ($service['id'] ?? ''),
            $this->amountOf($type),
            $request->user(),
        );

        if (! $check->ok) {
            /*
             * The code is NOT kept when it is refused. Keeping it would leave the box
             * filled with something that does not work and the price unchanged, which
             * reads as the site having quietly ignored the customer.
             */
            $this->draft->forgetFields($type, 'couponCode');

            return $this->answer($request, $type, $back->withErrors(['code' => $check->message]), [
                'error' => $check->message,
            ]);
        }

        // The code, not the discount. See the class comment.
        $this->draft->fill($type, ['couponCode' => $check->code()]);

        $applied = __('lang.coupon_applied', ['code' => $check->code()]);

        return $this->answer($request, $type, $back->with('status', $applied), [
            'message' => $applied,
            'discount' => $check->discount,
            'code' => $check->code(),
        ]);
    }

    /**
     * The redirect, or the re-rendered block for a browser that asked for JSON.
     *
     * The partial is rendered HERE rather than described to the script, so there is one
     * copy of the markup and one place that knows how to print money.
     *
     * @param  array{message?: string, error?: string, discount?: float, code?: string}  $state
     */
    private function answer(Request $request, string $type, RedirectResponse $back, array $state): RedirectResponse|JsonResponse
    {
        if (! $request->expectsJson()) {
            return $back;
        }

        $subtotal = $this->amountOf($type);

        return response()->json([
            'ok' => ! isset($state['error']),
            'html' => view('booking.partials.coupon', [
                'type' => $type,
                'code' => (string) ($state['code'] ?? ''),
                'subtotal' => $subtotal,
                'discount' => (float) ($state['discount'] ?? 0),
                'message' => $state['message'] ?? null,
                'error' => $state['error'] ?? null,
                'money' => app(\App\Services\Site\Currency::class),
            ])->render(),
        ]);
    }

    /**
     * What the booking comes to before any discount.
     *
     * Read from the draft the same way each service's own details screen reads it, so a
     * coupon is checked against the number the customer can see rather than a second
     * calculation that could disagree with it.
     */
    private function amountOf(string $type): float
    {
        $draft = $this->draft->all($type);

        if ($type === 'charter') {
            $vehicleType = $this->catalog->vehicleType((string) ($draft['vehicleTypeId'] ?? ''));

            // No vehicle chosen yet means no price to check a coupon against, and the
            // details screen is not reachable in that state anyway.
            if (! $vehicleType) {
                return 0.0;
            }

            return CharterQuote::for(
                $vehicleType,
                (string) ($draft['travelDate'] ?? ''),
                $draft['returnDate'] ?? null,
                (float) ($draft['distanceKm'] ?? 0),
                (string) ($draft['tripType'] ?? 'one_way'),
                // The measured loop, so a coupon preview cannot quote a different total
                // from the review screen it sits on.
                isset($draft['roundTripKm']) ? (float) $draft['roundTripKm'] : null,
            )->total;
        }

        $rate = $this->catalog->rate((string) ($draft['rateId'] ?? ''));

        return (float) ($rate['fixCost'] ?? 0) * max(1, (int) ($draft['passengers'] ?? 1));
    }
}

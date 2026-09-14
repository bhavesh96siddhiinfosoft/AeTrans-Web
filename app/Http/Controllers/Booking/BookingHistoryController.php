<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\Firestore;
use App\Services\Site\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A customer's own bookings.
 *
 * Read from Firestore by `userId`, which is the customer's Firebase UID — the same field
 * the panel's customer screen links a booking to an account by. A booking taken over the
 * phone by an operator carries no `userId`, so it will not appear here; that is correct,
 * because this page is "what I booked myself", not "everything about me".
 *
 * ── PAYMENT DETAILS ON A PENDING BOOKING ────────────────────────────────────
 *
 * The client, 2026-08-26: *"a customer hasn't made the payment yet but forgot to note
 * down the bank account number. Could the payment details also be displayed on the My
 * Orders page if the status is still pending"*. So a pending booking carries the bank
 * account and a WhatsApp button on this page as well as on the screen it was made on —
 * the confirmation screen is seen once and lost, and this page is where somebody comes
 * back to.
 *
 * Only while it is PENDING. Once an operator has accepted the booking the money has
 * arrived, and leaving a "pay this" panel on a confirmed order is how a customer pays
 * twice.
 */
class BookingHistoryController extends Controller
{
    public function __invoke(
        Request $request,
        Firestore $firestore,
        FirebaseSession $firebase,
        SiteSettings $site,
    ): View {
        $uid = (string) ($request->user()->uuid ?? '');

        $bookings = $uid === ''
            ? []
            : $firestore->query('bookings', 'userId', $uid, $firebase->idToken());

        return view('booking.history', [
            'bookings' => $this->newestFirst($bookings),
            /*
             * Fetched once for the page rather than per booking — every pending order
             * shows the same account, and this is one Firestore read either way.
             */
            'bank' => $site->bankAccount(),
        ]);
    }

    /**
     * Newest first, by when the booking was TAKEN.
     *
     * Not by when it travels: somebody opening this page has just booked something, and
     * what they are looking for is the thing they just did. Sorted here rather than in
     * the query because ordering on a field the query also filters by needs a composite
     * index somebody has to create in the console first — and this is a handful of
     * documents.
     *
     * @param  array<string, array<string, mixed>>  $bookings
     * @return array<string, array<string, mixed>>
     */
    private function newestFirst(array $bookings): array
    {
        uasort($bookings, function (array $a, array $b) {
            // `createdAt` is an RFC-3339 string through the REST API, which sorts
            // correctly as text. A booking without one sorts last rather than crashing.
            return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
        });

        return $bookings;
    }
}

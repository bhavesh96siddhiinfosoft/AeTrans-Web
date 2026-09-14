<?php

namespace App\Http\Controllers\Booking;

use App\Http\Controllers\Controller;
use App\Services\Firebase\FirebaseSession;
use App\Services\Firebase\FirestoreDocuments;
use App\Services\Site\SiteSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The screen a booking ends on, for both services.
 *
 * Modelled on the app's: what has been booked, its reference, and how to pay for it.
 *
 * ── WHY THE BOOKING IS RE-READ ──────────────────────────────────────────────
 *
 * The draft is thrown away the moment the booking is written — leaving it in the session
 * would let a refresh send a second one — so this page cannot describe the trip from it.
 * It reads the document back instead, which also means a customer can return to the URL
 * later and still find their reference and the bank details.
 *
 * Read AS THE CUSTOMER, with their own id token, so the page keeps working on the day
 * the Firestore rules are tightened to "a customer may read their own bookings".
 *
 * A booking that cannot be read is not an error: the reference and the payment details
 * are still shown, because those are what the customer actually needs from this screen.
 */
class BookingDoneController extends Controller
{
    public function __invoke(
        Request $request,
        string $booking,
        FirebaseSession $firebase,
        FirestoreDocuments $documents,
        SiteSettings $site,
    ): View {
        $idToken = $firebase->idToken();
        $document = $idToken ? $documents->get('bookings/'.$booking, $idToken) : null;

        return view('booking.done', [
            'bookingId' => $booking,
            /*
             * The 8-character form the app shows and the panel's list column prints —
             * `row.id.slice(0, 8)` there, uppercased here as the app uppercases it. It is
             * DERIVED, not stored: there is no reference field on a booking, and an
             * operator searching for what the customer read out is searching this.
             */
            'reference' => strtoupper(substr($booking, 0, 8)),
            'booking' => $document,
            'bank' => $site->bankAccount(),
        ]);
    }
}

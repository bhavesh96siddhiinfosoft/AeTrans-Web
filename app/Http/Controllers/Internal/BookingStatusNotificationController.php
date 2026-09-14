<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\Firebase\Firestore;
use App\Services\Mail\CustomerMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Emails a customer that an operator has changed their booking's status.
 *
 * ── WHY THE PANEL HAS TO ASK US ─────────────────────────────────────────────
 *
 * The admin panel is a separate application, and every write it makes goes straight from
 * the operator's BROWSER to Firestore — its controllers only render views. So there is no
 * server-side moment in the panel where "the status changed" can be observed, and this
 * site cannot observe it either. The panel therefore tells us, and this is the door.
 *
 * The alternative was to give the panel its own copy of the templates. Two repositories
 * rendering the same four emails drift, and the one that drifts is always the one nobody
 * reads — so the templates stay here, where they are built and tested, and the panel
 * sends a booking id.
 *
 * ── WHAT PROTECTS IT ────────────────────────────────────────────────────────
 *
 * A shared secret in a header, compared in constant time. It is a server-to-server call
 * between two applications on the same host: the panel's browser never sees the secret,
 * it talks to the panel's own session-authenticated route and the PANEL makes this call.
 *
 * The secret is the whole of the authentication, so two things bound what a leak would
 * cost. The caller may not name a recipient — the address comes off the booking — so the
 * worst an attacker can do is send real customers a truthful email about their own
 * booking. And it is throttled, so they cannot do it in a loop.
 *
 * NOT `auth`: there is no user here. NOT CSRF: there is no browser, no session and no
 * cookie — see the exemption in bootstrap/app.php.
 */
class BookingStatusNotificationController extends Controller
{
    /** The header the panel puts the shared secret in. */
    public const HEADER = 'X-Aetrans-Signature';

    public function __invoke(Request $request, Firestore $firestore, CustomerMail $mail): JsonResponse
    {
        $secret = (string) config('services.internal.secret', '');

        /*
         * An UNCONFIGURED secret refuses everything rather than letting everything
         * through. The opposite default — no secret means no check — is how an endpoint
         * ends up open on the day somebody copies `.env.example` onto a server.
         */
        if ($secret === '' || ! hash_equals($secret, (string) $request->header(self::HEADER, ''))) {
            Log::warning('An internal notification was refused.', [
                'ip' => $request->ip(),
                'configured' => $secret !== '',
            ]);

            return response()->json(['error' => 'unauthorised'], 401);
        }

        $validated = $request->validate([
            'booking' => ['required', 'string', 'max:128'],
            /*
             * Optional. Given wins, because the caller knows what it just wrote and the
             * document may already have moved on again — an operator correcting a
             * mis-click within the same minute would otherwise send the wrong email.
             */
            'status' => ['nullable', 'string', 'max:64'],
        ]);

        $id = $validated['booking'];
        $booking = $firestore->document('bookings/'.$id);

        if (! $booking) {
            return response()->json(['error' => 'no such booking'], 404);
        }

        $status = (string) ($validated['status'] ?? '') ?: (string) ($booking['status'] ?? '');

        if ($status === '') {
            return response()->json(['error' => 'no status'], 422);
        }

        $to = (string) ($booking['customerEmail'] ?? '');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            /*
             * Not an error, and it must not read as one in the panel. An operator can
             * enter a booking by phone with no address at all, and there is nothing
             * wrong with that booking — the panel should stay quiet rather than showing
             * a failure for a customer who simply has no email.
             */
            return response()->json(['sent' => false, 'reason' => 'no customer email']);
        }

        $mail->statusChanged($id, $booking, $status);

        Log::info('Sending a booking status email.', ['booking' => $id, 'status' => $status]);

        return response()->json(['sent' => true, 'status' => $status]);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Firebase\Firestore;
use App\Services\Mail\CustomerMail;
use Illuminate\Console\Command;

/**
 * Emails a customer that their booking's status has changed.
 *
 * ── WHY A COMMAND AND NOT A TRIGGER ─────────────────────────────────────────
 *
 * The status is changed in the ADMIN PANEL, which is a separate application; this site
 * cannot observe it happening. The trigger therefore has to come from outside, and this
 * command is the seam it comes through — one entry point, so whatever ends up calling it
 * (the panel, a scheduled sweep, an operator by hand) sends the same email.
 *
 * The panel-side call is being built separately, 2026-08-31. Until it exists this is
 * also how an operator sends one by hand after changing a status:
 *
 *     php artisan booking:status-email 7f3aC9x2QpLm confirmed
 *
 * ── NOTHING HERE REMEMBERS WHAT IT HAS SENT ─────────────────────────────────
 *
 * Deliberately. Calling it twice sends twice, because a command that silently declined
 * to send would be indistinguishable from a broken mail server at exactly the moment
 * somebody is testing it. Whatever calls this is what must decide the change is real —
 * and if that ends up being a polling sweep, the record of what has been sent belongs
 * with the sweep, not here.
 *
 * The status may be given, or read from the document. Given wins: the caller knows what
 * it just changed it to, whereas the document may already have moved on again.
 */
class SendBookingStatusEmail extends Command
{
    protected $signature = 'booking:status-email
                            {booking : the Firestore booking document id}
                            {status? : pending, confirmed, completed or cancelled — read from the booking when omitted}';

    protected $description = "Email a customer that their booking's status has changed";

    public function handle(Firestore $firestore, CustomerMail $mail): int
    {
        $id = (string) $this->argument('booking');

        /*
         * Read with the SERVICE ACCOUNT, not a customer token: there is no customer
         * signed in when an operator changes a status, and this is server-to-server.
         */
        $booking = $firestore->document('bookings/'.$id);

        if (! $booking) {
            $this->error("No booking {$id}.");

            return self::FAILURE;
        }

        $status = (string) ($this->argument('status') ?: ($booking['status'] ?? ''));

        if ($status === '') {
            $this->error("Booking {$id} has no status, and none was given.");

            return self::FAILURE;
        }

        $to = (string) ($booking['customerEmail'] ?? '');

        if ($to === '') {
            // Not a failure. An operator can enter a booking by phone with no address,
            // and there is nothing wrong with that booking.
            $this->warn("Booking {$id} carries no customer email; nothing sent.");

            return self::SUCCESS;
        }

        $mail->statusChanged($id, $booking, $status);

        $this->info("Queued the {$status} email for {$to} (booking {$id}).");

        return self::SUCCESS;
    }
}

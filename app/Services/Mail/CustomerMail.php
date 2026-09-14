<?php

namespace App\Services\Mail;

use App\Mail\AdminBookingMail;
use App\Mail\AdminNewCustomerMail;
use App\Mail\BookingReceivedMail;
use App\Mail\BookingStatusMail;
use App\Mail\WelcomeMail;
use App\Services\Site\SiteSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The one place this site decides to send a customer an email.
 *
 * ── A FAILED EMAIL MUST NEVER FAIL THE THING IT IS ABOUT ────────────────────
 *
 * Every send here is wrapped and logged rather than allowed to throw. An SMTP host that
 * is refusing connections must not lose a booking that has already been written to
 * Firestore, or reject a registration for an account Firebase has already created — the
 * customer would be left with a real account they were told did not exist. The email is
 * a courtesy on top of the transaction; the transaction is the product.
 *
 * The cost is that a broken mail server is quiet. That is what the log line is for, and
 * it names the address and the reason so a search for one customer's email finds it.
 *
 * ── SENT AFTER THE RESPONSE, NOT DURING IT ──────────────────────────────────
 *
 * `defer()` runs the send once the response has been flushed to the browser. An SMTP
 * handshake is a second or two on a good day, and putting that in front of the
 * confirmation screen would make the slowest moment of the booking the moment AFTER the
 * customer has already paid attention.
 *
 * A queue would be the textbook answer, and `QUEUE_CONNECTION` is `database` — but
 * nothing on this host runs a worker, so a queued email is an email that sits in a table
 * for ever. `defer()` needs no worker and no cron, which is the difference between mail
 * that sends and mail that is configured to.
 */
class CustomerMail
{
    /** Somebody has just registered. */
    public function welcome(string $email, string $name): void
    {
        $this->send($email, new WelcomeMail($name, $email), 'welcome');

        // And the office is told, in an email written for them rather than a copy of
        // the customer's. See `AdminNewCustomerMail`.
        $this->toAdmin(new AdminNewCustomerMail($name, $email), 'admin-new-customer');
    }

    /**
     * A booking has been written. This is the invoice.
     *
     * @param  array<string, mixed>  $booking
     */
    public function bookingReceived(string $bookingId, array $booking): void
    {
        $to = (string) ($booking['customerEmail'] ?? '');

        $this->send($to, new BookingReceivedMail($bookingId, $booking), 'booking-received', $bookingId);

        /*
         * The office copy goes out whether or not the customer's did. A booking with no
         * email address — or one that bounces — is exactly the booking an operator most
         * needs to be told about, because nobody else has been.
         */
        $this->toAdmin(new AdminBookingMail($bookingId, $booking), 'admin-booking', $bookingId);
    }

    /**
     * An operator has changed a booking's status.
     *
     * @param  array<string, mixed>  $booking
     */
    public function statusChanged(string $bookingId, array $booking, string $status): void
    {
        $to = (string) ($booking['customerEmail'] ?? '');

        $this->send($to, new BookingStatusMail($bookingId, $booking, $status), 'booking-status', $bookingId);
    }

    /**
     * The office inbox, or null when there is nowhere to send to.
     *
     * From GLOBAL SETTINGS first, so the client can change where notifications land from
     * the panel without a deploy — it is the same address the site's own footer prints.
     * `MAIL_ADMIN_ADDRESS` overrides it for the case where the public contact address and
     * the working inbox are deliberately different, and the sending address is the last
     * resort because a business that sends from an address it does not read is rarer than
     * one that has not filled the setting in.
     */
    private function adminAddress(): ?string
    {
        $candidates = [
            (string) config('mail.admin_address', ''),
            (string) (app(SiteSettings::class)->email() ?? ''),
            (string) config('mail.from.address', ''),
        ];

        foreach ($candidates as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                return $address;
            }
        }

        return null;
    }

    /**
     * Sends one email to the office.
     *
     * IN THE SITE'S DEFAULT LANGUAGE, not the customer's. An operator should not receive
     * a notification in Arabic because that is what the customer was reading in — they
     * read every one of these, and they read them in one language.
     */
    private function toAdmin(Mailable $mail, string $kind, ?string $subject = null): void
    {
        $to = $this->adminAddress();

        if ($to === null) {
            return;
        }

        $this->send($to, $mail->locale((string) config('app.locale', 'en')), $kind, $subject);
    }

    /**
     * Hands one email to the mailer, after the response and without ever throwing.
     *
     * An address that is empty or malformed is dropped silently — a booking may carry no
     * email at all if an operator entered it by phone, and that is not a fault worth an
     * error-level log line on every one of them.
     */
    private function send(string $to, Mailable $mail, string $kind, ?string $subject = null): void
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        defer(function () use ($to, $mail, $kind, $subject) {
            try {
                Mail::to($to)->send($mail);
            } catch (\Throwable $e) {
                Log::error('Could not send a customer email.', [
                    'kind' => $kind,
                    'to' => $to,
                    'booking' => $subject,
                    'reason' => $e->getMessage(),
                ]);
            }
        });
    }
}

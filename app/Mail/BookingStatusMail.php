<?php

namespace App\Mail;

use App\Services\Site\SiteServices;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Sent when an operator changes a booking's status.
 *
 * ── THE STATUSES, AND WHY EACH READS DIFFERENTLY ────────────────────────────
 *
 * The panel's own vocabulary: `pending`, `confirmed`, `completed`, `cancelled`. They are
 * not four shades of the same message — they are four different things to do next, and
 * the email says which:
 *
 *   confirmed  The trip is on. For a shuttle this is the moment the seat is ACTUALLY
 *              held; until now it was not, which the booking email said in as many
 *              words. This is the email that makes that promise, so it is the only one
 *              allowed to use the word "confirmed".
 *   completed  The trip has happened. A receipt, and the end of the conversation.
 *   cancelled  It is not happening. Money may be owed and this site cannot say — a
 *              refund is the operator's decision — so it sends the reader to a person
 *              rather than inventing a policy.
 *   pending    Back to waiting. An operator can do this by accident as easily as on
 *              purpose; it is stated plainly and nothing is promised.
 *
 * An unknown status is treated as `pending` rather than refused: the panel can add one,
 * and a customer receiving a slightly generic email is better than a customer receiving
 * nothing because a string did not match.
 */
class BookingStatusMail extends BrandedMail
{
    use SerializesModels;

    /** What the panel writes, and how each one reads. */
    private const TONES = [
        'confirmed' => 'good',
        'completed' => 'done',
        'cancelled' => 'bad',
        'pending' => 'wait',
    ];

    /**
     * @param  array<string, mixed>  $booking  the Firestore document
     */
    public function __construct(
        public readonly string $bookingId,
        public readonly array $booking,
        public readonly string $status,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('lang.email_status_subject_'.$this->key(), ['reference' => $this->reference()]),
        );
    }

    public function content(): Content
    {
        $key = $this->key();

        return new Content(
            view: 'emails.booking-status',
            with: array_merge($this->brand(), [
                'booking' => $this->booking,
                'reference' => $this->reference(),
                'status' => $key,
                'tone' => self::TONES[$key],
                'statusLabel' => __('lang.status_'.$key),
                'heading' => __('lang.email_status_heading_'.$key),
                'lead' => __('lang.email_status_lead_'.$key, [
                    'name' => $this->firstName((string) ($this->booking['customerName'] ?? '')),
                ]),
                'isCharter' => ($this->booking['bookingType'] ?? 'charter') === 'charter',
                'rows' => $this->rows(),
                'whatsapp' => $this->whatsappLink($this->reference()),
                'preview' => __('lang.email_status_preview_'.$key, ['reference' => $this->reference()]),
            ]),
        );
    }

    /** The status, normalised to one this email knows how to write. */
    private function key(): string
    {
        $status = strtolower(trim($this->status));

        return isset(self::TONES[$status]) ? $status : 'pending';
    }

    private function reference(): string
    {
        return strtoupper(substr($this->bookingId, 0, 8));
    }

    /**
     * Just enough of the trip to tell one booking from another.
     *
     * Short on purpose. This email is about a CHANGE, and repeating the whole invoice
     * buries the one line the reader opened it for — the full details are a tap away in
     * My bookings, which is where the button goes.
     *
     * @return array<string, string>
     */
    private function rows(): array
    {
        $b = $this->booking;
        $isCharter = ($b['bookingType'] ?? 'charter') === 'charter';

        $when = trim(
            $this->date($b['travelDate'] ?? null)
            .(! empty($b['pickupTime']) ? ' · '.$b['pickupTime'] : '')
        );

        return array_filter([
            // The admin's own name for the service, same as every other screen and
            // email. Resolved here rather than injected, for the reason BrandedMail
            // gives: a Mailable is serialised when queued.
            __('lang.service') => app(SiteServices::class)->label($isCharter),
            __('lang.date_and_time') => $when,
            __('lang.route') => $isCharter
                ? (string) ($b['pickupAddress'] ?? '')
                : trim(((string) ($b['airportName'] ?? '')).' · '.((string) ($b['dropPoint'] ?? '')), ' ·'),
            __('lang.passengers') => ($b['passengers'] ?? null) ? (string) $b['passengers'] : '',
        ]);
    }

    private function date(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        try {
            return Carbon::parse(is_array($value) ? ($value['seconds'] ?? '') : $value)->format('d/m/Y');
        } catch (\Throwable) {
            return '';
        }
    }
}

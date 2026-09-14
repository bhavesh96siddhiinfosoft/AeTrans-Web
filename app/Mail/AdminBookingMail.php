<?php

namespace App\Mail;

use App\Services\Site\Currency;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Tells the operator a booking has come in and needs a decision.
 *
 * ── WHY THIS IS NOT THE CUSTOMER'S INVOICE WITH A SECOND RECIPIENT ──────────
 *
 * The two emails are about the same booking and are for opposite jobs. The customer's
 * says "thank you, here is what you owe and how to pay it"; this one says "somebody is
 * waiting on you, here is what they want and how to ring them". Bcc-ing the invoice
 * would send the operator the bank account they already own, address them as the
 * customer, and bury the one fact that matters — that nothing is confirmed until they
 * act.
 *
 * On a shuttle that is not a nicety. The seat is NOT held while the booking is pending,
 * so an unread notification is a seat that may be sold twice.
 *
 * The subject carries the reference and the total, because a phone lock screen showing
 * "New charter — JBAXO243 — Rp 3,555,300" is often the whole of what an operator needs
 * to decide whether to open it now or after lunch.
 */
class AdminBookingMail extends BrandedMail
{
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $booking  the Firestore document as written
     */
    public function __construct(
        public readonly string $bookingId,
        public readonly array $booking,
    ) {}

    public function envelope(): Envelope
    {
        $money = app(Currency::class);
        $isCharter = ($this->booking['bookingType'] ?? 'charter') === 'charter';
        $customer = (string) ($this->booking['customerEmail'] ?? '');

        $replacements = [
            'reference' => $this->reference(),
            // What the operator will actually collect — see the customer's invoice.
            'total' => $money->format(
                (float) ($this->booking['payableAmount'] ?? $this->booking['cost'] ?? 0),
                (string) ($this->booking['currencyCode'] ?? '') ?: null,
            ),
        ];

        /*
         * Both keys written out in full rather than picked with a ternary inside `__()`.
         * The translation coverage scan reads this file as TEXT — a key it cannot see is
         * a key it reports as dead, and the fix for that should be code the scanner can
         * read, not a wider exemption in the scan.
         */
        $envelope = new Envelope(
            subject: $isCharter
                ? __('lang.email_admin_booking_subject_charter', $replacements)
                : __('lang.email_admin_booking_subject_shuttle', $replacements),
        );

        /*
         * Reply goes to the CUSTOMER, so an operator can answer a booking without
         * copying an address out of the body. Only when there is one — a booking may
         * carry no email at all, and a reply-to pointing at the office would have the
         * operator emailing themselves.
         */
        return filter_var($customer, FILTER_VALIDATE_EMAIL)
            ? $envelope->replyTo([new Address($customer, (string) ($this->booking['customerName'] ?? '') ?: $customer)])
            : $envelope;
    }

    public function content(): Content
    {
        $money = app(Currency::class);
        $isCharter = ($this->booking['bookingType'] ?? 'charter') === 'charter';
        $currency = (string) ($this->booking['currencyCode'] ?? '') ?: null;

        return new Content(
            view: 'emails.admin-booking',
            with: array_merge($this->brand(), [
                'booking' => $this->booking,
                'reference' => $this->reference(),
                'isCharter' => $isCharter,
                'total' => $money->format(
                    (float) ($this->booking['payableAmount'] ?? $this->booking['cost'] ?? 0),
                    $currency,
                ),
                // Shown only when there was one, and the operator needs to see WHY the
                // total is lower than the fare times the seats.
                'discountRow' => $this->discountRow($money, $currency),
                'customerRows' => $this->customerRows(),
                'rows' => $this->rows($isCharter),
                'phone' => $this->customerWhatsApp(),
                'customerEmail' => (string) ($this->booking['customerEmail'] ?? ''),
                'preview' => __('lang.email_admin_booking_preview', [
                    'name' => (string) ($this->booking['customerName'] ?? ''),
                    'reference' => $this->reference(),
                ]),
            ]),
        );
    }

    private function reference(): string
    {
        return strtoupper(substr($this->bookingId, 0, 8));
    }

    /**
     * The discount, so the operator can see why the total is not the full price.
     *
     * @return array{label: string, detail: string, amount: string}|null
     */
    private function discountRow(Currency $money, ?string $currency): ?array
    {
        $discount = (float) ($this->booking['discountAmount'] ?? 0);

        if ($discount <= 0) {
            return null;
        }

        return [
            'label' => __('lang.discount'),
            'detail' => (string) ($this->booking['couponCode'] ?? ''),
            'amount' => '-'.$money->format($discount, $currency),
        ];
    }

    /**
     * Who to ring, and when they placed the order.
     *
     * The phone number comes FIRST. Confirming a booking on this business is a phone or
     * WhatsApp conversation, so it is the field the operator is actually looking for.
     *
     * @return array<string, string>
     */
    private function customerRows(): array
    {
        return array_filter([
            __('lang.name') => (string) ($this->booking['customerName'] ?? ''),
            __('lang.phone_number') => (string) ($this->booking['customerPhone'] ?? ''),
            __('lang.email') => (string) ($this->booking['customerEmail'] ?? ''),
            __('lang.booked_at') => Carbon::now()->format('d/m/Y · H:i'),
        ]);
    }

    /**
     * A WhatsApp link to the CUSTOMER, with the reference already in the message.
     *
     * The opposite direction from `BrandedMail::whatsappLink()`, which points a customer
     * at the office. Null when the booking carries no usable number — an operator who
     * taps a dead `wa.me` link learns nothing about why.
     */
    private function customerWhatsApp(): ?string
    {
        $digits = preg_replace('/[^0-9+]/', '', (string) ($this->booking['customerPhone'] ?? ''));
        $digits = ltrim((string) $digits, '+');

        // Below this it is not a number, it is a typo or a placeholder.
        if (strlen($digits) < 8) {
            return null;
        }

        return 'https://wa.me/'.$digits.'?text='.urlencode(
            __('lang.whatsapp_operator_message', ['reference' => $this->reference()])
        );
    }

    /**
     * The trip itself — the same shape the customer's invoice shows.
     *
     * Deliberately the same fields: an operator reading this and a customer reading
     * theirs must be looking at the same booking, or a phone call about it goes wrong.
     *
     * @return array<string, string>
     */
    private function rows(bool $isCharter): array
    {
        $b = $this->booking;

        $when = trim(
            $this->date($b['travelDate'] ?? null)
            .(! empty($b['pickupTime']) ? ' · '.$b['pickupTime'] : '')
        );

        if ($isCharter) {
            return array_filter([
                __('lang.vehicle') => (string) ($b['vehicleTypeName'] ?? ''),
                __('lang.passengers') => ($b['passengers'] ?? null) ? (string) $b['passengers'] : '',
                // One per line, as on the shuttle: a list to read down, not a sentence.
                __('lang.passenger_names') => implode("
", array_filter((array) ($b['passengerNames'] ?? []))),
                __('lang.pickup_address') => (string) ($b['pickupAddress'] ?? ''),
                __('lang.drop_off_address') => implode("\n", array_filter((array) ($b['dropoffAddresses'] ?? []))),
                __('lang.date_and_time') => $when,
                __('lang.return_date') => $this->date($b['returnDate'] ?? null),
                __('lang.notes') => (string) ($b['notes'] ?? ''),
            ]);
        }

        $pickedUpAtTheStop = ($b['direction'] ?? '') === 'to_airport';

        return array_filter([
            __('lang.airport') => (string) ($b['airportName'] ?? ''),
            // The panel has no terminal row yet, so for now this email is where an
            // operator actually reads it. See BookingReservation.
            __('lang.terminal') => (string) ($b['terminal'] ?? ''),
            __('lang.city') => (string) ($b['cityGroupName'] ?? ''),
            // Drop point leaving the airport, PICKUP POINT going to it — the customer
            // is set down at one and collected at the other.
            $pickedUpAtTheStop ? __('lang.pickup_point') : __('lang.drop_point') => (string) ($b['dropPoint'] ?? ''),
            __('lang.date_and_time') => $when,
            __('lang.passengers') => ($b['passengers'] ?? null) ? (string) $b['passengers'] : '',
            // One per line: the driver reads this as a list to call people onto the bus,
            // not as a sentence.
            __('lang.passenger_names') => implode("
", array_filter((array) ($b['passengerNames'] ?? []))),
            __('lang.luggage') => ($b['luggage'] ?? 0) > 0 ? (string) $b['luggage'] : '',
            __('lang.flight_details') => (string) ($b['flightDetails'] ?? ''),
            __('lang.notes') => (string) ($b['notes'] ?? ''),
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

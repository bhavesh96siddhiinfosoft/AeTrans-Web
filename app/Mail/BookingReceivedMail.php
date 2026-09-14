<?php

namespace App\Mail;

use App\Services\Site\Currency;
use App\Services\Site\SiteSettings;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The invoice for a booking the customer has just made.
 *
 * There is no payment gateway. The customer reads this, transfers the money by hand and
 * sends the proof on WhatsApp — so this email IS the invoice, and the figures in it are
 * what the business will be held to.
 *
 * ── THE FIGURES COME FROM THE BOOKING, NOT FROM A RECALCULATION ─────────────
 *
 * Every line below is read off the document that was written. Nothing is worked out
 * again from rates, because a rate the admin edits an hour later would silently change
 * what this email says was charged — and the email is sent once, from a copy the
 * customer keeps. `cost` is the total that was agreed; the lines explain it and must
 * add up to it, which is why the charter breakdown is only printed when the document
 * actually carries the parts.
 */
class BookingReceivedMail extends BrandedMail
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
        return new Envelope(
            subject: __('lang.email_booking_subject', ['reference' => $this->reference()]),
        );
    }

    public function content(): Content
    {
        $site = app(SiteSettings::class);
        $money = app(Currency::class);
        $isCharter = ($this->booking['bookingType'] ?? 'charter') === 'charter';
        $currency = (string) ($this->booking['currencyCode'] ?? '') ?: null;

        return new Content(
            view: 'emails.booking-received',
            with: array_merge($this->brand(), [
                'booking' => $this->booking,
                'reference' => $this->reference(),
                'isCharter' => $isCharter,
                'firstName' => $this->firstName((string) ($this->booking['customerName'] ?? '')),
                'rows' => $this->rows($isCharter),
                'lines' => $this->lines($money, $currency, $isCharter),
                /*
                 * THE TOTAL IS WHAT THEY PAY, not `cost`.
                 *
                 * `cost` is the full price — the client's decision, 2026-09-02 — and a
                 * discount sits beside it. This email is the invoice the customer
                 * transfers money against, so the figure at the bottom of it has to be
                 * the figure they transfer.
                 *
                 * `payableAmount` is on bookings written since coupons existed; older
                 * ones fall back to `cost`, which for them is the same number.
                 */
                'total' => $money->format(
                    (float) ($this->booking['payableAmount'] ?? $this->booking['cost'] ?? 0),
                    $currency,
                ),
                'discountRow' => $this->discountRow($money, $currency),
                'bank' => $site->bankAccount(),
                'bankRows' => $this->bankRows($site->bankAccount()),
                'whatsapp' => $this->whatsappLink($this->reference()),
                'preview' => __('lang.email_booking_preview', ['reference' => $this->reference()]),
            ]),
        );
    }

    /**
     * The 8-character reference.
     *
     * DERIVED from the document id, not stored — there is no reference field on a
     * booking. This is what the app prints, what the panel's list column shows, and
     * therefore what an operator can search for when the customer reads it out.
     */
    private function reference(): string
    {
        return strtoupper(substr($this->bookingId, 0, 8));
    }

    /**
     * What was booked, as the customer would describe it.
     *
     * Empty values are dropped by the partial, so every possible field can be listed
     * here and the data decides what prints — a charter with no return date simply has
     * no return row rather than one saying "—".
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
                // Every stop, in order, on its own line: an itinerary with the middle
                // dropped is not the trip the customer booked.
                __('lang.drop_off_address') => implode("\n", array_filter((array) ($b['dropoffAddresses'] ?? []))),
                __('lang.date_and_time') => $when,
                __('lang.return_date') => $this->date($b['returnDate'] ?? null),
                __('lang.notes') => (string) ($b['notes'] ?? ''),
            ]);
        }

        $pickedUpAtTheStop = ($b['direction'] ?? '') === 'to_airport';

        return array_filter([
            __('lang.airport') => (string) ($b['airportName'] ?? ''),
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

    /**
     * The invoice lines, or an empty list when the document cannot explain its own total.
     *
     * An empty list prints the total on its own, which is honest. Inventing a breakdown
     * from rates read now would produce lines that do not add up to what was charged the
     * day the admin next edits a price.
     *
     * @return array<int, array{label: string, detail?: string, amount: string}>
     */
    private function lines(Currency $money, ?string $currency, bool $isCharter): array
    {
        $b = $this->booking;

        if (! $isCharter) {
            $fare = (float) ($b['farePerSeat'] ?? 0);
            $seats = (int) ($b['passengers'] ?? 0);

            if ($fare <= 0 || $seats <= 0) {
                return [];
            }

            return [[
                'label' => __('lang.seat_fare'),
                'detail' => __('lang.count_times_fare', ['count' => $seats, 'fare' => $money->format($fare, $currency)]),
                'amount' => $money->format($fare * $seats, $currency),
            ]];
        }

        $days = (int) ($b['billableDays'] ?? 0);
        $dailyRate = (float) ($b['dailyRate'] ?? 0);
        $km = (float) ($b['distanceKm'] ?? 0);
        $perKm = (float) ($b['perKmRate'] ?? 0);

        if ($days <= 0 || $dailyRate <= 0) {
            return [];
        }

        $lines = [[
            'label' => __('lang.vehicle_hire'),
            'detail' => __('lang.days_times_rate', [
                'days' => $days,
                'rate' => $money->format($dailyRate, $currency),
            ]),
            'amount' => $money->format($days * $dailyRate, $currency),
        ]];

        if ($km > 0 && $perKm > 0) {
            $lines[] = [
                'label' => __('lang.distance'),
                'detail' => __('lang.km_times_rate', [
                    // Trailing zero trimmed: "200 km", not "200.0 km".
                    'km' => rtrim(rtrim(number_format($km, 1, '.', ','), '0'), '.'),
                    'rate' => $money->format($perKm, $currency),
                ]),
                'amount' => $money->format($km * $perKm, $currency),
            ];
        }

        return $lines;
    }

    /**
     * The discount line, or null when the booking carried no code.
     *
     * Its own row under the itemised lines rather than one of them: the lines add up to
     * the full price, and this is what comes off that total. Rolling it into the list
     * would leave the arithmetic looking wrong.
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
     * The bank account, labelled.
     *
     * @param  array<string, string>  $bank
     * @return array<string, string>
     */
    private function bankRows(array $bank): array
    {
        $labels = [
            'bankName' => __('lang.bank'),
            'holderName' => __('lang.account_holder'),
            'accountNumber' => __('lang.account_number'),
            'bankCode' => __('lang.bank_code'),
            'branchName' => __('lang.branch'),
            'swiftCode' => __('lang.swift'),
        ];

        $rows = [];

        foreach ($labels as $field => $label) {
            if (! empty($bank[$field])) {
                $rows[$label] = $bank[$field];
            }
        }

        return $rows;
    }

    /**
     * A date the way a customer reads one, from whatever Firestore handed back.
     *
     * The field is a timestamp on a booking this site wrote and can be a plain string on
     * one entered elsewhere, so both are accepted — and anything unparseable prints
     * nothing rather than an exception inside an email nobody is watching.
     */
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

<?php

namespace App\Console\Commands;

use App\Mail\AdminBookingMail;
use App\Mail\AdminNewCustomerMail;
use App\Mail\BookingReceivedMail;
use App\Mail\BookingStatusMail;
use App\Mail\WelcomeMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/**
 * Renders every email to an HTML file so a person can look at it.
 *
 * Transactional email is the part of a site nobody sees until a customer does. It cannot
 * be checked by refreshing a page, the design is the one place on this project where
 * inline styles and table layout are unavoidable, and a broken one is discovered by the
 * person it was sent to. So: render them all, open them, look.
 *
 *     php artisan email:preview
 *     php artisan email:preview --lang=id
 *     php artisan email:preview --to=you@example.com
 *
 * `--to` SENDS them all to one address, which is the only honest way to check them: a
 * browser is far more forgiving than any mail client. Gmail strips the `<body>` tag,
 * Outlook renders through Word, and both decide for themselves what a `<style>` block is
 * worth — none of which a local file can show you.
 *
 * Without it, sends nothing.
 *
 * The figures are made up but shaped exactly like real documents — the
 * charter one carries the full `billableDays` / `distanceKm` / rate breakdown, because
 * an invoice that is only ever previewed with a round number hides the day the total
 * stops matching its own lines.
 */
class PreviewEmails extends Command
{
    protected $signature = 'email:preview
                            {--lang=en : the language to render in}
                            {--to= : send them all to this address instead of only writing files}';

    protected $description = 'Render every customer email to storage/app/email-preview for inspection';

    public function handle(): int
    {
        $locale = (string) $this->option('lang');
        app()->setLocale($locale);

        $charter = [
            'bookingType' => 'charter',
            'customerName' => 'Budi Santoso',
            'customerEmail' => 'budi@example.com',
            'customerPhone' => '+62 812 3456 7890',
            'vehicleTypeName' => 'Hiace Commuter',
            'passengers' => 4,
            'passengerNames' => ['Budi Santoso', 'Sari Dewi', 'Agus Wijaya', 'Rina Putri'],
            'pickupAddress' => 'Juanda International Airport, Terminal 1, Sidoarjo',
            'dropoffAddresses' => ['Hotel Majapahit, Surabaya', 'Tunjungan Plaza, Surabaya'],
            'travelDate' => '2026-09-14',
            'returnDate' => '2026-09-16',
            'pickupTime' => '08:30',
            'notes' => 'Two large suitcases. Please call on arrival.',
            'billableDays' => 3,
            'dailyRate' => 1000000,
            'distanceKm' => 185.1,
            'perKmRate' => 3000,
            'cost' => 3555300,
            'discountAmount' => 10000,
            'payableAmount' => 3545300,
            'couponCode' => '01AETRANS',
            'currencyCode' => 'IDR',
        ];

        $shuttle = [
            'bookingType' => 'shuttle',
            'customerName' => 'Sari Dewi',
            'customerEmail' => 'sari@example.com',
            'customerPhone' => '+62 813 9876 5432',
            'airportName' => 'Juanda Surabaya Airport',
            'cityGroupName' => 'Ngawi City',
            'dropPoint' => 'Ngawi (Pendopo Center)',
            'travelDate' => '2026-09-05',
            'pickupTime' => '15:00',
            'passengers' => 3,
            'passengerNames' => ['Sari Dewi', 'Andi Pratama', 'Nur Aini'],
            'luggage' => 2,
            'flightDetails' => 'GA 313, landing 14:05',
            'farePerSeat' => 250000,
            'cost' => 750000,
            'discountAmount' => 10000,
            'payableAmount' => 740000,
            'couponCode' => '01AETRANS',
            'currencyCode' => 'IDR',
        ];

        $emails = [
            'welcome' => new WelcomeMail('Budi Santoso', 'budi@example.com'),
            'invoice-charter' => new BookingReceivedMail('jBaXo2436zF8H1BPHJ5D', $charter),
            'invoice-shuttle' => new BookingReceivedMail('FGGcnAnqKrTJbpIYeLxy', $shuttle),
            'status-confirmed' => new BookingStatusMail('jBaXo2436zF8H1BPHJ5D', $charter, 'confirmed'),
            'status-completed' => new BookingStatusMail('jBaXo2436zF8H1BPHJ5D', $charter, 'completed'),
            'status-cancelled' => new BookingStatusMail('FGGcnAnqKrTJbpIYeLxy', $shuttle, 'cancelled'),
            'status-pending' => new BookingStatusMail('FGGcnAnqKrTJbpIYeLxy', $shuttle, 'pending'),

            /*
             * The office copies. Included here because they are the ones nobody thinks
             * to look at — they go to an inbox the developer never opens, and the client
             * reads every single one.
             */
            'admin-new-customer' => new AdminNewCustomerMail('Budi Santoso', 'budi@example.com'),
            'admin-booking-charter' => new AdminBookingMail('jBaXo2436zF8H1BPHJ5D', $charter),
            'admin-booking-shuttle' => new AdminBookingMail('FGGcnAnqKrTJbpIYeLxy', $shuttle),
        ];

        $directory = storage_path('app/email-preview');
        File::ensureDirectoryExists($directory);

        $to = (string) ($this->option('to') ?? '');

        if ($to !== '' && ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("{$to} is not an email address.");

            return self::FAILURE;
        }

        foreach ($emails as $name => $mail) {
            $file = $directory.DIRECTORY_SEPARATOR.$name.'.'.$locale.'.html';

            File::put($file, $mail->render());

            $this->line('  '.$file);
        }

        $this->info(count($emails).' emails rendered in '.$locale.'.');

        if ($to === '') {
            return self::SUCCESS;
        }

        $this->newLine();

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is `log`: these will be written to the log, not sent.');
        }

        $failed = 0;

        foreach ($emails as $name => $mail) {
            try {
                /*
                 * `sendNow`, not `send`: everything the site sends in earnest goes
                 * through `CustomerMail`, which defers and swallows failures so a
                 * customer never waits for SMTP and a booking is never lost to one. Both
                 * would hide exactly what this option exists to show, so here the send is
                 * synchronous and the error is the output.
                 */
                Mail::to($to)->sendNow($mail);

                $this->line('  sent  '.$name);
            } catch (\Throwable $e) {
                $failed++;

                $this->error('  failed  '.$name.'  -  '.$e->getMessage());
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error($failed.' of '.count($emails).' could not be sent. Run `php artisan mail:test` for what to check.');

            return self::FAILURE;
        }

        $this->info('All '.count($emails).' sent to '.$to.'. They arrive as separate emails.');

        return self::SUCCESS;
    }
}

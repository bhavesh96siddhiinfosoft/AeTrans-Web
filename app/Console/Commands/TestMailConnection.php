<?php

namespace App\Console\Commands;

use App\Mail\WelcomeMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends one real email, to prove the SMTP settings work.
 *
 *     php artisan mail:test you@example.com
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────────
 *
 * `MAIL_MAILER` was `log` in this repo and in the panel's from the day both were set
 * up, so no email has ever left either application and the Hostinger credentials in
 * `.env` have never been exercised. Configured is not the same as working: the host,
 * the port, the password and whether the provider will relay for this sender are four
 * separate ways to fail, and all four look identical from the outside — nothing arrives.
 *
 * This sends SYNCHRONOUSLY, unlike everything else the site sends. `CustomerMail` uses
 * `defer()` so a customer never waits for an SMTP handshake, and it swallows failures so
 * a booking is never lost to one — both of which would hide exactly what this command
 * exists to show. Here the error is the output.
 */
class TestMailConnection extends Command
{
    protected $signature = 'mail:test {to : the address to send to}';

    protected $description = 'Send one real email to prove the SMTP settings work';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("{$to} is not an email address.");

            return self::FAILURE;
        }

        $this->line('  mailer  '.config('mail.default'));
        $this->line('  host    '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('  from    '.config('mail.from.address'));
        $this->newLine();

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is `log`: this will be written to storage/logs/laravel.log, not sent.');
        }

        try {
            Mail::to($to)->sendNow(new WelcomeMail('Test', $to));
        } catch (\Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            /*
             * The two that actually happen with Hostinger, named rather than left to be
             * searched for. Both look like "it just does not work" from the outside.
             */
            $this->newLine();
            $this->line('  535 / authentication  the password in MAIL_PASSWORD is wrong, or the');
            $this->line('                        mailbox does not exist at the provider.');
            $this->line('  connection / timeout  try MAIL_PORT=465 with MAIL_SCHEME=smtps, which is');
            $this->line('                        what Hostinger documents; 587 needs STARTTLS and some');
            $this->line('                        networks block it outright.');

            return self::FAILURE;
        }

        $this->info("Sent to {$to}. If it does not arrive, check the spam folder and the sending domain's SPF record.");

        return self::SUCCESS;
    }
}

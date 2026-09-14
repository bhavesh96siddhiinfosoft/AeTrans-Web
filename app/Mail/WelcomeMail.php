<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once, when somebody registers.
 *
 * NOT the verification email. Firebase sends that from its own console template, and
 * this must not resemble it — two emails arriving together, both asking to be clicked,
 * is how a real verification link gets reported as phishing.
 */
class WelcomeMail extends BrandedMail
{
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('lang.email_welcome_subject', ['site' => app(\App\Services\Site\SiteSettings::class)->siteName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
            with: array_merge($this->brand(), [
                'firstName' => $this->firstName($this->name),
                /*
                 * The inbox preview line. Without one the client pulls in whatever text
                 * comes first, which here is the hidden preheader itself and then the
                 * alt text of the logo — "Aetrans Aetrans" in the inbox list.
                 */
                'preview' => __('lang.email_welcome_preview'),
            ]),
        );
    }
}

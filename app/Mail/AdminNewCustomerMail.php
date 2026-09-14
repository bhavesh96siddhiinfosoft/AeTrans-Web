<?php

namespace App\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Tells the operator that somebody has registered.
 *
 * Its own email rather than a copy of the customer's welcome. "Welcome, Budi" arriving
 * in the office inbox tells the operator nothing they can act on; who, when and how to
 * reach them does.
 */
class AdminNewCustomerMail extends BrandedMail
{
    use SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('lang.email_admin_customer_subject', ['name' => $this->name ?: $this->email]),
            /*
             * Reply goes to the CUSTOMER. An operator who wants to say hello, or who
             * spots a typo in the address, can simply press reply — rather than copying
             * an address out of the body of an email that came from their own domain.
             */
            replyTo: [new Address($this->email, $this->name ?: $this->email)],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-new-customer',
            with: array_merge($this->brand(), [
                'rows' => array_filter([
                    __('lang.name') => $this->name,
                    __('lang.email') => $this->email,
                    __('lang.registered') => Carbon::now()->format('d/m/Y · H:i'),
                ]),
                'preview' => __('lang.email_admin_customer_subject', ['name' => $this->name ?: $this->email]),
            ]),
        );
    }
}

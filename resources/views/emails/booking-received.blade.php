{{--
    The booking email — a receipt for a REQUEST, not a confirmation.

    The word matters and the client's own rules decide it: a charter holds its vehicle
    from the moment it is written, a shuttle seat does not and is only taken when an
    operator accepts the order. So this email says what the website's own last screen
    says — "we have your booking" — and never "confirmed". The status email that follows
    when an operator accepts it is where that word belongs.

    ── WHY IT IS AN ITEMISED INVOICE AND NOT JUST A TOTAL ──────────────────────

    There is no payment gateway. The customer reads this, transfers the money by hand,
    and sends the proof on WhatsApp — so this email IS the invoice, and it is what they
    will hold the business to. A total with no working shown is the thing people ring up
    about, and a charter total is genuinely not obvious: it is days × daily rate plus
    kilometres × per-km rate, where "days" may be more than the days travelled because
    of the distance rule.

    Expects: `$booking` (the Firestore document), `$reference`, `$bank`, `$lines`,
    `$rows`, `$isCharter`.
--}}
@extends('emails.layout')

@section('content')
@php
    $ink = '#1C1917';
    $muted = '#78716C';
    $line = '#E7E5E4';
    $accent = '#FF6B11';
    $soft = '#FAFAF9';
    $end = $languages->direction() === 'rtl' ? 'left' : 'right';
@endphp

<h1 class="h1" style="margin:0; font-family:Helvetica,Arial,sans-serif; font-size:26px; line-height:1.25; font-weight:700; color:{{ $ink }}; letter-spacing:-0.4px;">
    {{ __('lang.booking_received') }}
</h1>

<p style="margin:14px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:{{ $muted }};">
    {{ __('lang.email_booking_lead', ['name' => $firstName]) }}
</p>

{{-- The reference, given its own block. It is the first thing an operator asks for on
     the phone and the first thing the customer will hunt for in this email, so it is
     large, monospaced and nowhere near the small print. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;">
    <tr>
        <td style="background-color:{{ $soft }}; border:1px solid {{ $line }}; border-radius:10px; padding:16px 20px;">
            <div style="font-family:Helvetica,Arial,sans-serif; font-size:11.5px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:{{ $muted }};">
                {{ __('lang.booking_reference') }}
            </div>
            <div style="padding-top:6px; font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; font-size:21px; font-weight:700; letter-spacing:1.5px; color:{{ $ink }};">
                {{ $reference }}
            </div>
        </td>
    </tr>
</table>

{{-- What was booked. --}}
<h2 style="margin:32px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:12px; font-weight:700; letter-spacing:0.9px; text-transform:uppercase; color:{{ $muted }};">
    {{ $services->label($isCharter) }}
</h2>

@include('emails.partials.details', ['rows' => $rows])

{{-- The invoice. `$lines` is built in the mailable, because working a price out inside
     a template is where a template starts lying: the figures below have to be the ones
     that were charged, not the ones this file can recompute from what it happens to
     have been passed. --}}
<h2 style="margin:34px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:12px; font-weight:700; letter-spacing:0.9px; text-transform:uppercase; color:{{ $muted }};">
    {{ __('lang.email_invoice_heading') }}
</h2>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 0 0;">
    {{-- The lines EXPLAIN the total; they are not what produces it. An operator's custom
         quote carries no rates, so there is nothing honest to itemise — and the total is
         still the number the customer has to transfer. Wrapping the whole block in
         `@if ($lines)` hid it entirely on exactly those bookings. --}}
    @foreach ($lines as $item)
        <tr>
                <td class="stack-cell" valign="top"
                    style="padding:12px 12px 12px 0; border-top:1px solid #F0EEEC;
                           font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.5; color:{{ $ink }};">
                    {{ $item['label'] }}

                    @if (! empty($item['detail']))
                        <div style="padding-top:3px; font-size:12.5px; color:{{ $muted }};">{{ $item['detail'] }}</div>
                    @endif
                </td>
                <td class="stack-cell" valign="top" align="{{ $end }}" width="34%"
                    style="padding:12px 0 12px 0; border-top:1px solid #F0EEEC;
                           font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.5; color:{{ $ink }}; white-space:nowrap;">
                    {{ $item['amount'] }}
                </td>
            </tr>
        @endforeach

        {{-- The discount, under the lines and above the total. Its own row rather than
             one of the lines: the lines add up to the FULL price, and this is what comes
             off it — folding it in would leave the arithmetic looking wrong. --}}
        @if ($discountRow)
            <tr>
                <td class="stack-cell" valign="top"
                    style="padding:12px 12px 12px 0; border-top:1px solid #F0EEEC;
                           font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.5; color:{{ $ink }};">
                    {{ $discountRow['label'] }}

                    @if ($discountRow['detail'])
                        <div style="padding-top:3px; font-size:12.5px; color:{{ $muted }};">{{ $discountRow['detail'] }}</div>
                    @endif
                </td>
                <td class="stack-cell" valign="top" align="{{ $end }}" width="34%"
                    style="padding:12px 0 12px 0; border-top:1px solid #F0EEEC;
                           font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.5; color:#166534; white-space:nowrap;">
                    {{ $discountRow['amount'] }}
                </td>
            </tr>
        @endif

        <tr>
            <td class="stack-cell" valign="top"
                style="padding:14px 12px 0 0; border-top:2px solid {{ $ink }};
                       font-family:Helvetica,Arial,sans-serif; font-size:15px; font-weight:700; color:{{ $ink }};">
                {{ __('lang.total') }}
            </td>
            <td class="stack-cell" valign="top" align="{{ $end }}" width="34%"
                style="padding:14px 0 0 0; border-top:2px solid {{ $ink }};
                       font-family:Helvetica,Arial,sans-serif; font-size:19px; font-weight:700; color:{{ $ink }}; white-space:nowrap;">
                {{ $total }}
            </td>
        </tr>
</table>

{{-- How to pay. The account is the admin's and every field is conditional, so an
     account with no branch and one with a SWIFT code both print correctly — and an
     unconfigured account prints no empty card at all. --}}
@if ($bank)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:32px 0 0 0;">
        <tr>
            <td style="background-color:{{ $soft }}; border:1px solid {{ $line }}; border-radius:10px; padding:20px 22px;">
                <div style="font-family:Helvetica,Arial,sans-serif; font-size:15px; font-weight:700; color:{{ $ink }};">
                    {{ __('lang.pay_via_bank_transfer') }}
                </div>
                <div style="padding-top:6px; font-family:Helvetica,Arial,sans-serif; font-size:13.5px; line-height:1.6; color:{{ $muted }};">
                    {{ __('lang.bank_transfer_note') }}
                </div>

                @include('emails.partials.details', ['rows' => $bankRows])
            </td>
        </tr>
    </table>
@endif

@include('emails.partials.button', [
    'url' => route('bookings'),
    'label' => __('lang.my_bookings'),
])

@if ($whatsapp)
    @include('emails.partials.button', [
        'url' => $whatsapp,
        'label' => __('lang.chat_on_whatsapp'),
        'tone' => 'quiet',
    ])
@endif

{{-- The seat caveat, and only on a shuttle, because it is only true of a shuttle: a
     charter holds its vehicle from the moment this email was sent. Printing it on both
     would tell charter customers their van might go to somebody else. --}}
@unless ($isCharter)
    <p style="margin:26px 0 0 0; padding-top:22px; border-top:1px solid {{ $line }}; font-family:Helvetica,Arial,sans-serif; font-size:13.5px; line-height:1.65; color:{{ $muted }};">
        {{ __('lang.seat_held_on_acceptance') }}
    </p>
@endunless
@endsection

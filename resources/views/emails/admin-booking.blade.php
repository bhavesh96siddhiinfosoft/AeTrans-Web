{{--
    Tells the operator a booking has come in, and that it needs a decision.

    ── THIS ONE IS WORK, AND IT READS LIKE WORK ────────────────────────────────

    A booking arrives as `pending` and stays there until a person accepts it. On a
    shuttle that matters twice over: the seat is NOT held until they do, so a booking
    sitting unread is a seat that may be sold twice and a customer who has been told
    nothing. The band at the top says so — not decoration, the single most useful fact
    in the email.

    Everything else is the trip, plus who to ring. No bank details: the operator does not
    need them and does not want the account number in the office inbox.

    Expects: `$booking`, `$reference`, `$rows`, `$total`, `$isCharter`, `$phone`,
    `$customerEmail`.
--}}
@extends('emails.layout')

@section('content')
@php
    $ink = '#1C1917';
    $muted = '#78716C';
    $line = '#E7E5E4';
    $soft = '#FAFAF9';
    $end = $languages->direction() === 'rtl' ? 'left' : 'right';
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="background-color:#FFFBEB; border:1px solid #FDE68A; border-radius:10px; padding:14px 18px;
                   font-family:Helvetica,Arial,sans-serif; font-size:13px; font-weight:700; letter-spacing:0.7px; text-transform:uppercase; color:#92400E;">
            {{ __('lang.email_admin_action_needed') }}
        </td>
    </tr>
</table>

<h1 class="h1" style="margin:22px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:24px; line-height:1.25; font-weight:700; color:{{ $ink }}; letter-spacing:-0.4px;">
    {{ $isCharter ? __('lang.email_admin_booking_heading_charter') : __('lang.email_admin_booking_heading_shuttle') }}
</h1>

<p style="margin:12px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:{{ $muted }};">
    {{ $isCharter ? __('lang.email_admin_booking_lead_charter') : __('lang.email_admin_booking_lead_shuttle') }}
</p>

{{-- Reference and total side by side: the two things an operator reads first, one to
     find the booking in the panel and one to know what is at stake. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;">
    <tr>
        <td style="background-color:{{ $soft }}; border:1px solid {{ $line }}; border-radius:10px; padding:16px 20px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                    <td class="stack-cell" valign="top">
                        <div style="font-family:Helvetica,Arial,sans-serif; font-size:11.5px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:{{ $muted }};">
                            {{ __('lang.booking_reference') }}
                        </div>
                        <div style="padding-top:5px; font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; font-size:20px; font-weight:700; letter-spacing:1.4px; color:{{ $ink }};">
                            {{ $reference }}
                        </div>
                    </td>
                    <td class="stack-cell" valign="top" align="{{ $end }}">
                        <div style="font-family:Helvetica,Arial,sans-serif; font-size:11.5px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:{{ $muted }};">
                            {{ __('lang.total') }}
                        </div>
                        <div style="padding-top:5px; font-family:Helvetica,Arial,sans-serif; font-size:20px; font-weight:700; color:{{ $ink }}; white-space:nowrap;">
                            {{ $total }}
                        </div>

                        {{-- Why the total is lower than the fare times the seats. An
                             operator reconciling a transfer needs the code, not just a
                             number that does not match the rate card. --}}
                        @if ($discountRow)
                            <div style="padding-top:4px; font-family:Helvetica,Arial,sans-serif; font-size:12.5px; color:#166534;">
                                {{ $discountRow['label'] }} {{ $discountRow['detail'] }} {{ $discountRow['amount'] }}
                            </div>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<h2 style="margin:30px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:12px; font-weight:700; letter-spacing:0.9px; text-transform:uppercase; color:{{ $muted }};">
    {{ __('lang.email_admin_the_customer') }}
</h2>

@include('emails.partials.details', ['rows' => $customerRows])

<h2 style="margin:30px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:12px; font-weight:700; letter-spacing:0.9px; text-transform:uppercase; color:{{ $muted }};">
    {{ $services->label($isCharter) }}
</h2>

@include('emails.partials.details', ['rows' => $rows])

{{-- Straight to the customer on WhatsApp, with the reference already in the message.
     Confirming a booking IS a WhatsApp conversation on this business — there is no
     gateway and no automated confirmation — so the button is the actual next step. --}}
@if ($phone)
    @include('emails.partials.button', [
        'url' => $phone,
        'label' => __('lang.email_admin_message_customer'),
    ])
@endif

<p style="margin:26px 0 0 0; padding-top:22px; border-top:1px solid {{ $line }}; font-family:Helvetica,Arial,sans-serif; font-size:13.5px; line-height:1.65; color:{{ $muted }};">
    {{ __('lang.email_admin_reply_note') }}
</p>
@endsection

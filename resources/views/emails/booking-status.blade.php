{{--
    Sent when an operator changes a booking's status.

    ── THE STATUS IS THE WHOLE EMAIL ───────────────────────────────────────────

    Four of these mean four different things to the reader, and the difference is not
    decoration — it is what they do next:

      confirmed  the trip is on. A shuttle seat is now actually theirs.
      completed  the trip has happened; this is the receipt.
      cancelled  the trip is NOT happening, and they need to know whether money is
                 involved. This site cannot say — refunds are the operator's — so it
                 sends them to a person rather than inventing a policy.
      pending    back to waiting, which an operator can do by mistake as easily as on
                 purpose; it is stated plainly and left at that.

    The colour follows, but it never carries the meaning alone: the heading says it in
    words, because a coloured pill is invisible to a screen reader and to anyone whose
    client has stripped the styles.

    Expects: `$booking`, `$reference`, `$status`, `$tone`, `$rows`, `$isCharter`.
--}}
@extends('emails.layout')

@section('content')
@php
    $ink = '#1C1917';
    $muted = '#78716C';
    $line = '#E7E5E4';

    /*
     * Foreground and background per tone. Backgrounds are pale enough that the text
     * stays legible if a client inverts them, which several will.
     */
    $palette = [
        'good' => ['ink' => '#166534', 'bg' => '#F0FDF4', 'edge' => '#BBF7D0'],
        'bad' => ['ink' => '#991B1B', 'bg' => '#FEF2F2', 'edge' => '#FECACA'],
        'wait' => ['ink' => '#92400E', 'bg' => '#FFFBEB', 'edge' => '#FDE68A'],
        'done' => ['ink' => '#1E3A5F', 'bg' => '#EFF6FF', 'edge' => '#BFDBFE'],
    ];
    $colour = $palette[$tone] ?? $palette['wait'];
@endphp

{{-- The status, as a band across the top of the card. Said in words directly beneath,
     so the band is confirmation of the sentence rather than a substitute for it. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td style="background-color:{{ $colour['bg'] }}; border:1px solid {{ $colour['edge'] }}; border-radius:10px; padding:14px 18px;
                   font-family:Helvetica,Arial,sans-serif; font-size:13px; font-weight:700; letter-spacing:0.7px; text-transform:uppercase; color:{{ $colour['ink'] }};">
            {{ $statusLabel }}
        </td>
    </tr>
</table>

<h1 class="h1" style="margin:22px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:26px; line-height:1.25; font-weight:700; color:{{ $ink }}; letter-spacing:-0.4px;">
    {{ $heading }}
</h1>

<p style="margin:14px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:{{ $muted }};">
    {{ $lead }}
</p>

@include('emails.partials.details', ['rows' => $rows])

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;">
    <tr>
        <td style="background-color:#FAFAF9; border:1px solid {{ $line }}; border-radius:10px; padding:14px 20px;">
            <div style="font-family:Helvetica,Arial,sans-serif; font-size:11.5px; font-weight:700; letter-spacing:0.8px; text-transform:uppercase; color:{{ $muted }};">
                {{ __('lang.booking_reference') }}
            </div>
            <div style="padding-top:5px; font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace; font-size:19px; font-weight:700; letter-spacing:1.4px; color:{{ $ink }};">
                {{ $reference }}
            </div>
        </td>
    </tr>
</table>

{{-- A cancellation is the one status where the reader may be owed money, and this site
     genuinely does not know: refunds are the operator's decision. So it hands them a
     person instead of a policy it would be inventing. --}}
@if ($tone === 'bad' && $whatsapp)
    @include('emails.partials.button', ['url' => $whatsapp, 'label' => __('lang.chat_on_whatsapp')])
    @include('emails.partials.button', ['url' => route('bookings'), 'label' => __('lang.my_bookings'), 'tone' => 'quiet'])
@else
    @include('emails.partials.button', ['url' => route('bookings'), 'label' => __('lang.my_bookings')])
@endif
@endsection

{{--
    Sent once, when somebody registers.

    What it is NOT: a verification email. Firebase sends that one from its own console
    template, and this must not look like a second one or the reader clicks the wrong
    link and reports the other as phishing. So there is no "confirm your address" line
    here at all — this says hello and points at the two things a new customer can
    actually do.

    Nothing is promised that the business does not do: no discount code, no app that is
    only on Android if the admin has not filled the Play Store link in, no "24/7
    support". The two service links and the contact details are the whole email.
--}}
@extends('emails.layout')

@section('content')
@php
    $ink = '#1C1917';
    $muted = '#78716C';
    $line = '#E7E5E4';
@endphp

<h1 class="h1" style="margin:0; font-family:Helvetica,Arial,sans-serif; font-size:26px; line-height:1.25; font-weight:700; color:{{ $ink }}; letter-spacing:-0.4px;">
    {{ __('lang.email_welcome_heading', ['name' => $firstName]) }}
</h1>

<p style="margin:14px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:15px; line-height:1.65; color:{{ $muted }};">
    {{ __('lang.email_welcome_lead', ['site' => $site->siteName()]) }}
</p>

{{-- The two services, as rows rather than cards: a card grid in email is four nested
     tables and a phone-width problem, and this is a list of two things. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 0 0; border-top:1px solid {{ $line }};">
    <tr>
        <td style="padding:18px 0 0 0; font-family:Helvetica,Arial,sans-serif;">
            <div style="font-size:15px; font-weight:700; color:{{ $ink }};">{{ $services->charter() }}</div>
            <div style="padding-top:5px; font-size:14px; line-height:1.6; color:{{ $muted }};">
                {{ __('lang.email_welcome_charter') }}
            </div>
        </td>
    </tr>
    <tr>
        <td style="padding:18px 0 4px 0; font-family:Helvetica,Arial,sans-serif;">
            <div style="font-size:15px; font-weight:700; color:{{ $ink }};">{{ $services->shuttle() }}</div>
            <div style="padding-top:5px; font-size:14px; line-height:1.6; color:{{ $muted }};">
                {{ __('lang.email_welcome_shuttle') }}
            </div>
        </td>
    </tr>
</table>

@include('emails.partials.button', [
    'url' => route('home'),
    'label' => __('lang.email_welcome_cta'),
])

<p style="margin:26px 0 0 0; padding-top:22px; border-top:1px solid {{ $line }}; font-family:Helvetica,Arial,sans-serif; font-size:13.5px; line-height:1.65; color:{{ $muted }};">
    {{ __('lang.email_welcome_help') }}
</p>
@endsection

{{--
    Tells the operator somebody has registered.

    NOT the customer's welcome email with a second recipient. That one says "Welcome,
    Budi" and points at the booking form — useless to the person who has to act on it,
    and faintly absurd arriving in the office inbox. What an operator wants is who,
    when, and how to reach them.

    Nothing here is a task: registering is not work. It is a notification, and it says so
    by being short.
--}}
@extends('emails.layout')

@section('content')
@php
    $ink = '#1C1917';
    $muted = '#78716C';
@endphp

<div style="font-family:Helvetica,Arial,sans-serif; font-size:11.5px; font-weight:700; letter-spacing:0.9px; text-transform:uppercase; color:{{ $muted }};">
    {{ __('lang.email_admin_label') }}
</div>

<h1 class="h1" style="margin:10px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:24px; line-height:1.25; font-weight:700; color:{{ $ink }}; letter-spacing:-0.4px;">
    {{ __('lang.email_admin_customer_heading') }}
</h1>

@include('emails.partials.details', ['rows' => $rows])

{{-- Reply goes to the customer, not to the office — see `AdminNewCustomerMail`. So the
     operator can simply hit reply, and this says so rather than leaving them to notice. --}}
<p style="margin:24px 0 0 0; font-family:Helvetica,Arial,sans-serif; font-size:13.5px; line-height:1.65; color:{{ $muted }};">
    {{ __('lang.email_admin_reply_note') }}
</p>
@endsection

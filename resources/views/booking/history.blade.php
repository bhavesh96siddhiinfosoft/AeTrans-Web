{{--
    My bookings.

    What this customer has ordered from the website, newest first. Each card is the
    booking as the customer would describe it — where, when, what it cost, and where it
    has got to — with the reference they read out to an operator.

    A PENDING booking carries the bank details and a WhatsApp button. The client's
    request of 2026-08-26: a customer had not paid because they had lost the account
    number, and the confirmation screen is seen once. This page is where they come back.
--}}
@extends('layouts.app')

@section('title', __('lang.my_bookings'))

@section('header')
        <h1 class="page-title">{{ __('lang.my_bookings') }}</h1>
        <p class="page-subtitle">{{ __('lang.everything_booked') }}</p>
@endsection

@section('content')
@if (empty($bookings))
    <div class="card">
        <div class="card-body stack">
            <div class="card-head">
                <div class="card-title">{{ __('lang.nothing_booked_yet') }}</div>
                <div class="card-subtitle">
                    {{ __('lang.no_bookings_yet_lead') }}
                </div>
            </div>

            <div class="row is-wrap">
                @if ($services->offersCharter())
                    <a href="{{ route('book.charter') }}" class="btn btn-primary">{{ $services->charter() }}</a>
                @endif
                @if ($services->offersShuttle())
                    <a href="{{ route('book.shuttle') }}" class="btn btn-secondary">{{ $services->shuttle() }}</a>
                @endif
            </div>
        </div>
    </div>
@else
    <div class="booking-list">
        @foreach ($bookings as $id => $booking)
            @php
                $status = (string) ($booking['status'] ?? 'pending');
                // The panel's list column and the app both show the document id's
                // first eight characters; the app uppercases them.
                $reference = strtoupper(substr($id, 0, 8));
                $charter = ($booking['bookingType'] ?? 'charter') === 'charter';
            @endphp

            <div class="card booking-card">
                <div class="card-body">
                    <div class="done-head">
                        <span class="done-service">
                            {{-- The LIVE name from the panel, not the `serviceName`
                                 snapshotted onto the booking when it was made. That
                                 field stays on the document for the operator's record,
                                 but showing it here would put the old name beside the
                                 new one on a site that had been renamed — which is the
                                 same staleness this screen just stopped having. --}}
                            {{ $services->label($charter) }}
                        </span>

                        {{-- The panel's own word for where the booking has got to,
                             so a customer reading it out is describing what the
                             operator can see on their screen. --}}
                        {{-- Keyed, not built from the status word: with slug keys a
                             translation cannot be looked up by capitalising a value, and
                             an unknown status must print something rather than the key. --}}
                        <span class="status-pill is-{{ $status }}">
                            {{ __('lang.status_'.$status) === 'lang.status_'.$status
                                ? ucfirst($status)
                                : __('lang.status_'.$status) }}
                        </span>
                    </div>

                    <dl class="review-list">
                        @if (! empty($booking['pickupAddress']))
                            <div>
                                <dt>{{ __('lang.pickup_address') }}</dt>
                                <dd>{{ $booking['pickupAddress'] }}</dd>
                            </div>
                        @endif

                        @foreach ((array) ($booking['dropoffAddresses'] ?? []) as $stop)
                            <div>
                                <dt>{{ __('lang.drop_off_address') }}</dt>
                                <dd>{{ $stop }}</dd>
                            </div>
                        @endforeach

                        {{-- Airport, terminal, then stop — the order the customer chose
                             them in on step 1, so the card reads back the way the form
                             was filled.

                             Each one guarded on its own rather than on the booking type:
                             a shuttle booked before 2026-09-01 carries no `terminal` at
                             all, and a row printed empty for those reads as a booking
                             missing information rather than one made before the field
                             existed. --}}
                        @if (! empty($booking['airportName']))
                            <div>
                                <dt>{{ __('lang.airport') }}</dt>
                                <dd>{{ $booking['airportName'] }}</dd>
                            </div>
                        @endif

                        @if (! empty($booking['terminal']))
                            <div>
                                <dt>{{ __('lang.terminal') }}</dt>
                                <dd>{{ $booking['terminal'] }}</dd>
                            </div>
                        @endif

                        @if (! empty($booking['dropPoint']))
                            <div>
                                {{-- Drop point leaving the airport, PICKUP POINT going to it. --}}
                                <dt>{{ ($booking['direction'] ?? '') === 'to_airport' ? __('lang.pickup_point') : __('lang.drop_point') }}</dt>
                                <dd>{{ $booking['dropPoint'] }}</dd>
                            </div>
                        @endif

                        @if (! empty($booking['travelDate']))
                            <div>
                                <dt>{{ __('lang.date_and_time') }}</dt>
                                <dd>
                                    {{ \Illuminate\Support\Carbon::parse($booking['travelDate'])->format('d/m/Y') }}
                                    @if (! empty($booking['pickupTime'])) · {{ $booking['pickupTime'] }} @endif
                                </dd>
                            </div>
                        @endif

                        @if (! empty($booking['returnDate']))
                            <div>
                                <dt>{{ __('lang.return_date') }}</dt>
                                <dd>{{ \Illuminate\Support\Carbon::parse($booking['returnDate'])->format('d/m/Y') }}</dd>
                            </div>
                        @endif

                        @if (! empty($booking['vehicleTypeName']))
                            <div>
                                <dt>{{ __('lang.car_type') }}</dt>
                                <dd>{{ $booking['vehicleTypeName'] }}</dd>
                            </div>
                        @endif

                        {{-- Every passenger, so a customer can check the list they gave us. --}}
                        @if (! empty($booking['passengerNames']))
                            <div>
                                <dt>{{ __('lang.passenger_names') }}</dt>
                                <dd>
                                    @foreach ((array) $booking['passengerNames'] as $passenger)
                                        <div>{{ $passenger }}</div>
                                    @endforeach
                                </dd>
                            </div>
                        @endif

                        @if (isset($booking['cost']))
                            @php
                                /* `cost` is the FULL price and the discount sits beside it — the client's
                                   decision, 2026-09-02. So the row that says what is OWED is
                                   `payableAmount`, and a booking made before coupons existed carries none:
                                   for those it falls back to `cost`, which is the same number. */
                                $discount = (float) ($booking['discountAmount'] ?? 0);
                                $payable = (float) ($booking['payableAmount'] ?? $booking['cost']);
                            @endphp
                        
                            <div>
                                <dt>{{ $discount > 0 ? __('lang.subtotal') : __('lang.cost') }}</dt>
                                {{-- A ternary, not an inline `@if`: Blade does not read a directive glued to a
                                 word character, so `<dd@if(...)` left the `@if` as literal text and its
                                 `@endif` unmatched — a parse error at render time. --}}
                            <dd class="{{ $discount > 0 ? '' : 'is-strong' }}">{{ $money->format((float) $booking['cost'], $booking['currencyCode'] ?? null) }}</dd>
                            </div>
                        
                            @if ($discount > 0)
                                <div>
                                    <dt>
                                        {{ __('lang.discount') }}
                                        @if (! empty($booking['couponCode']))
                                            <span class="coupon-code">{{ $booking['couponCode'] }}</span>
                                        @endif
                                    </dt>
                                    <dd class="is-discount">&minus;{{ $money->format($discount, $booking['currencyCode'] ?? null) }}</dd>
                                </div>
                        
                                <div>
                                    <dt>{{ __('lang.total_to_pay') }}</dt>
                                    <dd><strong>{{ $money->format($payable, $booking['currencyCode'] ?? null) }}</strong></dd>
                                </div>
                            @endif
                        @endif
                    </dl>

                    <div class="done-reference">
                        <span class="done-reference-label">{{ __('lang.booking_reference') }}</span>
                        <code class="done-reference-value">{{ $reference }}</code>
                    </div>

                    {{-- Only while it is pending. Once an operator has accepted the
                         booking the money has arrived, and leaving a "pay this" panel
                         on a confirmed order is how somebody pays twice. --}}
                    @if ($status === 'pending')
                        <div class="pay-pending">
                            <p class="pay-lead">{{ __('lang.booking_paid_yet') }}</p>
                            @include('partials.bank-transfer', ['bank' => $bank, 'reference' => $reference])
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection

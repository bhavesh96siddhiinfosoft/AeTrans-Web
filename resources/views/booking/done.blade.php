{{--
    The booking has been sent.

    Deliberately not called "confirmed": what has happened is that a REQUEST reached the
    operator. A charter holds its vehicle from this moment; a shuttle seat does not, and
    is only taken when the order is accepted. Saying "confirmed" here would promise
    something the client's own rules do not.

    Follows the app's screen: the tick, the reference, and how to pay. There is no
    gateway — the customer transfers to the account below and sends the proof on
    WhatsApp, which is the client's own answer to how a booking is paid for.

    The reference is the document id's first 8 characters, uppercased. That is what the
    app prints and what the panel's list column shows, so it is what an operator can
    search for when the customer reads it out.
--}}
@extends('layouts.public')

@section('title', __('lang.booking_received'))

@section('content')
<div class="page booking-page">
    <div class="done-mark" aria-hidden="true">
        <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <path d="M20 6 9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>

    <div class="page-head is-centred">
        <h1 class="page-title">{{ __('lang.booking_received') }}</h1>
        <p class="page-subtitle">
            {{ __('lang.booking_received_lead') }}
        </p>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="done-head">
                <span class="done-service">
                    @if (($booking['bookingType'] ?? 'charter') === 'charter')
                        {{ $services->charter() }}
                    @else
                        {{ $services->shuttle() }}
                    @endif
                </span>
                <span class="status-pill">{{ __('lang.status_pending') }}</span>
            </div>

            @if ($booking)
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

                    {{-- The shuttle's route. It was missing entirely until 2026-09-01:
                         a shuttle carries no `pickupAddress` and no `dropoffAddresses`,
                         so this screen told a customer who had just booked one nothing
                         but the date and the price — not where they were being collected
                         from, nor where they were going.

                         Each row guarded on its own value rather than on the booking
                         type, so a booking made before `terminal` existed simply has no
                         terminal row instead of an empty one. --}}
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
            @endif

            <div class="done-reference">
                <span class="done-reference-label">{{ __('lang.booking_reference') }}</span>
                <code class="done-reference-value">{{ $reference }}</code>
            </div>
        </div>
    </div>

    {{-- No outer guard: the partial shows the account only when there is one, but
         the WhatsApp button is worth having either way. --}}
    <div class="card">
        <div class="card-body">
            @include('partials.bank-transfer', ['bank' => $bank, 'reference' => $reference])
        </div>
    </div>

    <div class="stack">
        <div class="row is-wrap">
            <a href="{{ route('bookings') }}" class="btn btn-secondary">{{ __('lang.my_bookings') }}</a>
            <a href="{{ route('home') }}" class="btn btn-ghost">{{ __('lang.done') }}</a>
        </div>
    </div>
</div>
@endsection

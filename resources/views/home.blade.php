{{--
    The landing page — what a customer sees on arrival. This is the DEFAULT route;
    nobody is sent to a sign-in screen to reach it.

    Content is written here for now. It moves to Firestore (`services`,
    `vehicle_types`, `shuttle_rates`, `cms_pages`, `banner`) once the server-side
    Firestore reader exists — see docs/website-spec.md §2. Nothing on this page
    invents a figure the panel cannot back up: no fake fleet size, no invented
    on-time rate.
--}}
@extends('layouts.public')

@section('content')
<section class="hero">
    <div class="hero-inner">
        <span class="eyebrow">{{ __('lang.home_eyebrow') }}</span>

        <h1 class="hero-title">{{ __('lang.home_headline') }}</h1>

        <p class="hero-lede">
            {{ __('lang.home_lead') }}
        </p>

        {{-- Both buttons follow the admin's switches. With one service off the other
             takes the primary style, so the page never leads with a secondary button
             as its only call to action. --}}
        <div class="row is-wrap">
            @if ($services->offersCharter())
                <a href="{{ route('book.charter') }}" class="btn btn-primary">{{ __('lang.book_charter') }}</a>
            @endif
            @if ($services->offersShuttle())
                <a href="{{ route('book.shuttle') }}" class="btn {{ $services->offersCharter() ? 'btn-secondary' : 'btn-primary' }}">{{ __('lang.book_shuttle_seat') }}</a>
            @endif
        </div>
    </div>
</section>

{{-- The whole section goes when the admin has switched BOTH services off. An empty
     "Two ways to travel" heading over nothing is worse than no section at all. --}}
@unless ($services->offersNothing())
<section class="section" id="services">
    <div class="section-inner">
        <div class="section-head">
            {{-- The heading counts. "Two ways to travel" over a single card reads as a
                 page that has lost half of itself, and the lede below it is about
                 choosing BETWEEN two — so with one service both give way. --}}
            @if ($services->offersCharter() && $services->offersShuttle())
                <h2 class="section-title">{{ __('lang.two_ways_to_travel') }}</h2>
                <p class="section-lede">{{ __('lang.pick_one_fits_trip') }}</p>
            @else
                <h2 class="section-title">{{ __('lang.what_we_offer') }}</h2>
            @endif
        </div>

        <div class="service-grid">
            @if ($services->offersCharter())
            <article class="service-card" id="charter">
                <h3 class="service-name">{{ $services->charter() }}</h3>
                <p class="service-lede">
                    {{ __('lang.charter_service_blurb') }}
                </p>

                <ul class="service-points">
                    <li>{{ __('lang.seats_7_to_18') }}</li>
                    <li>{{ __('lang.one_way_round_trip_return') }}</li>
                    <li>{{ __('lang.own_pickup_address_stops') }}</li>
                </ul>

                <a href="{{ route('book.charter') }}" class="btn btn-primary">{{ __('lang.get_a_quote') }}</a>
            </article>
            @endif

            @if ($services->offersShuttle())
            <article class="service-card" id="shuttle">
                <h3 class="service-name">{{ $services->shuttle() }}</h3>
                <p class="service-lede">
                    {{ __('lang.shuttle_service_blurb') }}
                </p>

                <ul class="service-points">
                    <li>{{ __('lang.fixed_fare_per_airport_drop') }}</li>
                    <li>{{ __('lang.to_or_from_airport') }}</li>
                    {{-- The free-time rule from the panel, stated plainly: the
                         timetable is a suggestion, not a restriction. --}}
                    <li>{{ __('lang.scheduled_departures_tell_time_need') }}</li>
                </ul>

                <a href="{{ route('book.shuttle') }}" class="btn btn-primary">{{ __('lang.check_seats') }}</a>
            </article>
            @endif
        </div>
    </div>
</section>
@endunless

<section class="section is-alt">
    <div class="section-inner">
        <div class="section-head">
            <h2 class="section-title">{{ __('lang.how_booking_works') }}</h2>
        </div>

        {{-- Numbered because this genuinely is a sequence — each step depends on
             the one before it. --}}
        <ol class="steps">
            <li class="step">
                <span class="step-n">1</span>
                <div>
                    <h3 class="step-title">{{ __('lang.tell_us_the_trip') }}</h3>
                    <p class="step-text">{{ __('lang.shuttle_step_lead') }}</p>
                </div>
            </li>
            <li class="step">
                <span class="step-n">2</span>
                <div>
                    <h3 class="step-title">{{ __('lang.see_the_price') }}</h3>
                    <p class="step-text">{{ __('lang.price_from_published_rates') }}</p>
                </div>
            </li>
            <li class="step">
                <span class="step-n">3</span>
                <div>
                    <h3 class="step-title">{{ __('lang.how_it_works_we_confirm') }}</h3>
                    {{-- Honest about the current flow: a booking is a request until
                         an admin accepts it. Saying otherwise would promise an
                         instant confirmation the system does not give. --}}
                    <p class="step-text">{{ __('lang.how_it_works_confirm') }}</p>
                </div>
            </li>
        </ol>
    </div>
</section>

<section class="section">
    <div class="section-inner">
        <div class="cta">
            <div>
                <h2 class="section-title">{{ __('lang.ready_when_you_are') }}</h2>
                <p class="section-lede">{{ __('lang.register_lead') }}</p>
            </div>

            <div class="row is-wrap">
                @auth
                    <a href="{{ route('bookings') }}" class="btn btn-primary">{{ __('lang.my_bookings') }}</a>
                @else
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}" class="btn btn-primary">{{ __('lang.create_an_account') }}</a>
                    @endif
                    <a href="{{ route('login') }}" class="btn btn-secondary">{{ __('lang.log_in') }}</a>
                @endauth
            </div>
        </div>
    </div>
</section>
@endsection

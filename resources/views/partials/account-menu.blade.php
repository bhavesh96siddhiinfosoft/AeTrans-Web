{{--
    The signed-in customer's corner of the header.

    Their name and email, and the pages that belong to them. Replaced a lone
    "My bookings" button on 2026-08-26: on a site where the only sign-in is at the end of
    a booking, a customer has no other way to tell whether they are still signed in, or
    which account they are signed in AS — which matters here, because a booking belongs
    to the account that made it.

    NO JAVASCRIPT, like the rest of this header. A checkbox holds the open state and a
    full-screen label closes it again, so a stray click outside dismisses the menu the
    way a real dropdown does. `<details>` was not used for the same reason the site menu
    does not use it: a browser hides a closed `<details>`'s content itself, and no
    `display` rule on a child overrides that.
--}}
@php
    $customer = auth()->user();
    // The first letter of whatever they are called, for the badge. A name that starts
    // with a space or is somehow empty falls back to their email, and then to a mark
    // rather than an empty circle.
    $initial = mb_strtoupper(mb_substr(trim($customer->name) ?: trim((string) $customer->email) ?: '?', 0, 1));
@endphp

<div class="account">
    <input type="checkbox" id="account-menu" class="account-input">

    {{-- Closes the menu when anything else is clicked. Only in the layout while the
         menu is open, so it never sits over the page. --}}
    <label for="account-menu" class="account-scrim" aria-hidden="true"></label>

    <label for="account-menu" class="account-toggle" tabindex="0" role="button"
           aria-haspopup="true" aria-label="{{ __('lang.your_account') }}">
        <span class="account-badge" aria-hidden="true">{{ $initial }}</span>
        <span class="account-name">{{ $customer->name }}</span>
        <svg class="account-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m6 9 6 6 6-6"/>
        </svg>
    </label>

    <div class="account-panel">
        <div class="account-who">
            <span class="account-who-name">{{ $customer->name }}</span>
            <span class="account-who-email">{{ $customer->email }}</span>
        </div>

        <nav class="account-links">
            <a href="{{ route('bookings') }}">{{ __('lang.my_bookings') }}</a>
            <a href="{{ route('profile.edit') }}">{{ __('lang.profile_settings') }}</a>
        </nav>

        {{-- A POST, because signing out changes something. A link would let any page
             sign the customer out by embedding an image. --}}
        <form method="POST" action="{{ route('logout') }}" class="account-signout">
            @csrf
            <button type="submit" class="account-signout-btn">{{ __('lang.log_out') }}</button>
        </form>
    </div>
</div>

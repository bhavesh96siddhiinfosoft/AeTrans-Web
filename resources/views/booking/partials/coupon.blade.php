{{--
    The discount code box, and what it takes off.

    Shown on both services' review step, under the price — the last place a price can
    still be changed, and the only place a customer thinks to look for it.

    ── ITS OWN FORM, OUTSIDE THE BOOKING FORM ──────────────────────────────────

    Nested forms are not valid HTML and browsers silently drop the inner one, so this
    sits BESIDE the details form rather than inside it. That is also the behaviour you
    want: applying a code is a separate act with its own answer, and pressing Enter in
    the code box must not send the booking.

    ── THIS FILE IS ALSO THE AJAX RESPONSE ─────────────────────────────────────

    `coupon.js` posts the form and the server sends this partial back, re-rendered. So
    the markup and the money formatting exist in ONE place: the script swaps this block
    for the new one and has no idea what a Rupiah looks like.

    Without the script it is a plain form that posts and redirects, and the customer gets
    exactly the same discount. That is why the button is a real submit and the whole
    thing works with scripts off.

    Expects `$type` ('charter' or 'shuttle'), `$code`, `$subtotal`, `$discount` and
    `$money`. `$message` and `$error` are optional — the redirect path leaves them in the
    session, the JSON path passes them in.
--}}
@php
    $message = $message ?? session('status');
    $error = $error ?? ($errors->has('code') ? $errors->first('code') : null);
@endphp

{{-- The id is the swap target. `coupon.js` replaces what is inside it. --}}
<div class="coupon" id="coupon-block">
    <form method="POST" action="{{ route('book.coupon', ['type' => $type]) }}" class="coupon-form" data-coupon-form>
        @csrf

        <label for="code" class="form-label">{{ __('lang.discount_code') }}</label>

        <div class="coupon-row">
            <input id="code" name="code" type="text" class="form-input" maxlength="64"
                   value="{{ $code }}"
                   placeholder="{{ __('lang.enter_discount_code') }}"
                   data-empty-message="{{ __('lang.enter_a_discount_code') }}"
                   autocomplete="off" autocapitalize="characters" spellcheck="false">

            {{-- One button, and it says what it will do.

                 REMOVE POSTS ITS OWN FLAG rather than an empty code. Both actions used
                 to arrive as `code=""` and were indistinguishable, so pressing Apply
                 with an empty box answered "Code removed" — about a code that had never
                 been applied. --}}
            @if ($discount > 0)
                <button type="submit" class="btn btn-secondary" data-coupon-submit name="remove" value="1">
                    {{ __('lang.remove') }}
                </button>
            @else
                <button type="submit" class="btn btn-secondary" data-coupon-submit>
                    {{ __('lang.apply') }}
                </button>
            @endif
        </div>

        @if ($error)
            <ul class="form-error">
                <li>{{ $error }}</li>
            </ul>
        @endif

        @if ($message)
            <p class="form-help is-good">{{ $message }}</p>
        @endif
    </form>

    {{-- The arithmetic, shown rather than asserted. A customer who can see the full
         price, the discount and the total has no reason to wonder whether the code
         worked — and `cost` on the booking stays the FULL price, so this is the only
         place the two numbers are set beside each other. --}}
    @if ($discount > 0)
        <dl class="coupon-total">
            <div>
                <dt>{{ __('lang.subtotal') }}</dt>
                <dd>{{ $money->format($subtotal) }}</dd>
            </div>
            <div>
                <dt>{{ __('lang.discount') }} @if ($code) <span class="coupon-code">{{ $code }}</span> @endif</dt>
                <dd class="is-discount">&minus;{{ $money->format($discount) }}</dd>
            </div>
            <div class="coupon-payable">
                <dt>{{ __('lang.total_to_pay') }}</dt>
                <dd><strong>{{ $money->format(max(0, $subtotal - $discount)) }}</strong></dd>
            </div>
        </dl>
    @endif
</div>

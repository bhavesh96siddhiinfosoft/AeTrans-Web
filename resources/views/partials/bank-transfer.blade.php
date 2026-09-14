{{--
    How to pay for a booking that is still pending.

    Shown on the confirmation screen AND on every pending booking in My bookings — the
    client's request of 2026-08-26, after a customer paid nothing because they had lost
    the account number and the screen it was on.

    Expects `$bank` (SiteSettings::bankAccount()) and `$reference`.
--}}
@if ($bank)
    <div class="pay-block">
        <h3 class="pay-title">{{ __('lang.pay_via_bank_transfer') }}</h3>
        <p class="form-help">
            {{ __('lang.bank_transfer_note') }}
        </p>

        <dl class="review-list">
            @foreach ([
                'bankName' => __('lang.bank'),
                'holderName' => __('lang.account_holder'),
                'accountNumber' => __('lang.account_number'),
                'bankCode' => __('lang.bank_code'),
                'branchName' => __('lang.branch'),
                'swiftCode' => __('lang.swift'),
            ] as $field => $label)
                @if (! empty($bank[$field]))
                    <div>
                        <dt>{{ $label }}</dt>
                        <dd>{{ $bank[$field] }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </div>
@endif

@if ($whatsapp = $site->whatsapp())
    <a href="https://wa.me/{{ ltrim(preg_replace('/[^0-9+]/', '', $whatsapp), '+') }}?text={{ urlencode(__('lang.whatsapp_booking_message', ['reference' => $reference])) }}"
       class="btn btn-whatsapp" rel="noopener">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2a10 10 0 0 0-8.6 15L2 22l5.2-1.4A10 10 0 1 0 12 2zm5.6 14.2c-.2.7-1.2 1.3-2 1.4-.5.1-1.2.1-3.7-.8-3.1-1.3-5.1-4.5-5.3-4.7-.1-.2-1.2-1.6-1.2-3.1s.8-2.2 1.1-2.5c.3-.3.6-.4.8-.4h.6c.2 0 .4 0 .6.5l.9 2c.1.2.1.4 0 .5l-.4.6-.3.3c-.1.1-.2.3-.1.5.1.2.6 1.1 1.4 1.8 1 .9 1.8 1.2 2 1.3.2.1.4.1.5-.1l.8-.9c.2-.2.3-.2.5-.1l2 .9c.2.1.4.2.4.3.1.2.1.7-.1 1.4z"/>
        </svg>
        {{ __('lang.chat_on_whatsapp') }}
    </a>
@endif

{{--
    A phone number with its country code, as the admin panel takes one.

    @include('partials.phone-field', [
        'id' => 'customerPhone', 'name' => 'customerPhone', 'value' => $customer['customerPhone'],
    ])

    The hidden input named `$name` holds the FULL international number — `+62812…` — and
    is what the form posts, so the server-side rules do not change and the value the
    dispatcher rings is unambiguous. The dial button and the national number beside it are
    drawn by public/js/country-select.js.

    Without JavaScript the hidden input is all there is, so a plain text field is rendered
    in its place and the customer types the number themselves. Nothing here is required
    for the form to work; the picker only saves them typing `+62`.
--}}
@php
    $default = $default ?? 'ID';
    $requiredMessage = $requiredMessage ?? __('lang.enter_phone_number_reach');

    /*
     * Deduplicated on ISO2 code, keeping the first dial code. The source file repeats
     * countries that have several — the Dominican Republic three times — and nothing
     * here stores more than the code, so the extras are indistinguishable rows.
     */
    $countries = collect(json_decode((string) @file_get_contents(public_path('json/countriesdata.json')), true) ?: [])
        ->unique('code')
        ->map(fn (array $country) => [
            'code' => $country['code'],
            'countryName' => $country['countryName'],
            'phoneCode' => $country['phoneCode'],
        ])
        ->values();
@endphp

<input type="hidden" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}"
       data-required data-required-message="{{ $requiredMessage }}">

{{-- The no-JavaScript field. The script hides it and builds the picker in its place. --}}
<div class="picker-wrap" id="{{ $id }}-picker">
    <input type="tel" class="form-input" data-phone-fallback
           value="{{ $value }}" placeholder="+62 812 3456 7890"
           aria-label="{{ __('lang.phone_number') }}">
</div>

<script src="{{ asset('js/dropdown-position.js') }}?v={{ is_file(public_path('js/dropdown-position.js')) ? filemtime(public_path('js/dropdown-position.js')) : '' }}"></script>
<script src="{{ asset('js/country-select.js') }}?v={{ is_file(public_path('js/country-select.js')) ? filemtime(public_path('js/country-select.js')) : '' }}"></script>
<script>
    (function () {
        'use strict';

        var mount = document.getElementById(@json($id . '-picker'));

        // A failed download leaves the plain field above, which still posts nothing —
        // so it is mirrored onto the hidden input by hand in that case.
        if (!window.initPhoneInput || !mount) {
            var fallback = mount && mount.querySelector('[data-phone-fallback]');

            if (fallback) {
                fallback.addEventListener('input', function () {
                    document.getElementById(@json($id)).value = fallback.value;
                });
            }

            return;
        }

        window.initPhoneInput(mount, {
            inputId: @json($id),
            countries: @json($countries),
            flagBase: @json(asset('flags/120')),
            defaultCountry: @json($default),
            searchLabel: @json(__('lang.search')),
            emptyLabel: @json(__('lang.no_matches')),
        });
    }());
</script>

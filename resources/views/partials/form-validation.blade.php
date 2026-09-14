{{--
    Turns the browser's validation bubbles into the site's own field messages.

    Included by every booking step and by the sign-in and register pages — it lived under
    `booking/partials` until the auth screens needed it too, and there was nothing
    booking-specific in it: it attaches to every `form.form` on the page and reads the
    constraints off the elements.

    The native constraints stay on the elements — `required`, `type`, `min`, `max`,
    `minlength` — and the script reads them, so nothing is described twice and the
    server's rules stay the ones that count.
--}}
<script src="{{ asset('js/form-validate.js') }}?v={{ is_file(public_path('js/form-validate.js')) ? filemtime(public_path('js/form-validate.js')) : '' }}"></script>
<script>
    (function () {
        'use strict';

        if (!window.initFormValidation) {
            // The download failed: the browser's own bubbles are still in place, because
            // `novalidate` is set by the script and not by the markup.
            return;
        }

        document.querySelectorAll('form.form').forEach(function (form) {
            window.initFormValidation(form, {
                required: @json(__('lang.this_field_is_required')),
                email: @json(__('lang.please_enter_valid_email_address')),
                tel: @json(__('lang.please_enter_valid_phone_number')),
                number: @json(__('lang.please_enter_number_between')),
                short: @json(__('lang.use_at_least_characters')),
                match: @json(__('lang.these_do_not_match')),
            });
        });
    }());
</script>

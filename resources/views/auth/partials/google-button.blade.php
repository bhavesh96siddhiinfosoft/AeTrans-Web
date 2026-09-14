{{--
    Google sign-in.

    Rendered on both the login and the register page, because they are the same act:
    Firebase creates the account if it is new and signs it in if it is not.

    The site's own button, in the site's own type and radii — not the one Google
    Identity Services draws, which arrives in an iframe that cannot be styled to match
    this card. The Google mark keeps its official colours, which is what Google's
    branding terms actually ask for.

    The button POSTs a normal form — the script's only job is to fill in the ID token
    that Google's window produced. Everything after that (CSRF, validation errors, the
    redirect) is the same path the password form takes.

    Hidden with `hidden` rather than omitted: the form must exist before the module
    loads so the script has something to attach to, and a customer with JavaScript off
    must not be shown a button that cannot work. It is unhidden by the script.
--}}
@if ($site->googleLoginEnabled())
    <link rel="preconnect" href="https://www.gstatic.com" crossorigin>

    {{-- Every sentence the script can show, in the reader's language. They live here
         rather than in the file because that file is static JavaScript with no access to
         `__()` — and until 2026-09-01 it carried them hardcoded in English, on a site
         that defaults to Indonesian. --}}
    <div class="auth-alt" data-google-signin hidden
         data-msg-blocked="{{ __('lang.google_popup_blocked') }}"
         data-msg-exists="{{ __('lang.google_email_uses_password') }}"
         data-msg-disabled="{{ __('lang.google_account_suspended') }}"
         data-msg-network="{{ __('lang.google_no_connection') }}"
         data-msg-domain="{{ __('lang.google_not_enabled_here') }}"
         data-msg-failed="{{ __('lang.google_did_not_complete') }}">
        <div class="auth-divider"><span>{{ __('lang.or') }}</span></div>

        <form method="POST" action="{{ route('auth.google') }}" data-google-form>
            @csrf
            <input type="hidden" name="id_token" data-google-id-token>
            <input type="hidden" name="refresh_token" data-google-refresh-token>

            <button type="button" class="btn btn-secondary is-block" data-google-button>
                <svg width="17" height="17" viewBox="0 0 18 18" aria-hidden="true" focusable="false">
                    <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
                    <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.81.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
                    <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
                    <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
                </svg>
                <span data-google-label data-busy="{{ __('lang.opening_google') }}">{{ __('lang.continue_with_google') }}</span>
            </button>
        </form>

        <p class="form-error" data-google-error hidden></p>
    </div>

    {{-- PUBLIC by design: this identifies the Firebase project, it authorises nothing.
         What protects the data is the Firestore rules — see config/firebase.php. --}}
    <script type="application/json" id="firebase-config">@json(config('firebase.web'))</script>
    <script type="module" src="{{ asset('js/firebase-google.js') }}?v={{ is_file(public_path('js/firebase-google.js')) ? filemtime(public_path('js/firebase-google.js')) : '' }}"></script>
@endif

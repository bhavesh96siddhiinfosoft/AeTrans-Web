/*
 * Google sign-in, the only part of authentication that has to happen in the browser:
 * Google's consent screen needs a window, and only the Firebase SDK can open it.
 *
 * What this file does NOT do is sign the customer into this site. It collects the ID
 * token Firebase minted and hands it to the server, which verifies it with Google
 * before trusting a word of it (GoogleSessionController). A forged token posted to
 * that endpoint by hand gets nowhere.
 *
 * `signInWithPopup`, not Google Identity Services. The Website Panel this project is
 * modelled on uses Identity Services, and it was built that way first — but Identity
 * Services only hands over a credential from the button GOOGLE draws, in its own
 * iframe, which cannot be styled to match this card. The site's own button, with the
 * project's type and radii, is worth the pop-up. The trade-off to know about: a
 * blocked pop-up is a real failure mode, so it is caught and explained below rather
 * than left as a button that appears to do nothing.
 *
 * An ES module straight from the CDN, with no bundler: this project has no build step
 * (see resources/views/layouts/guest.blade.php). The version is pinned, because
 * "latest" is a third party changing our login page without telling us.
 */
import { initializeApp } from 'https://www.gstatic.com/firebasejs/10.12.5/firebase-app.js';
import {
    getAuth,
    GoogleAuthProvider,
    signInWithPopup,
    signOut,
    setPersistence,
    inMemoryPersistence,
} from 'https://www.gstatic.com/firebasejs/10.12.5/firebase-auth.js';

const container = document.querySelector('[data-google-signin]');
const configEl = document.getElementById('firebase-config');

/*
 * Every sentence this file can show, from the page rather than from here.
 *
 * They were written in English in this file until 2026-09-01, on a site whose DEFAULT
 * language is Indonesian — so the one moment a customer most needs to be told what went
 * wrong was the one moment the site stopped speaking their language. `data-google-signin`
 * carries them as data attributes; see auth/partials/google-button.blade.php.
 *
 * The fallbacks are here so a missing attribute degrades to a real sentence rather than
 * to an empty red line, which reads as a bug rather than as an answer.
 */
function say(key, fallback) {
    return (container && container.dataset[key]) || fallback;
}

if (container && configEl) {
    const form = container.querySelector('[data-google-form]');
    const button = container.querySelector('[data-google-button]');
    const label = container.querySelector('[data-google-label]');
    const idTokenField = container.querySelector('[data-google-id-token]');
    const refreshTokenField = container.querySelector('[data-google-refresh-token]');
    const errorEl = container.querySelector('[data-google-error]');

    const auth = getAuth(initializeApp(JSON.parse(configEl.textContent)));

    /*
     * The browser keeps NO session of its own. The server holds the tokens for the life
     * of the Laravel session, so a Firebase session in IndexedDB would only be a second,
     * longer-lived login left on the machine after the customer logs out — which matters
     * on the shared and borrowed phones this site is used from.
     */
    setPersistence(auth, inMemoryPersistence);

    // Only now is the button real. Until the module runs, pressing it would do nothing.
    container.hidden = false;

    const idle = label ? label.textContent : '';

    const stopWaiting = () => {
        button.disabled = false;
        if (label) {
            label.textContent = idle;
        }
    };

    const fail = (message) => {
        stopWaiting();
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.hidden = false;
        }
    };

    button.addEventListener('click', async () => {
        button.disabled = true;
        if (errorEl) {
            errorEl.hidden = true;
        }
        if (label) {
            label.textContent = label.dataset.busy || label.textContent;
        }

        try {
            const provider = new GoogleAuthProvider();

            // Always ask which account, rather than silently reusing the one Google
            // happens to be signed into. People share devices.
            provider.setCustomParameters({ prompt: 'select_account' });

            const credential = await signInWithPopup(auth, provider);

            idTokenField.value = await credential.user.getIdToken();
            refreshTokenField.value = credential.user.refreshToken || '';

            // Hand the tokens over and leave nothing behind in the browser.
            await signOut(auth);

            form.submit();
        } catch (error) {
            const code = error && error.code ? error.code : '';

            // Closing the window, or clicking twice, is not an error worth a red line.
            if (
                code === 'auth/popup-closed-by-user' ||
                code === 'auth/cancelled-popup-request' ||
                code === 'auth/user-cancelled'
            ) {
                stopWaiting();
                return;
            }

            if (code === 'auth/popup-blocked') {
                fail(say('msgBlocked', 'Your browser blocked the Google window. Allow pop-ups for this site, or use your email and password.'));
                return;
            }

            /*
             * Raised when this address already signs in another way and the Firebase
             * project is set to one account per email. Firebase will not merge them on
             * its own, and neither should we — linking is an action for the person who
             * can prove they own both.
             */
            if (code === 'auth/account-exists-with-different-credential') {
                fail(say('msgExists', 'This email already signs in with a password. Use your email and password below.'));
                return;
            }

            if (code === 'auth/user-disabled') {
                fail(say('msgDisabled', 'This account has been suspended. Please contact us.'));
                return;
            }

            if (code === 'auth/network-request-failed') {
                fail(say('msgNetwork', 'No connection to Google. Check your network and try again.'));
                return;
            }

            /*
             * The one worth naming: the domain the site is being served from is not in
             * the Firebase project's authorised domains, so Google refuses the window.
             * It is a console setting, not a code fault, and the generic message below
             * would send someone hunting through this file for it.
             */
            if (code === 'auth/unauthorized-domain') {
                fail(say('msgDomain', 'Google sign-in is not enabled for this address yet. Please use your email and password.'));
                console.error('Add this domain to Firebase console -> Authentication -> Settings -> Authorized domains.');
                return;
            }

            console.error('Google sign-in error:', error);
            fail(say('msgFailed', 'Google sign-in did not complete. Please try again, or use your email and password.'));
        }
    });
}

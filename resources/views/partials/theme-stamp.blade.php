{{--
    Stamps the saved theme on <html> BEFORE the first paint.

    Inline, in the <head>, and deliberately not in a file: an external script is a
    second request, and the page would paint light before it arrived — the white flash
    on a dark phone that spec §11 rules out. It is a few hundred bytes, and it is the
    one place a <script> earns being inline.

    Nothing is stamped when the visitor has expressed no preference, which lets the
    stylesheet's `prefers-color-scheme` block follow the operating system.

    The click handler lives here too, so the toggle works on every page that has a head
    without a second file to keep in step.
--}}
<script>
    (function () {
        var root = document.documentElement;

        try {
            var saved = localStorage.getItem('theme');

            if (saved === 'dark' || saved === 'light') {
                root.dataset.theme = saved;
            }
        } catch (e) {
            // Private mode, or storage switched off. The OS preference still applies.
        }

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-theme-toggle]');

            if (!button) {
                return;
            }

            // What the visitor sees now, whether that came from a choice or the OS.
            var dark = root.dataset.theme
                ? root.dataset.theme === 'dark'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;

            var next = dark ? 'light' : 'dark';

            root.dataset.theme = next;

            try {
                localStorage.setItem('theme', next);
            } catch (e) {
                // The page still switches; it just will not be remembered.
            }
        });
    })();
</script>

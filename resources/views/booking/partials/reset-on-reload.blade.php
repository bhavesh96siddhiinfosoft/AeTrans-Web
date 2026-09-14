{{--
    Refreshing a step of the charter wizard starts the booking over.

    The client's rule of 2026-08-21: a refresh clears what was filled in.

    WHY THE BROWSER HAS TO DECIDE THIS, and not the server: a refresh is byte-for-byte
    the same GET request as following a link to the same page. There is nothing in it to
    tell them apart — no header, no flag. `PerformanceNavigationTiming.type` is the one
    thing that knows, because it is the browser reporting how ITS OWN navigation began.

    That distinction is what keeps the sign-in step working. Coming back from the login
    screen is a `navigate`, so the half-filled booking survives exactly as it must; only
    a `reload` throws it away. `back_forward` is left alone too — pressing Back is not
    asking to start again.

    In the <head> and synchronous, before the body has painted, so the old answers never
    flash up on their way to being discarded. `location.replace` rather than `href`, so
    the discarded page does not sit in the history for Back to return to.

    The URL travels as a DATA ATTRIBUTE rather than being printed into the script. Blade's
    directives for putting a PHP value into JavaScript all escape every forward slash, so
    the source would read "http:\/\/…" — valid, but unreadable to anyone working out
    where a refresh went, and impossible to search for by path. An attribute is escaped
    by Blade in the ordinary way and reads as itself.

    Two things to know before editing the script below: Blade compiles its directives
    inside <script> tags, INCLUDING inside JavaScript comments, so naming a directive in
    a comment here is enough to break the page.
--}}
{{-- `$restart` is the route name of the flow this page belongs to; it defaulted to the
     charter one while charter was the only wizard. --}}
<script data-restart-url="{{ route($restart ?? 'book.charter.restart') }}">
    (function () {
        var url = document.currentScript.dataset.restartUrl;

        try {
            var entries = performance.getEntriesByType('navigation');
            var type = entries.length ? entries[0].type : null;

            // Older browsers with no Navigation Timing Level 2 keep the draft. Losing a
            // customer's work is the worse failure of the two, so the fallback is to do
            // nothing rather than to guess.
            if (type === 'reload') {
                location.replace(url);
            }
        } catch (e) {
            // Timing API unavailable or blocked. Same reasoning: leave the draft alone.
        }
    })();
</script>

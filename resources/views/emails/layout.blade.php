{{--
    The shell every email this site sends is drawn in.

    ── WHY THIS FILE BREAKS THE PROJECT'S CSS RULE ─────────────────────────────

    The site has no build step and no inline CSS: `public/css/style.css` is hand written
    and editing it is the whole pipeline. EMAIL IS NOT THE WEB and does not get that
    rule. Gmail strips `<link>` entirely, Outlook renders through Word, and several
    clients drop `<style>` blocks on forwarding — so every rule here is an inline
    `style` attribute on the element it applies to, and the layout is tables rather than
    flex or grid. That is not a shortcut; it is the only thing that renders everywhere.

    A `<style>` block is included as well, but ONLY for the two things an attribute
    cannot express: the mobile breakpoint and the dark-mode hints. Anything that must
    survive is inline; anything in the block is an improvement that may be dropped.

    ── ONE LIGHT DESIGN, DELIBERATELY ──────────────────────────────────────────

    No dark palette. Email clients invert colours on their own terms — Outlook.com
    rewrites hex values, Gmail's app inverts some and not others — and a design that
    fights that ends up unreadable in the client that half-applies it. Light with real
    contrast survives inversion; a dark design that gets partly inverted does not.

    Expects: `$site` (SiteSettings), `$preview` (the inbox preview line), and a
    `content` section.
--}}
@php
    /*
     * The palette, mirroring `public/css/style.css` so an email reads as the same
     * company as the site it came from. Written as literals rather than tokens because
     * a `var()` in an email is a blank value in Outlook.
     */
    $accent = '#FF6B11';
    $ink = '#1C1917';
    $muted = '#78716C';
    $line = '#E7E5E4';
    $card = '#FFFFFF';
    $ground = '#F5F4F2';

    $logo = $site->logo();
    $name = $site->siteName();
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $languages->direction() }}">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="x-apple-disable-message-reformatting" />
    <meta name="color-scheme" content="light" />
    <meta name="supported-color-schemes" content="light" />
    <title>{{ $subject ?? $name }}</title>

    <style>
        /* The breakpoint. An inline attribute cannot carry a media query, and without
           this the two-column rows stack badly on a phone. */
        @media only screen and (max-width: 620px) {
            .wrap { width: 100% !important; }
            .pad { padding-left: 20px !important; padding-right: 20px !important; }
            .stack-cell { display: block !important; width: 100% !important; text-align: left !important; }
            .stack-cell + .stack-cell { padding-top: 4px !important; }
            .h1 { font-size: 24px !important; }
        }

        /* Some clients underline and recolour anything that looks like a phone number
           or an address. These are the accepted incantations for stopping it. */
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        .no-detect a { color: inherit !important; text-decoration: none !important; }
    </style>
</head>
<body style="margin:0; padding:0; background-color:{{ $ground }}; -webkit-font-smoothing:antialiased;">

    {{-- The inbox preview line, then enough whitespace that the client does not pull
         the first words of the email in after it. --}}
    <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:{{ $ground }};">
        {{ $preview ?? '' }}
        {!! str_repeat('&#847;&zwnj;&nbsp;', 60) !!}
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ $ground }};">
        <tr>
            <td align="center" style="padding:24px 12px 40px 12px;">

                <table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">

                    {{-- The card. A 3px accent rule along the top is the whole brand
                         gesture: it survives every client, image blocking included. --}}
                    <tr>
                        <td style="background-color:{{ $accent }}; font-size:0; line-height:0; height:3px; border-radius:12px 12px 0 0;">&nbsp;</td>
                    </tr>

                    {{--
                        The brand row, INSIDE the card rather than floating above it.

                        It used to sit on the grey with the logo alone, which read as an
                        orphaned app icon rather than a masthead — and it was sized by
                        WIDTH, so the client's square 740×768 icon came out as a 132px
                        block that dominated the email. Gmail, 2026-08-31.

                        Constrained by HEIGHT now, which is what the website itself does
                        with the same file (`.brand-logo` is `height: 38px; width: auto`).
                        A height attribute with no width is the one form Outlook scales
                        proportionally, so a square icon and a wide wordmark both land at
                        40px tall — and `max-width` stops a very wide one from pushing
                        the name off the row.
                    --}}
                    <tr>
                        <td class="pad" style="background-color:{{ $card }}; padding:20px 32px; border:1px solid {{ $line }}; border-top:0; border-bottom:0;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    @if ($logo)
                                        <td valign="middle" style="padding-{{ $languages->direction() === 'rtl' ? 'left' : 'right' }}:12px;">
                                            <img src="{{ $logo }}" height="40" alt="{{ $name }}"
                                                 style="display:block; height:40px; width:auto; max-width:190px; border:0; outline:none; text-decoration:none;" />
                                        </td>
                                    @endif

                                    {{-- Always printed, not only as a fallback: most
                                         clients block images until the reader allows
                                         them, and alt text alone leaves the masthead
                                         looking broken rather than plain. --}}
                                    <td valign="middle" style="font-family:Helvetica,Arial,sans-serif; font-size:16px; font-weight:700; color:{{ $ink }}; letter-spacing:-0.2px; line-height:1.3;">
                                        {{ $name }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="pad" style="background-color:{{ $card }}; padding:0 32px;  border-left:1px solid {{ $line }}; border-right:1px solid {{ $line }};">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr><td style="height:1px; font-size:0; line-height:0; background-color:{{ $line }};">&nbsp;</td></tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="pad" style="background-color:{{ $card }}; padding:30px 32px 34px 32px; border:1px solid {{ $line }}; border-top:0; border-radius:0 0 12px 12px;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer. Every line is conditional, exactly as the site's own
                         footer is: an empty setting means the line is absent rather than
                         printed with nothing after it. --}}
                    <tr>
                        <td class="pad no-detect" align="center" style="padding:24px 32px 0 32px; font-family:Helvetica,Arial,sans-serif; font-size:12.5px; line-height:1.7; color:{{ $muted }};">
                            @php
                                $bits = array_filter([$site->email(), $site->phone(), $site->address()]);
                            @endphp

                            @foreach ($bits as $bit)
                                <div>{{ $bit }}</div>
                            @endforeach

                            <div style="padding-top:12px; color:{{ $muted }};">
                                &copy; {{ date('Y') }} {{ $name }}
                            </div>

                            {{-- Said plainly. This is a transactional email — a receipt
                                 for something the reader did — so there is no
                                 unsubscribe link and it would be dishonest to imply one
                                 stops it. --}}
                            <div style="padding-top:10px; font-size:11.5px; color:{{ $muted }};">
                                {{ __('lang.email_transactional_note') }}
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>

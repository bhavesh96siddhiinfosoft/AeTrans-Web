{{--
    A button, drawn as a table.

    An `<a>` with padding is a text link in Outlook, which renders through Word and
    ignores padding on inline elements. A one-cell table with the background on the CELL
    is the shape that comes out as a button everywhere — the `mso-` line is what stops
    Word adding its own leading inside it.

    Expects `$url` and `$label`. `$tone` is 'accent' (default) or 'quiet'.
--}}
@php
    $accent = '#FF6B11';
    $quiet = ($tone ?? 'accent') === 'quiet';
@endphp

<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0 0;">
    <tr>
        <td align="center"
            style="background-color:{{ $quiet ? '#FFFFFF' : $accent }};
                   border:1px solid {{ $quiet ? '#D6D3D1' : $accent }};
                   border-radius:8px; mso-padding-alt:13px 26px;">
            <a href="{{ $url }}"
               style="display:inline-block; padding:13px 26px;
                      font-family:Helvetica,Arial,sans-serif; font-size:14.5px; font-weight:700;
                      color:{{ $quiet ? '#1C1917' : '#FFFFFF' }}; text-decoration:none; line-height:1;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>

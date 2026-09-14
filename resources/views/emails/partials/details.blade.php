{{--
    A label/value list — what was booked, in the order a reader wants it.

    Two cells per row rather than a `<dl>`: definition lists collapse to one column in
    several clients, which puts the label and the value on the same line with no gap and
    reads as one run-on sentence.

    On a phone `.stack-cell` turns them into stacked blocks (see the layout's one media
    query), because 35% of a 320px screen is not a column, it is a hyphenation accident.

    Expects `$rows` as `['Label' => 'value', ...]`. Empty values are dropped, so a
    caller can hand over every possible field and let the data decide what prints.
--}}
@php
    $muted = '#78716C';
    $ink = '#1C1917';
    $line = '#F0EEEC';
    $rows = array_filter($rows ?? [], fn ($value) => trim((string) $value) !== '');
    $end = $languages->direction() === 'rtl' ? 'left' : 'right';
@endphp

@if ($rows)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0 0;">
        @foreach ($rows as $label => $value)
            <tr>
                <td class="stack-cell" width="38%" valign="top"
                    style="padding:11px 12px 11px 0; border-top:1px solid {{ $line }};
                           font-family:Helvetica,Arial,sans-serif; font-size:13px; line-height:1.5; color:{{ $muted }};">
                    {{ $label }}
                </td>
                <td class="stack-cell" width="62%" valign="top" align="{{ $end }}"
                    style="padding:11px 0 11px 0; border-top:1px solid {{ $line }};
                           font-family:Helvetica,Arial,sans-serif; font-size:14px; line-height:1.5; color:{{ $ink }};">
                    {!! nl2br(e($value)) !!}
                </td>
            </tr>
        @endforeach
    </table>
@endif

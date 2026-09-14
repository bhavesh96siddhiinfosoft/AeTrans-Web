{{--
    One social mark, drawn inline.

    Inline SVG rather than an icon font or a sprite: four glyphs do not justify a
    download, they inherit `currentColor` so they follow the theme for free, and there
    is no flash of a missing icon.

    The four are exactly the ones the panel offers (settings/socialLinks). A platform
    with no glyph here still gets a link — a labelled dot rather than nothing — so
    adding one to the panel cannot leave a blank hole on the page.
--}}
@switch($platform)
    @case('whatsapp')
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12.04 2C6.6 2 2.2 6.4 2.2 11.84c0 1.74.46 3.44 1.32 4.94L2.1 22l5.34-1.4a9.8 9.8 0 0 0 4.6 1.17h.01c5.43 0 9.84-4.4 9.84-9.84 0-2.63-1.02-5.1-2.88-6.96A9.77 9.77 0 0 0 12.04 2zm0 18.02h-.01a8.2 8.2 0 0 1-4.16-1.14l-.3-.18-3.1.81.83-3.02-.2-.31a8.14 8.14 0 0 1-1.25-4.34c0-4.5 3.68-8.17 8.2-8.17a8.15 8.15 0 0 1 8.18 8.18c0 4.5-3.67 8.17-8.19 8.17zm4.5-6.12c-.25-.13-1.46-.72-1.68-.8-.23-.08-.39-.12-.55.13-.17.24-.64.8-.78.96-.14.17-.29.19-.53.06-.25-.12-1.04-.38-1.98-1.22-.73-.65-1.22-1.46-1.37-1.7-.14-.25-.01-.38.11-.5.11-.12.25-.29.37-.44.12-.15.16-.25.25-.42.08-.16.04-.31-.02-.44-.06-.12-.55-1.33-.76-1.82-.2-.48-.4-.41-.55-.42h-.47c-.16 0-.42.06-.64.31-.22.25-.84.82-.84 2s.86 2.32.98 2.48c.12.17 1.7 2.6 4.12 3.64.57.25 1.02.4 1.37.5.58.19 1.1.16 1.52.1.46-.07 1.46-.6 1.67-1.18.2-.58.2-1.07.15-1.18-.06-.1-.22-.16-.47-.28z"/>
        </svg>
        @break

    @case('facebook')
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06c0 5.02 3.66 9.18 8.44 9.94v-7.03H7.9v-2.91h2.54V9.85c0-2.52 1.49-3.91 3.77-3.91 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.89h2.78l-.45 2.91h-2.33V22c4.78-.76 8.44-4.92 8.44-9.94z"/>
        </svg>
        @break

    @case('tiktok')
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M16.6 5.82A4.28 4.28 0 0 1 15.54 3h-3.09v12.4a2.59 2.59 0 0 1-2.59 2.5 2.59 2.59 0 0 1 0-5.18c.27 0 .53.04.78.12v-3.2a5.86 5.86 0 0 0-.78-.05A5.78 5.78 0 0 0 4.08 15.4 5.78 5.78 0 0 0 9.86 21a5.78 5.78 0 0 0 5.78-5.78V9.01a7.35 7.35 0 0 0 4.28 1.38V7.3a4.28 4.28 0 0 1-3.32-1.48z"/>
        </svg>
        @break

    @case('telegram')
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm4.64 6.86-1.55 7.34c-.12.52-.42.65-.86.4l-2.38-1.75-1.15 1.1c-.13.13-.24.24-.48.24l.17-2.42 4.4-3.98c.2-.17-.04-.26-.29-.1L9.06 12.1l-2.34-.73c-.51-.16-.52-.51.11-.76l9.15-3.53c.42-.15.79.1.66.78z"/>
        </svg>
        @break

    @default
        <span class="site-social-dot" aria-hidden="true"></span>
@endswitch

{{--
    Everything the <head> needs.

    The order the spec asks for (§10) is: the page's own value first, then Global
    Settings, then nothing at all. `$title` and `$description` are what a page passes
    in; the rest is the admin's.

    This is the reason the site is server-rendered. Facebook and WhatsApp crawlers run
    no JavaScript, and WhatsApp is how a link travels in Indonesia — a tag written by a
    script is an empty grey preview card.

    ── THE PER-PAGE `seo` BLOCK ────────────────────────────────────────────────

    A CMS page carries its own `seo` map, written on the panel's SEO tab: title, meta
    description, canonical, robots, schema, Open Graph and Twitter. It arrives here as
    `$seo` because Blade hands a child view's data to its layout, and every other page
    on the site simply has none — hence `?? []` throughout rather than a second partial.

    EVERY FIELD IS OPTIONAL AND EMPTY MEANS ABSENT. The panel saves the whole map
    whether or not the admin filled it in, so `title: ""` means "not set"; treating it
    as a real value would blank out the fallback it is supposed to defer to.

    Not wired: `seo.sitemap` (include / priority / changefreq). It describes a
    `sitemap.xml` this site does not serve yet, and there is nowhere honest to put it in
    a `<head>`. It belongs with that file when it is built.
--}}
@php
    $seo = is_array($seo ?? null) ? $seo : [];

    /** The panel writes every key, so "" and null both mean the admin left it blank. */
    $pageSeo = function (string $key, ...$path) use ($seo) {
        $value = $seo[$key] ?? null;

        foreach ($path as $step) {
            $value = is_array($value) ? ($value[$step] ?? null) : null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    };

    $seoTitle = $pageSeo('title') ?? $title ?? $site->metaTitle();
    $seoDescription = $pageSeo('metaDescription') ?? $description ?? $site->metaDescription();

    // The social image falls through three sources before the site default: the page's
    // own OG image, then the picture the admin attached to the page, then Twitter's.
    $seoImage = $pageSeo('openGraph', 'image')
        ?? (isset($page) && is_array($page) && trim((string) ($page['featuredImage'] ?? '')) !== '' ? trim((string) $page['featuredImage']) : null)
        ?? $pageSeo('twitter', 'image')
        ?? $image
        ?? $site->ogImage();

    /*
     * Query strings are dropped: two URLs that differ only by `?ref=` are one page, and
     * telling search engines otherwise splits it in two. A page may name its own
     * canonical, which is how the admin points a duplicate at the original.
     */
    $canonical = $pageSeo('canonicalUrl') ?? url()->current();

    $ogTitle = $pageSeo('openGraph', 'title') ?? $seoTitle;
    $ogDescription = $pageSeo('openGraph', 'description') ?? $seoDescription;
    $ogType = $pageSeo('openGraph', 'type') ?? 'website';

    $twitterTitle = $pageSeo('twitter', 'title') ?? $seoTitle;
    $twitterDescription = $pageSeo('twitter', 'description') ?? $seoDescription;

    /*
     * `robots`. Built only when the page carries the block at all, because the DEFAULT
     * — no tag — already means index, follow: emitting "index, follow" everywhere says
     * nothing and adds a line to every page on the site.
     *
     * The negatives are what earn a tag. `maxSnippet: -1` and `maxVideoPreview: -1`
     * mean "no limit", which is also the default, so they are only printed when the
     * admin set a real number.
     */
    $robots = [];
    $robotRules = is_array($seo['robots'] ?? null) ? $seo['robots'] : [];

    if ($robotRules !== []) {
        $robots[] = ($robotRules['index'] ?? true) ? 'index' : 'noindex';
        $robots[] = ($robotRules['follow'] ?? true) ? 'follow' : 'nofollow';

        foreach (['archive' => 'noarchive', 'snippet' => 'nosnippet', 'imageIndex' => 'noimageindex'] as $key => $directive) {
            if (($robotRules[$key] ?? true) === false) {
                $robots[] = $directive;
            }
        }

        foreach (['maxSnippet' => 'max-snippet', 'maxVideoPreview' => 'max-video-preview'] as $key => $directive) {
            $limit = $robotRules[$key] ?? -1;

            if (is_numeric($limit) && (int) $limit >= 0) {
                $robots[] = $directive.':'.(int) $limit;
            }
        }

        if ($preview = trim((string) ($robotRules['maxImagePreview'] ?? ''))) {
            $robots[] = 'max-image-preview:'.$preview;
        }
    }

    /*
     * The JSON-LD, built here rather than beside the tag it prints.
     *
     * TWO Blade traps, both of which fail at render time on a page nobody reloads
     * twice. An array literal inside `@json(...)` breaks the directive parser
     * ("Unclosed '['"), and a SECOND `@php` block further down this file does not
     * compile at all — Blade pairs the raw blocks across the whole document, and the
     * inline `@php(...)` between them is enough to lose the pairing. One block, at
     * the top, and every tag below it is a plain echo.
     */
    $schemaType = $pageSeo('schema', 'pageType');
    $schema = $schemaType && $schemaType !== 'None' ? array_filter([
        '@context' => 'https://schema.org',
        '@type' => $schemaType,
        'name' => $seoTitle,
        'url' => $canonical,
        'description' => $seoDescription,
    ]) : null;

    $keywords = $pageSeo('keywords') ?? $site->metaKeywords();
@endphp

<title>{{ $seoTitle }}</title>

@if ($seoDescription)
    <meta name="description" content="{{ $seoDescription }}">
@endif

@if ($keywords)
    <meta name="keywords" content="{{ $keywords }}">
@endif

@if ($robots)
    <meta name="robots" content="{{ implode(', ', $robots) }}">
@endif

<link rel="canonical" href="{{ $canonical }}">

{{-- No `hreflang`. It names the URL of each translation, and every language now shares
     ONE URL — the client's decision of 2026-08-20 (see SetLocale). Emitting tags that
     all point at this same address would tell a search engine something untrue. If the
     languages ever get their own URLs again, they belong here. --}}

{{-- Open Graph — what WhatsApp, Facebook and most chat apps read. --}}
<meta property="og:type" content="{{ $ogType }}">
<meta property="og:site_name" content="{{ $site->siteName() }}">
<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:url" content="{{ $canonical }}">
@if ($ogDescription)
    <meta property="og:description" content="{{ $ogDescription }}">
@endif
@if ($seoImage)
    <meta property="og:image" content="{{ $seoImage }}">

    @if ($imageAlt = $pageSeo('openGraph', 'imageAlt'))
        <meta property="og:image:alt" content="{{ $imageAlt }}">
    @endif
@endif

{{-- `summary_large_image` only when there IS an image; the large card with none is a
     blank rectangle, and the small card degrades better. --}}
<meta name="twitter:card" content="{{ $seoImage ? ($pageSeo('twitter', 'card') ?? 'summary_large_image') : 'summary' }}">
<meta name="twitter:title" content="{{ $twitterTitle }}">
@if ($twitterDescription)
    <meta name="twitter:description" content="{{ $twitterDescription }}">
@endif
@if ($seoImage)
    <meta name="twitter:image" content="{{ $seoImage }}">
@endif

{{-- The page's own schema type, as JSON-LD. `AboutPage` and `ContactPage` are what the
     two live pages are set to, and Google reads them to understand what a page IS
     rather than merely what it says. `None` is the panel's way of saying "no schema". --}}
@if ($schema)
    <script type="application/ld+json">
        {!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}
    </script>
@endif

@if ($favicon = $site->favicon())
    <link rel="icon" href="{{ $favicon }}">
@else
    <link rel="icon" href="{{ asset('favicon.ico') }}">
@endif

{{-- Firebase Storage serves every logo and picture on the page; the handshake is worth
     starting before the parser reaches the first <img>. --}}
<link rel="preconnect" href="https://firebasestorage.googleapis.com" crossorigin>

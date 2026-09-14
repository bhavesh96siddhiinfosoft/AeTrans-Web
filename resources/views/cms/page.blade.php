{{--
    A page written in the panel's CMS.

    Nothing on this screen is authored here: the heading, the body and the meta tags
    are all the admin's, and the file's only job is to decide where they go and how
    they are typed. Adding a page in the panel puts it on the site and in the footer
    with no deploy.

    ── THE BODY IS PRINTED UNESCAPED, AND THAT IS THE DECISION ─────────────────

    `description` is rich-text HTML from the panel's editor — headings, lists and links
    the admin laid out deliberately — so escaping it would print the tags on the page
    and make the feature useless. The trust boundary is therefore the PANEL: anyone who
    can edit a CMS page can put script on this site.

    That is what a CMS is, and it is worth saying out loud rather than leaving to be
    discovered. It is also why it is not sanitised here — a filter that strips `<script>`
    but leaves `<iframe>` and `on*` attributes buys a false sense of safety, and one that
    strips everything dangerous also strips the embeds a Contact page legitimately wants.
    The real control is who holds a panel login.
--}}
@extends('layouts.public')

@section('title', ($seo['title'] ?? '') ?: ($page['name'] ?? ''))

@section('content')
<article class="cms-page">
    <div class="cms-page-inner">
        <header class="cms-page-head">
            <h1 class="cms-page-title">{{ $page['name'] ?? '' }}</h1>

            @if ($excerpt = trim((string) ($page['excerpt'] ?? '')))
                <p class="cms-page-lede">{{ $excerpt }}</p>
            @endif
        </header>

        {{-- See the note at the top of this file before changing this to `{{ }}`. --}}
        <div class="cms-body">
            {!! $page['description'] ?? '' !!}
        </div>
    </div>
</article>
@endsection

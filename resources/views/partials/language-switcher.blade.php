{{--
    The language switcher.

    Each option is a POST, not a link: the language lives in a cookie and the address
    never changes, so there is nothing to link TO. A GET would have to put `?lang=id`
    in the address bar, which is exactly what this design exists to avoid.

    The current path travels with the form so the visitor comes back to the page they
    were reading rather than the front page. It is checked as a local path before being
    redirected to — see LanguageController.

    Only enabled languages appear, and the whole control is dropped when there is only
    one: a switcher with a single option is furniture.

    A checkbox and a label rather than <details>, matching the site menu: browsers
    disagree about what a closed <details> does to its contents, and this panel rendered
    permanently OPEN when it was built that way.
--}}
@php
    $available = $languages->enabled();
    $current = app()->getLocale();
    // Path and query only — never the host, so it cannot become an off-site redirect.
    $return = '/'.ltrim(request()->getRequestUri(), '/');
@endphp

@if (count($available) > 1)
    <div class="lang">
        <input type="checkbox" id="lang-menu" class="lang-input">

        <label for="lang-menu" class="lang-toggle" aria-label="{{ __('lang.change_language') }}">
            @if ($flag = $available[$current]['image'] ?? null)
                <img src="{{ $flag }}" alt="" class="lang-flag" width="20" height="14" loading="lazy">
            @endif
            <span class="lang-code">{{ strtoupper($current) }}</span>
        </label>

        <div class="lang-panel">
            @foreach ($available as $code => $language)
                <form method="POST" action="{{ route('language.update') }}">
                    @csrf
                    <input type="hidden" name="code" value="{{ $code }}">
                    <input type="hidden" name="return" value="{{ $return }}">

                    <button type="submit"
                            class="lang-option {{ $code === $current ? 'is-active' : '' }}"
                            lang="{{ $code }}"
                            @if ($code === $current) aria-current="true" @endif>
                        @if ($language['image'])
                            <img src="{{ $language['image'] }}" alt="" class="lang-flag" width="20" height="14" loading="lazy">
                        @endif
                        {{ $language['name'] }}
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@endif

{{--
    Where the customer is in the booking, and how much is left.

    Shown on every step because a form with no visible end is a form people abandon —
    especially on a phone, where only one question fits on screen at a time.

    Completed steps are links back. Steps ahead are not: they would render half-empty
    and the answer they need has not been given yet.

    `$steps` is a list of ['label' => …, 'route' => …|null], and `$current` the index of
    the one being shown, counting from 1.
--}}
<ol class="steps-bar" aria-label="{{ __('lang.booking_steps') }}">
    @foreach ($steps as $index => $step)
        @php $number = $index + 1; @endphp

        <li class="steps-bar-item {{ $number === $current ? 'is-current' : '' }} {{ $number < $current ? 'is-done' : '' }}"
            @if ($number === $current) aria-current="step" @endif>
            @if ($number < $current && ! empty($step['route']))
                <a href="{{ route($step['route']) }}">
                    <span class="steps-bar-number">{{ $number }}</span>
                    <span class="steps-bar-label">{{ $step['label'] }}</span>
                </a>
            @else
                <span class="steps-bar-number">{{ $number }}</span>
                <span class="steps-bar-label">{{ $step['label'] }}</span>
            @endif
        </li>
    @endforeach
</ol>

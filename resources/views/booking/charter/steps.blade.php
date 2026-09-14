{{--
    The four steps of a charter booking, named once.

    Repeated inline in each step until 2026-08-26, which meant renaming a step in four
    files and, the first time, renaming it in three.
--}}
@include('booking.partials.steps', [
    'current' => $current,
    'steps' => [
        ['label' => __('lang.step_route'), 'route' => 'book.charter'],
        ['label' => __('lang.step_vehicle_dates'), 'route' => 'book.charter.vehicle'],
        ['label' => __('lang.your_details'), 'route' => 'book.charter.details'],
        ['label' => __('lang.done'), 'route' => null],
    ],
])

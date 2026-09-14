{{--
    The four steps of a shuttle booking, named once — see booking/charter/steps.blade.php.
--}}
@include('booking.partials.steps', [
    'current' => $current,
    'steps' => [
        ['label' => __('lang.step_route'), 'route' => 'book.shuttle'],
        ['label' => __('lang.date_and_seats'), 'route' => 'book.shuttle.trip'],
        ['label' => __('lang.your_details'), 'route' => 'book.shuttle.details'],
        ['label' => __('lang.done'), 'route' => null],
    ],
])

<?php

declare(strict_types=1);

return [
    /*
    | Which AdvisorAssignmentStrategy the container hands out: "least_booked" (default,
    | balances the workload) or "random". Switch with ASSIGNMENT_STRATEGY in .env.
    */
    'assignment_strategy' => env('ASSIGNMENT_STRATEGY', 'least_booked'),

    /*
    | How long computed availability for one (branch, service, date) is cached, in seconds.
    | Booking changes clear it immediately; this only bounds staleness for other changes
    | (shifts, leave, opening hours).
    */
    'availability_cache_seconds' => (int) env('AVAILABILITY_CACHE_SECONDS', 60),

    /*
    | Send the Strict-Transport-Security header over HTTPS. Leave off until the site is
    | permanently HTTPS-only: browsers remember the setting for a year.
    */
    'hsts' => (bool) env('ENABLE_HSTS', false),
];

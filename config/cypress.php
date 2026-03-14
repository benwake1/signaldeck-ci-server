<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cypress Job Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum number of seconds a Cypress test run job may execute before
    | it is killed by the queue worker. This must be less than or equal to
    | the --timeout value configured on the queue:work supervisor command.
    |
    | Default: 10800 (3 hours). Increase for very long-running test suites.
    |
    */

    'job_timeout' => env('CYPRESS_JOB_TIMEOUT', 10800),

];

<?php

use Laravel\Pulse\Http\Middleware\Authorize;
use Laravel\Pulse\Recorders;

return [
    'enabled' => (bool) env('PULSE_ENABLED', false)
        && (app()->runningInConsole() || in_array(request()->ip(), ['127.0.0.1', '::1'], true))
        && in_array(env('APP_ENV'), ['local', 'testing'], true)
        && env('DB_CONNECTION') === 'pgsql'
        && in_array(env('DB_HOST'), ['127.0.0.1', 'localhost'], true)
        && (string) env('DB_PORT') === '55461'
        && env('DB_DATABASE') === 'erp_elec_new_test'
        && str_starts_with(str_replace('\\', '/', (string) env('E2E_APP_STORAGE')), str_replace('\\', '/', base_path('.tmp')).'/'),
    'middleware' => ['web', 'auth', 'verified', Authorize::class],
    'storage' => [
        'driver' => 'database',
        'trim' => ['keep' => '1 day'],
        'database' => ['connection' => 'pgsql', 'chunk' => 1000],
    ],
    'recorders' => [
        Recorders\SlowRequests::class => ['enabled' => true, 'sample_rate' => 1, 'threshold' => 1000],
        Recorders\SlowQueries::class => [
            'enabled' => true, 'sample_rate' => 1, 'threshold' => 50,
            'location' => true, 'max_query_length' => 500,
            'ignore' => ['/(["`])pulse_[\w]+?\1/'],
        ],
        Recorders\Exceptions::class => ['enabled' => true, 'sample_rate' => 1, 'location' => true],
    ],
];

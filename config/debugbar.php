<?php

return [
    // Profiling is opt-in and restricted to the isolated synthetic database.
    'enabled' => (bool) env('DEBUGBAR_ENABLED', false)
        && (app()->runningInConsole() || in_array(request()->ip(), ['127.0.0.1', '::1'], true))
        && env('APP_ENV') === 'local'
        && env('DB_CONNECTION') === 'pgsql'
        && in_array(env('DB_HOST'), ['127.0.0.1', 'localhost'], true)
        && (string) env('DB_PORT') === '55461'
        && env('DB_DATABASE') === 'erp_elec_new_test'
        && str_starts_with(str_replace('\\', '/', (string) env('E2E_APP_STORAGE')), str_replace('\\', '/', base_path('.tmp')).'/'),
    'force_allow_enable' => false,
    'collect_jobs' => false,
    'collectors' => ['time' => true, 'memory' => true, 'db' => true],
    'options' => [
        'db' => [
            'with_params' => false,
            'backtrace' => 2,
            'soft_limit' => 5000,
            'hard_limit' => 5000,
            'explain' => false,
            'show_query_result' => false,
            'timeline' => false,
            'only_slow_queries' => false,
        ],
    ],
    'storage' => [
        'enabled' => true,
        'open' => false,
        'driver' => 'file',
        'path' => storage_path('debugbar'),
    ],
    'capture_ajax' => true,
];

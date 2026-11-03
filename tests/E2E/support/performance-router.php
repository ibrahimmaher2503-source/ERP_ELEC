<?php

$root = dirname(__DIR__, 3);
$public = $root.'/public';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path !== '/' && is_file($public.$path)) return false;

require $root.'/vendor/autoload.php';
// PHP's built-in server exposes inherited variables through getenv(), but may
// not populate them into the arrays Laravel checks when selecting storagePath.
if (($isolatedStorage = getenv('LARAVEL_STORAGE_PATH')) !== false && $isolatedStorage !== '') {
    $_ENV['LARAVEL_STORAGE_PATH'] = $isolatedStorage;
    $_SERVER['LARAVEL_STORAGE_PATH'] = $isolatedStorage;
}
$app = require $root.'/bootstrap/app.php';
$started = microtime(true);
$queries = [];
$requestId = preg_replace('/[^a-zA-Z0-9._-]/', '', $_SERVER['HTTP_X_AUDIT_REQUEST_ID'] ?? '');
$app->booted(function () use (&$queries, $requestId): void {
    if ($requestId === '') return;
    \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
        $sql = preg_replace('/\s+/', ' ', trim($query->sql));
        $fingerprint = hash('sha256', strtolower($sql));
        $queries[] = ['fingerprint' => $fingerprint, 'sql' => mb_substr($sql, 0, 500), 'duration_ms' => round($query->time, 2)];
    });
});
$app->handleRequest(\Illuminate\Http\Request::capture());
if ($requestId !== '' && ($log = getenv('AUDIT_QUERY_LOG'))) {
    $byFingerprint = [];
    foreach ($queries as $query) {
        $key = $query['fingerprint'];
        $byFingerprint[$key] ??= ['sql' => $query['sql'], 'count' => 0, 'total_ms' => 0.0];
        $byFingerprint[$key]['count']++;
        $byFingerprint[$key]['total_ms'] += $query['duration_ms'];
    }
    $repeated = array_values(array_filter($byFingerprint, static fn ($query) => $query['count'] > 1));
    usort($repeated, static fn ($a, $b) => $b['count'] <=> $a['count']);
    $slow = $queries;
    usort($slow, static fn ($a, $b) => $b['duration_ms'] <=> $a['duration_ms']);
    $record = ['request_id' => $requestId, 'path' => $path, 'query_count' => count($queries),
        'database_time_ms' => round(array_sum(array_column($queries, 'duration_ms')), 2),
        'repeated_queries' => $repeated, 'slow_queries' => array_slice($slow, 0, 10),
        'logged_at' => gmdate('c'), 'elapsed_server_ms' => round((microtime(true) - $started) * 1000, 2)];
    file_put_contents($log, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL, FILE_APPEND | LOCK_EX);
}

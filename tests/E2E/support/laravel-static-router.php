<?php

if (($isolatedStorage = getenv('LARAVEL_STORAGE_PATH')) !== false && $isolatedStorage !== '') {
    $_ENV['LARAVEL_STORAGE_PATH'] = $isolatedStorage;
    $_SERVER['LARAVEL_STORAGE_PATH'] = $isolatedStorage;
}
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = dirname(__DIR__, 3).'/public'.(str_starts_with($path, '/') ? $path : '/'.$path);
if ($path !== '/' && is_file($file)) {
    return false;
}
require dirname(__DIR__, 3).'/public/index.php';

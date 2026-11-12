<?php

use App\Support\DefaultAvatar;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

function verify(bool $result, string $message): void
{
    if (! $result) {
        throw new RuntimeException($message);
    }
    echo "PASS {$message}\n";
}

$malePool = [1, 3, 5, 7, 9, 11, 13, 15];
$femalePool = [2, 4, 6, 8, 10, 12, 14];
$maleUsed = $femaleUsed = [];
$sameOrigin = $validPools = true;
$appOrigin = parse_url(config('app.url'));

for ($id = 1; $id <= 40; $id++) {
    foreach (['male' => $malePool, 'female' => $femalePool] as $gender => $pool) {
        $url = DefaultAvatar::url($id, $gender);
        $asset = parse_url($url);
        $filename = basename($asset['path'] ?? '');
        preg_match('/^avatar-(\d{2})\.webp$/', $filename, $matches);
        $number = isset($matches[1]) ? (int) $matches[1] : 0;
        $validPools = $validPools && in_array($number, $pool, true) && $url === DefaultAvatar::url($id, $gender);
        $sameOrigin = $sameOrigin && ($asset['scheme'] ?? null) === ($appOrigin['scheme'] ?? null)
            && ($asset['host'] ?? null) === ($appOrigin['host'] ?? null)
            && ($asset['port'] ?? null) === ($appOrigin['port'] ?? null);
        ${$gender.'Used'}[$number] = true;
    }
}
verify($validPools, 'male and female user IDs 1-40 map stably to their assigned portrait pools');
verify(count($maleUsed) === count($malePool) && count(array_diff($malePool, array_keys($maleUsed))) === 0
    && count($femaleUsed) === count($femalePool) && count(array_diff($femalePool, array_keys($femaleUsed))) === 0,
    'both gender pools are used across the tested IDs');
verify($sameOrigin, 'gendered portrait URLs use the local app origin');

for ($id = 1; $id <= 15; $id++) {
    $path = base_path(sprintf('public/images/avatars/avatar-%02d.webp', $id));
    $size = is_file($path) ? filesize($path) : false;
    $image = is_file($path) ? getimagesize($path) : false;
    verify($size !== false && $size <= 10240 && $image !== false && $image[0] === 96 && $image[1] === 96 && $image[2] === IMAGETYPE_WEBP,
        sprintf('avatar-%02d.webp exists as WebP 96x96 at no more than 10 KB', $id));
}

$neutral = DefaultAvatar::url(1, null);
$neutralParts = parse_url($neutral);
$neutralPath = base_path('public/images/avatars/neutral.svg');
$neutralContent = is_file($neutralPath) ? file_get_contents($neutralPath) : false;
verify(basename($neutralParts['path'] ?? '') === 'neutral.svg' && $neutral === DefaultAvatar::url(40, null), 'legacy null gender maps consistently to the neutral portrait');
verify(($neutralParts['scheme'] ?? null) === ($appOrigin['scheme'] ?? null)
    && ($neutralParts['host'] ?? null) === ($appOrigin['host'] ?? null)
    && ($neutralParts['port'] ?? null) === ($appOrigin['port'] ?? null), 'neutral portrait URL uses the local app origin');
verify(is_file($neutralPath) && filesize($neutralPath) <= 10240 && is_string($neutralContent) && preg_match('/<svg\b/i', $neutralContent) === 1,
    'neutral.svg exists as a local SVG no larger than 10 KB');

<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(ConsoleKernel::class)->bootstrap();

if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Module route authorization check requires the exact separate PostgreSQL test database.');
}

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    fwrite(STDOUT, "PASS {$message}\n");
};

$requestAs = static function (?User $user, string $path): Response {
    Auth::forgetGuards();
    if ($user !== null) {
        Auth::guard('web')->setUser($user);
    }

    config(['session.driver' => 'array']);
    $request = Request::create($path, 'GET');
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);

    try {
        return $response;
    } finally {
        $kernel->terminate($request, $response);
    }
};

$protectedRoutes = [
    'M01 company' => '/organization/company',
    'M01 users' => '/organization/users',
    'M01 roles' => '/organization/roles',
    'M02 products' => '/products',
    'M03 customers' => '/customers',
    'M03 suppliers' => '/suppliers',
    'M04 quotations' => '/quotations',
    'M04 sales orders' => '/sales-orders',
    'M04 returns' => '/sales-returns',
];

DB::beginTransaction();

try {
    $unverified = User::factory()->create(['active' => true, 'email_verified_at' => null]);
    $verifiedWithoutPermissions = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $loginPath = route('login', [], false);
    $verificationPath = route('verification.notice', [], false);

    foreach ($protectedRoutes as $module => $path) {
        $anonymousResponse = $requestAs(null, $path);
        $anonymousLocation = parse_url((string) $anonymousResponse->headers->get('Location'), PHP_URL_PATH);
        $verify($anonymousResponse->getStatusCode() === 302 && $anonymousLocation === $loginPath,
            "SC-MODULE-AUTH-001 {$module} redirects anonymous requests to login");

        $unverifiedResponse = $requestAs($unverified, $path);
        $unverifiedLocation = parse_url((string) $unverifiedResponse->headers->get('Location'), PHP_URL_PATH);
        $verify($unverifiedResponse->getStatusCode() === 302 && $unverifiedLocation === $verificationPath,
            "SC-MODULE-AUTH-001 {$module} requires verified email");

        $unauthorizedResponse = $requestAs($verifiedWithoutPermissions, $path);
        $verify(in_array($unauthorizedResponse->getStatusCode(), [403, 404], true),
            "SC-MODULE-AUTH-001 {$module} rejects verified users without module permission");
    }
} finally {
    Auth::forgetGuards();
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    fwrite(STDOUT, "Disposable module-route auth fixtures rolled back.\n");
}

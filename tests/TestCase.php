<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Bootstrap only after pinning database configuration to the disposable test database.
     * This runs before Laravel discovers RefreshDatabase and can issue any migrations.
     */
    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'erp_elec_new_test',
            'DB_URL' => '',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.testing');
        $app->make(Kernel::class)->bootstrap();

        // Do not let a cached URL or default connection redirect a destructive test trait.
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.driver', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', 'erp_elec_new_test');
        $app['config']->set('database.connections.pgsql.url', null);

        $connection = $app['db']->connection();
        if (! $app->environment('testing') || $connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== 'erp_elec_new_test') {
            throw new RuntimeException('Tests require APP_ENV=testing and the exact PostgreSQL database erp_elec_new_test.');
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

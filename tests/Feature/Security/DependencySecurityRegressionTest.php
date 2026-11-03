<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Tests\Fixtures\Security\AuthorizationRegressionComponent;

final class DependencySecurityRegressionTest extends TestCase
{
    public function test_mime_parameter_names_reject_crlf_and_non_token_characters(): void
    {
        $header = new ParameterizedHeader('Content-Disposition', 'attachment');

        foreach (["name\r\nX-Injected: yes", "name\nInjected", "bad name"] as $parameter) {
            try {
                $header->setParameter($parameter, 'value');
                $this->fail('Unsafe MIME parameter name was accepted.');
            } catch (RfcComplianceException) {
                $this->assertSame([], $header->getParameters());
            }
        }
    }

    public function test_livewire_authorize_uses_parameter_names_after_reordered_payload_keys(): void
    {
        AuthorizationRegressionComponent::$actionEffects = 0;
        AuthorizationRegressionComponent::$actionArguments = [];
        $gateCalls = 0;
        Gate::define('security-regression-reordered', function (User $user, $first, $second) use (&$gateCalls): bool {
            $gateCalls++;

            return false;
        });

        $component = Livewire::actingAs($this->actor())->test(AuthorizationRegressionComponent::class);

        // This is the raw HTTP update-call shape from the advisory: PHP preserves
        // the insertion order, while positional invocation and keyed auth disagree.
        $component->update(calls: [[
            'method' => 'reorderedAction',
            'params' => [1 => 'branch-B', 0 => 'record-A'],
            'path' => '',
        ]])->assertStatus(404);
        $this->assertSame(0, $gateCalls, 'Ambiguous integer parameter keys must be rejected before Gate authorization.');
        $this->assertSame(0, AuthorizationRegressionComponent::$actionEffects);

        $seen = null;
        Gate::define('security-regression-reordered', function (User $user, $first, $second) use (&$seen): bool {
            $seen = [$first, $second];

            return false;
        });

        Livewire::actingAs($this->actor())->test(AuthorizationRegressionComponent::class)
            ->update(calls: [[
                'method' => 'reorderedAction',
                'params' => ['second' => 'branch-B', 'first' => 'record-A'],
                'path' => '',
            ]])->assertStatus(403);
        $this->assertSame(['record-A', 'branch-B'], $seen);
        $this->assertSame(0, AuthorizationRegressionComponent::$actionEffects);

        $seen = null;
        Gate::define('security-regression-reordered', function (User $user, $first, $second) use (&$seen): bool {
            $seen = [$first, $second];

            return true;
        });
        Livewire::actingAs($this->actor())->test(AuthorizationRegressionComponent::class)
            ->update(calls: [[
                'method' => 'reorderedAction',
                'params' => ['second' => 'branch-B', 'first' => 'record-A'],
                'path' => '',
            ]]);
        $this->assertSame(['record-A', 'branch-B'], $seen);
        $this->assertSame(['record-A', 'branch-B'], AuthorizationRegressionComponent::$actionArguments);
        $this->assertSame(1, AuthorizationRegressionComponent::$actionEffects);
    }

    public function test_livewire_json_authorization_runs_before_the_method_side_effect(): void
    {
        AuthorizationRegressionComponent::$jsonEffects = 0;
        Gate::define('security-regression-json', fn (User $user) => false);

        Livewire::actingAs($this->actor())->test(AuthorizationRegressionComponent::class)
            ->call('jsonAction')->assertStatus(403);
        $this->assertSame(0, AuthorizationRegressionComponent::$jsonEffects);
    }

    public function createApplication()
    {
        foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'erp_elec_new_test', 'DB_URL' => ''] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->loadEnvironmentFrom('.env.testing');
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.database', 'erp_elec_new_test');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('app.debug', false);

        return $app;
    }

    private function actor(): User
    {
        $user = new User(['name' => 'Security regression actor', 'email' => 'security-regression@example.invalid']);
        $user->setAttribute($user->getKeyName(), 987654321);

        return $user;
    }
}

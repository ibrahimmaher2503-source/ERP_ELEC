<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LocalUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_account_is_created_with_a_hashed_password_and_verified_email(): void
    {
        $this->artisan('erp:create-local-user', ['email' => 'developer@example.test', '--name' => 'Developer'])
            ->expectsQuestion('Password (minimum 12 characters)', 'local-long-password')
            ->assertSuccessful();

        $user = User::where('email', 'developer@example.test')->sole();
        $this->assertTrue(Hash::check('local-long-password', $user->password));
        $this->assertTrue($user->hasVerifiedEmail());
    }

    public function test_local_account_command_is_unavailable_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('erp:create-local-user', ['email' => 'developer@example.test'])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_short_password_cannot_create_an_account(): void
    {
        $this->artisan('erp:create-local-user', ['email' => 'developer@example.test'])
            ->expectsQuestion('Password (minimum 12 characters)', 'short')
            ->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}

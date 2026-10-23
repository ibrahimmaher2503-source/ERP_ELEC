<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_cannot_create_an_erp_account(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Uninvited',
            'email' => 'uninvited@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'uninvited@example.com']);
    }

    public function test_operator_profile_does_not_offer_destructive_account_deletion(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('data-test="delete-user-button"', false);
    }

    public function test_dashboard_renders_arabic_rtl_without_business_totals(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('مساحة العمل')
            ->assertSee('لا توجد بيانات تشغيلية بعد');
    }
}

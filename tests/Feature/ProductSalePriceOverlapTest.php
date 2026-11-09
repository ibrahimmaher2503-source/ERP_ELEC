<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organization\Domain\Company;
use App\Modules\Products\Application\SalePrices;
use App\Modules\Products\Domain\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductSalePriceOverlapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_overlapping_sale_price_cannot_be_approved_and_remains_draft(): void
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }

        $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $approver = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('access_grants')->insert([
            ['user_id' => $approver->id, 'permission_code' => 'M02.price_approve', 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $product = Product::factory()->create();
        $common = ['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'amount' => '100.00000000', 'reference' => 'TEST-OVERLAP', 'created_by' => $maker->id, 'created_at' => now()];
        DB::table('product_sale_prices')->insert($common + ['starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()]);
        $draftId = DB::table('product_sale_prices')->insertGetId($common + ['starts_on' => '2026-11-15', 'ends_on' => '2026-12-15', 'status' => 'draft']);

        try {
            app(SalePrices::class)->decide($approver->id, $draftId, 'approve');
            $this->fail('An overlapping sale price was approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('price', $exception->errors());
        }

        $this->assertDatabaseHas('product_sale_prices', ['id' => $draftId, 'status' => 'draft', 'approved_by' => null]);
    }
}

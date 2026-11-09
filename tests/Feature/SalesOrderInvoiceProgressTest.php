<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Application\SalesOrderProgress;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderLine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesOrderInvoiceProgressTest extends TestCase
{
    use DatabaseTransactions;

    public function test_posted_m12_invoice_is_reflected_in_sales_order_progress(): void
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => 'M04.sales_order_view', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => 'M12.customer_invoice_view', 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrder::factory()->create(['branch_id' => $branch->id]);
        $line = SalesOrderLine::factory()->create(['sales_order_id' => $order->id]);

        $this->mock(ReceivablesPayablesApi::class)
            ->shouldReceive('salesOrderInvoices')
            ->once()
            ->with($actor->id, $branch->id, [$line->id])
            ->andReturn(['state' => 'invoiced', 'invoices' => [['id' => 11, 'number' => 'TEST-INV-001', 'status' => 'posted', 'invoice_date' => '2026-11-05', 'total_amount' => '100.00']]]);

        $this->assertSame('invoiced', app(SalesOrderProgress::class)->read($actor->id, $order)['invoicing']);
    }
}

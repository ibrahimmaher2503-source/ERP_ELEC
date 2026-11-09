<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Products\Domain\Product;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderLine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesOrderInvoiceProviderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sales_invoice_projection_is_scoped_to_permission_branch_and_order_lines(): void
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }

        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrder::factory()->create(['branch_id' => $branch->id]);
        $line = SalesOrderLine::factory()->create(['sales_order_id' => $order->id]);
        $unrelated = SalesOrderLine::factory()->create(['sales_order_id' => SalesOrder::factory()->create(['branch_id' => $branch->id])->id]);
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        $product = Product::findOrFail($line->product_id);
        $reservation = DB::table('inventory_reservations')->insertGetId([
            'idempotency_key' => (string) Str::uuid(), 'source_module' => 'Sales', 'source_id' => (string) $order->id,
            'source_line' => (string) $line->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'requested' => '1.00000000', 'reserved' => '0.00000000', 'actor_id' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $instruction = DB::table('sales_delivery_instructions')->insertGetId([
            'order_id' => $order->id, 'creation_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'reference' => 'TEST-INVOICE-PROJECTION', 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        $deliveryLine = DB::table('sales_delivery_instruction_lines')->insertGetId([
            'instruction_id' => $instruction, 'line_id' => $line->id, 'reservation_id' => $reservation,
            'quantity' => '1.00000000', 'issue_key' => (string) Str::uuid(),
        ]);
        $taxRule = DB::table('accounting_tax_rules')->insertGetId([
            'code' => 'TEST-SALES-INVOICE', 'name' => 'قاعدة اختبار', 'transaction_type' => 'sale', 'rate' => '0.00000',
            'starts_on' => '2020-01-01', 'treatment' => 'exempt', 'tax_scale' => 2,
            'legal_reference' => 'Synthetic test fixture only', 'created_by' => $actor->id,
        ]);
        $invoice = DB::table('rp_customer_invoices')->insertGetId([
            'creation_key' => (string) Str::uuid(), 'branch_id' => $branch->id, 'customer_id' => $order->customer_id,
            'customer_name' => $order->customer_snapshot['name'], 'invoice_date' => '2026-11-05', 'due_date' => '2026-11-05',
            'status' => 'draft', 'created_by' => $actor->id,
        ]);
        DB::table('rp_customer_invoice_lines')->insert([
            'invoice_id' => $invoice, 'sales_order_line_id' => $line->id, 'delivery_line_id' => $deliveryLine,
            'product_id' => $product->id, 'description' => 'بند اختبار', 'quantity' => '1.00000000',
            'net_amount' => '0.00', 'tax_amount' => '0.00', 'total_amount' => '0.00',
            'tax_rule_id' => $taxRule, 'tax_snapshot' => json_encode(['fixture' => true]),
        ]);

        $api = app(ReceivablesPayablesApi::class);
        $this->assertSame(['state' => 'restricted', 'invoices' => []], $api->salesOrderInvoices($actor->id, $branch->id, [$line->id]));

        DB::table('access_grants')->insert([
            'user_id' => $actor->id, 'permission_code' => 'M12.customer_invoice_view', 'branch_id' => $branch->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        PermissionAccess::forgetRequestSnapshot();
        $this->assertSame('requested', $api->salesOrderInvoices($actor->id, $branch->id, [$line->id])['state']);
        $this->assertSame($invoice, $api->salesOrderInvoices($actor->id, $branch->id, [$line->id])['invoices'][0]['id']);
        $this->assertSame('none', $api->salesOrderInvoices($actor->id, $branch->id, [$unrelated->id])['state']);
        $this->assertSame(['state' => 'restricted', 'invoices' => []], $api->salesOrderInvoices($actor->id, $otherBranch->id, [$line->id]));
    }
}

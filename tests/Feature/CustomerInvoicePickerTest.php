<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\ReceivablesPayables\Presentation\FinancialOperationsPage;
use App\Modules\Sales\Application\Contracts\SalesDeliverySources;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\Sales\Domain\SalesOrderLine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerInvoicePickerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_confirmed_delivery_lines_are_scoped_by_sales_view_permission_and_confirmation(): void
    {
        [$branch, $actor, $order, $line, $instruction, $deliveryLine] = $this->deliveryFixture();
        $outsider = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $service = app(SalesDeliverySources::class);

        $this->grant($actor, 'M04.sales_order_view', $branch->id);

        // A draft instruction is not billable evidence yet.
        $this->assertSame([], $service->confirmedDeliveryLines($actor->id, (int) $order->customer_id));

        $this->confirm($instruction, $actor);

        $rows = $service->confirmedDeliveryLines($actor->id, (int) $order->customer_id);
        $this->assertCount(1, $rows);
        $this->assertSame($deliveryLine, $rows[0]['delivery_line_id']);
        $this->assertSame($instruction, $rows[0]['delivery_id']);
        $this->assertSame($order->id, $rows[0]['order_id']);
        $this->assertSame($line->product_id, $rows[0]['product_id']);
        $this->assertSame(0, bccomp((string) $rows[0]['delivered_quantity'], '1', 8));

        // Another customer's lines are not returned.
        $this->assertSame([], $service->confirmedDeliveryLines($actor->id, (int) $order->customer_id + 999999));

        // An actor without the Sales view permission sees nothing.
        $this->assertSame([], $service->confirmedDeliveryLines($outsider->id, (int) $order->customer_id));
    }

    public function test_create_customer_without_picked_lines_fails_validation_on_picked(): void
    {
        [$branch, $actor] = $this->deliveryFixture();
        $this->grant($actor, 'M12.customer_invoice_view', $branch->id);
        $this->grant($actor, 'M12.customer_invoice_manage', $branch->id);
        // The page's customer search needs the Parties view permission to render.
        $this->grant($actor, 'M03.customer_view', null);
        $this->grant($actor, 'M04.sales_order_view', $branch->id);

        Livewire::actingAs($actor)
            ->test(FinancialOperationsPage::class, ['screen' => 'customer-invoices'])
            ->call('createCustomer')
            ->assertHasErrors(['picked']);
    }

    private function grant(User $user, string $permission, ?int $branchId): void
    {
        DB::table('access_grants')->insert([
            'user_id' => $user->id, 'permission_code' => $permission, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        PermissionAccess::forgetRequestSnapshot();
    }

    /** Moves a draft instruction through the guarded draft -> posting -> confirmed path with a real official document. */
    private function confirm(int $instruction, User $actor): void
    {
        $documentId = (string) Str::uuid();
        DB::table('system_official_documents')->insert([
            'id' => $documentId, 'company_id' => 1, 'document_type' => 'sales_delivery', 'source_id' => (string) $instruction,
            'revision' => 1, 'document_date' => '2026-11-05', 'official_number' => 'TEST-DLV-'.Str::upper(Str::random(10)),
            'snapshot' => json_encode(['fixture' => true]), 'pdf_base64' => str_repeat('A', 160), 'sha256' => str_repeat('b', 64),
            'issued_by' => $actor->id,
        ]);
        DB::table('sales_delivery_instructions')->where('id', $instruction)->update(['status' => 'posting']);
        DB::table('sales_delivery_instructions')->where('id', $instruction)->update([
            'status' => 'confirmed', 'document_id' => $documentId, 'confirmed_by' => $actor->id, 'confirmed_at' => now(),
        ]);
    }

    /** @return array{Branch, User, SalesOrder, SalesOrderLine, int, int} */
    private function deliveryFixture(): array
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }

        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrder::factory()->create(['branch_id' => $branch->id]);
        $line = SalesOrderLine::factory()->create(['sales_order_id' => $order->id]);
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        $reservation = DB::table('inventory_reservations')->insertGetId([
            'idempotency_key' => (string) Str::uuid(), 'source_module' => 'Sales', 'source_id' => (string) $order->id,
            'source_line' => (string) $line->id, 'warehouse_id' => $warehouse->id, 'product_id' => $line->product_id,
            'requested' => '1.00000000', 'reserved' => '0.00000000', 'actor_id' => $actor->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $instruction = DB::table('sales_delivery_instructions')->insertGetId([
            'order_id' => $order->id, 'creation_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'reference' => 'TEST-INVOICE-PICKER', 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        $deliveryLine = DB::table('sales_delivery_instruction_lines')->insertGetId([
            'instruction_id' => $instruction, 'line_id' => $line->id, 'reservation_id' => $reservation,
            'quantity' => '1.00000000', 'issue_key' => (string) Str::uuid(),
        ]);

        return [$branch, $actor, $order, $line, $instruction, $deliveryLine];
    }
}

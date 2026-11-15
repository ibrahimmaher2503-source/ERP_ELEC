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
use Livewire\Features\SupportTesting\Testable;
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

    public function test_picking_a_delivery_line_without_quantity_creates_a_draft_for_the_full_remaining_quantity(): void
    {
        [$branch, $actor, $order, $line, $deliveryLine] = $this->billableFixture('4.00000000');
        $component = $this->pickerPage($actor, $branch, $order);

        $component->set('picked.'.$deliveryLine.'.on', true)
            ->call('createCustomer')
            ->assertHasNoErrors();

        $invoice = DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->first();
        $this->assertNotNull($invoice, 'A draft customer invoice row must exist after createCustomer().');
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($branch->id, (int) $invoice->branch_id);
        $this->assertSame($actor->id, (int) $invoice->created_by);
        $lines = DB::table('rp_customer_invoice_lines')->where('invoice_id', $invoice->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame($deliveryLine, (int) $lines[0]->delivery_line_id);
        $this->assertSame($line->id, (int) $lines[0]->sales_order_line_id);
        $this->assertSame(0, bccomp((string) $lines[0]->quantity, '4', 8), 'Empty quantity must default to the full remaining quantity.');
        $this->assertSame(0, bccomp((string) $lines[0]->net_amount, '400.00', 2));
        $this->assertSame(0, bccomp((string) $lines[0]->tax_amount, '56.00', 2));
        $this->assertSame(0, bccomp((string) $invoice->total_amount, '456.00', 2));

        // The page resets itself after success, so nothing is left picked.
        $component->assertSet('picked', [])->assertSet('orderChoice', '');
        $this->assertSame(1, DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->count());
    }

    public function test_partial_quantity_then_remaining_quantity_then_over_invoicing_is_rejected(): void
    {
        [$branch, $actor, $order, , $deliveryLine] = $this->billableFixture('4.00000000');
        $component = $this->pickerPage($actor, $branch, $order);

        // First invoice: explicit smaller quantity.
        $component->set('picked.'.$deliveryLine.'.on', true)
            ->set('picked.'.$deliveryLine.'.qty', '1.5')
            ->call('createCustomer')
            ->assertHasNoErrors();
        $first = DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->orderBy('id')->first();
        $firstLine = DB::table('rp_customer_invoice_lines')->where('invoice_id', $first->id)->first();
        $this->assertSame('draft', $first->status);
        $this->assertSame(0, bccomp((string) $firstLine->quantity, '1.5', 8));
        $this->assertSame(0, bccomp((string) $firstLine->net_amount, '150.00', 2));
        $this->assertSame(0, bccomp((string) $firstLine->tax_amount, '21.00', 2));

        // Second invoice for the remaining quantity: the picker must offer 2.5 and default to it.
        $component = $this->pickerPage($actor, $branch, $order);
        $component->assertSee('2.5');
        $component->set('picked.'.$deliveryLine.'.on', true)
            ->call('createCustomer')
            ->assertHasNoErrors();
        $this->assertSame(2, DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->count());
        $second = DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->orderByDesc('id')->first();
        $secondLine = DB::table('rp_customer_invoice_lines')->where('invoice_id', $second->id)->first();
        $this->assertNotSame((int) $first->id, (int) $second->id);
        $this->assertSame(0, bccomp((string) $secondLine->quantity, '2.5', 8));
        // The cumulative frozen order tax is split exactly: 150 + 250 net, 21 + 35 tax.
        $this->assertSame(0, bccomp((string) $secondLine->net_amount, '250.00', 2));
        $this->assertSame(0, bccomp((string) $secondLine->tax_amount, '35.00', 2));
        $this->assertSame(0, bccomp((string) DB::table('rp_customer_invoice_lines')->where('delivery_line_id', $deliveryLine)->sum('quantity'), '4', 8));

        // Fully invoiced: the line is no longer offered (remaining 0). Forcing it picked anyway must not create a third draft.
        // The page does not filter picked ids against the offered lines, so an empty quantity reaches the service and is rejected there.
        $component = $this->pickerPage($actor, $branch, $order);
        $component->set('picked.'.$deliveryLine.'.on', true)->call('createCustomer')->assertHasErrors();
        $component->set('picked.'.$deliveryLine.'.qty', '1')->call('createCustomer')->assertHasErrors(['picked']);
        $this->assertSame(2, DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->count());
    }

    public function test_explicit_quantity_above_the_remaining_delivered_quantity_is_rejected_without_a_draft(): void
    {
        [$branch, $actor, $order, , $deliveryLine] = $this->billableFixture('4.00000000');
        $component = $this->pickerPage($actor, $branch, $order);

        $component->set('picked.'.$deliveryLine.'.on', true)
            ->set('picked.'.$deliveryLine.'.qty', '4.5')
            ->call('createCustomer')
            ->assertHasErrors(['customer_invoice']);

        $this->assertSame(0, DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->count());
        $this->assertSame(0, DB::table('rp_customer_invoice_lines')->where('delivery_line_id', $deliveryLine)->count());

        // After a legitimate partial invoice, asking for more than what is left is rejected and creates nothing.
        $component = $this->pickerPage($actor, $branch, $order);
        $component->set('picked.'.$deliveryLine.'.on', true)->set('picked.'.$deliveryLine.'.qty', '3')->call('createCustomer')->assertHasNoErrors();
        $component = $this->pickerPage($actor, $branch, $order);
        $component->set('picked.'.$deliveryLine.'.on', true)->set('picked.'.$deliveryLine.'.qty', '1.00000001')
            ->call('createCustomer')->assertHasErrors(['customer_invoice']);
        $this->assertSame(1, DB::table('rp_customer_invoices')->where('customer_id', $order->customer_id)->count());
        $this->assertSame(0, bccomp((string) DB::table('rp_customer_invoice_lines')->where('delivery_line_id', $deliveryLine)->sum('quantity'), '3', 8));
    }

    private function pickerPage(User $actor, Branch $branch, SalesOrder $order): Testable
    {
        // Each call mounts a fresh page, like reopening the screen; permissions were granted once by billableFixture().
        return Livewire::actingAs($actor)
            ->test(FinancialOperationsPage::class, ['screen' => 'customer-invoices'])
            ->set('form.customer_id', (string) $order->customer_id)
            ->set('orderChoice', (string) $order->id);
    }

    /**
     * An approved sales order (qty $quantity, net 100.00 and tax 14.00 per unit frozen in its issue snapshot),
     * fully delivered through a confirmed delivery instruction, with financial activation opened.
     * Direct inserts: reservation, delivery instruction + line, official documents, order review/issue, tax rule, activation.
     *
     * @return array{Branch, User, SalesOrder, SalesOrderLine, int}
     */
    private function billableFixture(string $quantity): array
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $approver = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrder::factory()->create(['branch_id' => $branch->id]);
        $line = SalesOrderLine::factory()->create(['sales_order_id' => $order->id, 'quantity' => $quantity, 'description' => 'كابل نحاس 3×2.5 — اختبار الفوترة']);
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);
        $reservation = DB::table('inventory_reservations')->insertGetId([
            'idempotency_key' => (string) Str::uuid(), 'source_module' => 'Sales', 'source_id' => (string) $order->id,
            'source_line' => (string) $line->id, 'warehouse_id' => $warehouse->id, 'product_id' => $line->product_id,
            'requested' => $quantity, 'reserved' => '0.00000000', 'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $instruction = DB::table('sales_delivery_instructions')->insertGetId([
            'order_id' => $order->id, 'creation_key' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'reference' => 'TEST-DLV-'.Str::upper(Str::random(6)), 'status' => 'draft', 'created_by' => $actor->id, 'created_at' => now(),
        ]);
        $deliveryLine = DB::table('sales_delivery_instruction_lines')->insertGetId([
            'instruction_id' => $instruction, 'line_id' => $line->id, 'reservation_id' => $reservation,
            'quantity' => $quantity, 'issue_key' => (string) Str::uuid(),
        ]);
        $this->confirm($instruction, $actor);

        $taxRule = DB::table('accounting_tax_rules')->insertGetId([
            'code' => 'TEST-PICKER-SALE-'.Str::upper(Str::random(6)), 'name' => 'قاعدة بيع اختبار', 'transaction_type' => 'sale', 'rate' => '14.00000',
            'starts_on' => '2020-01-01', 'treatment' => 'percentage', 'tax_scale' => 2,
            'legal_reference' => 'Synthetic test fixture only', 'created_by' => $actor->id,
        ]);
        $review = DB::table('sales_order_reviews')->insertGetId([
            'sales_order_id' => $order->id, 'target_version' => 1, 'content_hash' => str_repeat('c', 64), 'snapshot' => json_encode(['fixture' => true]),
            'status' => 'approved', 'requested_by' => $actor->id, 'decided_by' => $approver->id, 'decided_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderDocument = (string) Str::uuid();
        DB::table('system_official_documents')->insert([
            'id' => $orderDocument, 'company_id' => 1, 'document_type' => 'sales_order', 'source_id' => (string) $order->id,
            'revision' => 1, 'document_date' => '2026-11-05', 'official_number' => 'TEST-SO-'.Str::upper(Str::random(10)),
            'snapshot' => json_encode(['fixture' => true]), 'pdf_base64' => str_repeat('A', 160), 'sha256' => str_repeat('d', 64), 'issued_by' => $approver->id,
        ]);
        DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'approved']);
        DB::table('sales_order_issues')->insert([
            'order_id' => $order->id, 'review_id' => $review, 'document_id' => $orderDocument, 'commercial_snapshot' => json_encode(['fixture' => true]),
            'tax_snapshot' => json_encode(['lines' => [['net' => bcmul('100', $quantity, 2), 'tax' => bcmul('14', $quantity, 2), 'tax_rule_id' => $taxRule, 'rate' => '14.00000']]]),
            'approved_by' => $approver->id, 'approved_at' => now(),
        ]);
        $this->openFinancialActivation($actor);
        $this->grant($actor, 'M12.customer_invoice_view', $branch->id);
        $this->grant($actor, 'M12.customer_invoice_manage', $branch->id);
        $this->grant($actor, 'M03.customer_view', null);
        $this->grant($actor, 'M04.sales_order_view', $branch->id);

        return [$branch, $actor, $order->fresh(), $line, $deliveryLine];
    }

    /** Opens the single accounting_activation row inside the test transaction (its immutability guard is disabled for this transaction only). */
    private function openFinancialActivation(User $actor): void
    {
        DB::statement('ALTER TABLE accounting_activation DISABLE TRIGGER accounting_activation_guard');
        DB::table('accounting_activation')->where('id', 1)->update([
            'go_live_date' => '2020-01-01', 'tax_validated' => true, 'tax_validation_reference' => 'Synthetic test validation',
            'activated_by' => $actor->id, 'activated_at' => now(), 'updated_at' => now(),
        ]);
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

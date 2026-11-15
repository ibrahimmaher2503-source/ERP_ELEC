<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Inventory\Domain\Warehouse;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\ReceivablesPayables\Presentation\FinancialOperationsPage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Drives the "supplier-invoices" screen the way a user does: choose the supplier, choose the received order,
 * tick posted receipt lines (optionally typing a quantity/price) and press create. Everything after the page
 * (SupplierInvoices::create, tax calculation, GRNI matching) is the real application code.
 */
class SupplierInvoicePickerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_picking_a_receipt_line_without_quantity_or_price_creates_a_draft_for_the_full_remaining_quantity(): void
    {
        $f = $this->postedReceiptFixture();
        $component = $this->pickerPage($f, 'PICKER-A-001');

        $component->set('receiptPicked.'.$f['lines'][0].'.on', true)
            ->call('createSupplier')
            ->assertHasNoErrors();

        $invoice = DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->first();
        $this->assertNotNull($invoice, 'A draft supplier invoice row must exist after createSupplier().');
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('PICKER-A-001', $invoice->supplier_invoice_number);
        $this->assertSame($f['branch']->id, (int) $invoice->branch_id);
        $this->assertSame($f['actor']->id, (int) $invoice->created_by);
        $lines = DB::table('rp_supplier_invoice_lines')->where('invoice_id', $invoice->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame($f['orderLine'], (int) $lines[0]->order_line_id);
        $this->assertSame(0, bccomp((string) $lines[0]->quantity, '4', 8), 'Empty quantity defaults to the full remaining receipt quantity.');
        $this->assertSame(0, bccomp((string) $lines[0]->unit_price, '20', 8), 'Empty price defaults to the purchase order unit price.');
        $this->assertSame(0, bccomp((string) $lines[0]->net_amount, '80.00', 2));
        $this->assertSame(0, bccomp((string) $lines[0]->tax_amount, '11.20', 2));
        $this->assertSame(0, bccomp((string) $invoice->total_amount, '91.20', 2));
        $matches = DB::table('rp_supplier_invoice_matches')->where('invoice_line_id', $lines[0]->id)->get();
        $this->assertCount(1, $matches);
        $this->assertSame($f['lines'][0], (int) $matches[0]->receipt_line_id);
        $this->assertSame(0, bccomp((string) $matches[0]->quantity, '4', 8));
        $this->assertSame(0, bccomp((string) $matches[0]->grni_amount, '80.00', 2));
        $this->assertSame(0, bccomp((string) $invoice->grni_amount, '80.00', 2));
        $this->assertSame(0, bccomp((string) $invoice->ppv_amount, '0.00', 2));

        $component->assertSet('receiptPicked', [])->assertSet('receiptChoice', '');
    }

    public function test_partial_quantity_then_remaining_quantity_then_over_invoicing_is_rejected(): void
    {
        $f = $this->postedReceiptFixture();
        $receiptLine = $f['lines'][0];

        // First invoice: explicit smaller quantity.
        $this->pickerPage($f, 'PICKER-B-001')
            ->set('receiptPicked.'.$receiptLine.'.on', true)
            ->set('receiptPicked.'.$receiptLine.'.qty', '1.5')
            ->call('createSupplier')
            ->assertHasNoErrors();
        $first = DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->orderBy('id')->first();
        $firstLine = DB::table('rp_supplier_invoice_lines')->where('invoice_id', $first->id)->first();
        $this->assertSame('draft', $first->status);
        $this->assertSame(0, bccomp((string) $firstLine->quantity, '1.5', 8));
        $this->assertSame(0, bccomp((string) $firstLine->net_amount, '30.00', 2));
        $this->assertSame(0, bccomp((string) $first->grni_amount, '30.00', 2));

        // Second invoice for the remaining 2.5, with an explicit higher price: GRNI is exact, the difference is PPV.
        $second = $this->pickerPage($f, 'PICKER-B-002');
        $second->assertSee('2.5');
        $second->set('receiptPicked.'.$receiptLine.'.on', true)
            ->set('receiptPicked.'.$receiptLine.'.price', '22')
            ->call('createSupplier')
            ->assertHasNoErrors();
        $this->assertSame(2, DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->count());
        $secondInvoice = DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->orderByDesc('id')->first();
        $secondLine = DB::table('rp_supplier_invoice_lines')->where('invoice_id', $secondInvoice->id)->first();
        $this->assertNotSame((int) $first->id, (int) $secondInvoice->id);
        $this->assertSame(0, bccomp((string) $secondLine->quantity, '2.5', 8));
        $this->assertSame(0, bccomp((string) $secondLine->unit_price, '22', 8));
        $this->assertSame(0, bccomp((string) $secondLine->net_amount, '55.00', 2));
        // Cumulative GRNI over the two invoices equals the receipt value exactly (30.00 + 50.00 = 80.00).
        $this->assertSame(0, bccomp((string) $secondInvoice->grni_amount, '50.00', 2));
        $this->assertSame(0, bccomp((string) $secondInvoice->ppv_amount, '5.00', 2));
        $this->assertSame(0, bccomp((string) DB::table('rp_supplier_invoice_matches')->where('receipt_line_id', $receiptLine)->sum('quantity'), '4', 8));
        $this->assertSame(0, bccomp((string) DB::table('rp_supplier_invoice_matches')->where('receipt_line_id', $receiptLine)->sum('grni_amount'), '80.00', 2));

        // Fully invoiced: the line is no longer offered, so ticking it selects nothing and no third draft appears.
        $this->pickerPage($f, 'PICKER-B-003')
            ->set('receiptPicked.'.$receiptLine.'.on', true)
            ->call('createSupplier')
            ->assertHasErrors(['receiptPicked']);
        $this->assertSame(2, DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->count());
    }

    public function test_explicit_quantity_above_the_remaining_receipt_quantity_is_rejected_without_a_draft(): void
    {
        $f = $this->postedReceiptFixture();
        $receiptLine = $f['lines'][0];

        $this->pickerPage($f, 'PICKER-C-001')
            ->set('receiptPicked.'.$receiptLine.'.on', true)
            ->set('receiptPicked.'.$receiptLine.'.qty', '4.5')
            ->call('createSupplier')
            ->assertHasErrors(['supplier_invoice']);
        $this->assertSame(0, DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->count());
        $this->assertSame(0, DB::table('rp_supplier_invoice_matches')->where('receipt_line_id', $receiptLine)->count());

        // After a legitimate partial invoice, asking for more than what is left is rejected and creates nothing.
        $this->pickerPage($f, 'PICKER-C-002')->set('receiptPicked.'.$receiptLine.'.on', true)
            ->set('receiptPicked.'.$receiptLine.'.qty', '3')->call('createSupplier')->assertHasNoErrors();
        $this->pickerPage($f, 'PICKER-C-003')->set('receiptPicked.'.$receiptLine.'.on', true)
            ->set('receiptPicked.'.$receiptLine.'.qty', '1.00000001')->call('createSupplier')->assertHasErrors(['supplier_invoice']);
        $this->assertSame(1, DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->count());
        $this->assertSame(0, bccomp((string) DB::table('rp_supplier_invoice_matches')->where('receipt_line_id', $receiptLine)->sum('quantity'), '3', 8));
    }

    public function test_two_receipt_lines_of_the_same_order_line_become_one_invoice_line_with_two_matches(): void
    {
        $f = $this->postedReceiptFixture();

        $this->pickerPage($f, 'PICKER-D-001')
            ->set('receiptPicked.'.$f['lines'][0].'.on', true)
            ->set('receiptPicked.'.$f['lines'][1].'.on', true)
            ->call('createSupplier')
            ->assertHasNoErrors();

        $invoice = DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->first();
        $this->assertNotNull($invoice);
        $lines = DB::table('rp_supplier_invoice_lines')->where('invoice_id', $invoice->id)->get();
        $this->assertCount(1, $lines);
        $this->assertSame(0, bccomp((string) $lines[0]->quantity, '10', 8));
        $this->assertSame(0, bccomp((string) $lines[0]->net_amount, '200.00', 2));
        $matches = DB::table('rp_supplier_invoice_matches')->where('invoice_line_id', $lines[0]->id)->orderBy('receipt_line_id')->get();
        $this->assertCount(2, $matches);
        $this->assertSame([$f['lines'][0], $f['lines'][1]], $matches->pluck('receipt_line_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(0, bccomp((string) $matches[0]->quantity, '4', 8));
        $this->assertSame(0, bccomp((string) $matches[1]->quantity, '6', 8));
        $this->assertSame(0, bccomp((string) $invoice->grni_amount, '200.00', 2));
    }

    public function test_receipt_lines_of_another_supplier_are_not_offered(): void
    {
        $f = $this->postedReceiptFixture();
        $other = Supplier::factory()->create();

        Livewire::actingAs($f['actor'])->test(FinancialOperationsPage::class, ['screen' => 'supplier-invoices'])
            ->set('form.supplier_id', (string) $other->id)
            ->set('receiptChoice', (string) $f['order'])
            ->set('receiptPicked.'.$f['lines'][0].'.on', true)
            ->call('createSupplier')
            ->assertHasErrors(['receiptPicked']);
        $this->assertSame(0, DB::table('rp_supplier_invoices')->where('supplier_id', $other->id)->count());
        $this->assertSame(0, DB::table('rp_supplier_invoices')->where('supplier_id', $f['supplier']->id)->count());
    }

    private function pickerPage(array $f, string $number): Testable
    {
        // A fresh mount per call, like reopening the screen after the previous invoice was created.
        return Livewire::actingAs($f['actor'])
            ->test(FinancialOperationsPage::class, ['screen' => 'supplier-invoices'])
            ->set('form.supplier_id', (string) $f['supplier']->id)
            ->set('form.supplier_invoice_number', $number)
            ->set('receiptChoice', (string) $f['order']);
    }

    /**
     * One approved, issued purchase order (10 x 20.00) for a supplier, with two POSTED receipts of 4 and 6 units
     * (receipt value 80.00 and 120.00) and an effective 14% recoverable purchase tax rule.
     *
     * DIRECT INSERTS (built with session_replication_role=replica so triggers/FKs do not demand the full
     * inventory/GL posting chain, then switched back to "origin" before the page is driven):
     * purchasing_orders, purchasing_order_lines, purchasing_order_issues (+ system_official_documents),
     * purchasing_receipts (status posted), purchasing_receipt_lines, purchasing_receipt_postings
     * (movement_id/layer_id/journal_id are dangling placeholders; no real stock movement, FIFO layer or journal exists).
     * Everything the invoice creation itself writes is produced by the real application code.
     *
     * @return array{branch: Branch, actor: User, supplier: Supplier, order: int, orderLine: int, lines: array{int, int}}
     */
    private function postedReceiptFixture(): array
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            Company::factory()->create();
        }
        $branch = Branch::factory()->create();
        $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        $poster = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
        DB::table('user_branch_scopes')->insert(['user_id' => $actor->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create();
        $warehouse = Warehouse::factory()->create(['branch_id' => $branch->id]);

        DB::statement("SET LOCAL session_replication_role = 'replica'");
        $order = DB::table('purchasing_orders')->insertGetId([
            'creation_key' => (string) Str::uuid(), 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'created_by' => $actor->id, 'approved_by' => $poster->id, 'status' => 'approved', 'version' => 3, 'order_date' => '2026-11-05',
            'currency' => 'EGP', 'net_amount' => '200.00000000', 'approved_at' => now(), 'issued_at' => now(), 'official_number' => 'TEST-PO-'.Str::upper(Str::random(8)),
            'tax_mode' => 'exclusive', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderLine = DB::table('purchasing_order_lines')->insertGetId([
            'order_id' => $order, 'product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'sku' => $product->sku, 'product_name' => $product->name,
            'quantity' => '10.00000000', 'unit_price' => '20.00000000', 'discount_amount' => '0.00000000', 'net_amount' => '200.00000000',
        ]);
        $document = (string) Str::uuid();
        DB::table('system_official_documents')->insert([
            'id' => $document, 'company_id' => 1, 'document_type' => 'purchase_order', 'source_id' => (string) $order,
            'revision' => 1, 'document_date' => '2026-11-05', 'official_number' => 'TEST-POD-'.Str::upper(Str::random(10)),
            'snapshot' => json_encode(['fixture' => true]), 'pdf_base64' => str_repeat('A', 160), 'sha256' => str_repeat('e', 64), 'issued_by' => $poster->id,
        ]);
        DB::table('purchasing_order_issues')->insert([
            'order_id' => $order, 'source_version' => 3, 'document_id' => $document, 'official_number' => 'TEST-POI-'.Str::upper(Str::random(8)),
            'tax_snapshot' => json_encode(['fixture' => true]), 'issued_by' => $poster->id, 'issued_at' => now(),
        ]);
        $lines = [];
        $placeholder = 800000000 + $order * 10;
        foreach (['4.00000000' => '80.00', '6.00000000' => '120.00'] as $quantity => $value) {
            $placeholder++;
            $receipt = DB::table('purchasing_receipts')->insertGetId([
                'creation_key' => (string) Str::uuid(), 'order_id' => $order, 'branch_id' => $branch->id, 'warehouse_id' => $warehouse->id,
                'created_by' => $actor->id, 'status' => 'posted', 'version' => 3, 'receipt_date' => '2026-11-06',
                'delivery_reference' => 'TEST-DN-'.Str::upper(Str::random(6)), 'journal_id' => $placeholder, 'posted_by' => $poster->id, 'posted_at' => now(),
                'capitalized_amount' => $value, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $line = DB::table('purchasing_receipt_lines')->insertGetId(['receipt_id' => $receipt, 'order_line_id' => $orderLine, 'quantity' => $quantity]);
            DB::table('purchasing_receipt_postings')->insert(['receipt_id' => $receipt, 'line_id' => $line, 'movement_id' => $placeholder, 'layer_id' => $placeholder, 'amount' => $value, 'posted_at' => now()]);
            $lines[] = $line;
        }
        DB::table('accounting_tax_rules')->insert([
            'code' => 'TEST-PICKER-PURCHASE-'.Str::upper(Str::random(6)), 'name' => 'قاعدة شراء اختبار', 'transaction_type' => 'purchase', 'rate' => '14.00000',
            'starts_on' => '2020-01-01', 'treatment' => 'percentage', 'tax_scale' => 2, 'purchase_recoverable' => true,
            'legal_reference' => 'Synthetic test fixture only', 'created_by' => $actor->id,
        ]);
        DB::statement("SET LOCAL session_replication_role = 'origin'");

        foreach ([['M12.supplier_invoice_view', $branch->id], ['M12.supplier_invoice_manage', $branch->id], ['M05.receipt_view', $branch->id],
            ['M03.supplier_view', null], ['M02.product_view', null], ['M11.tax_calculate', null]] as [$permission, $scope]) {
            DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => $scope, 'created_at' => now(), 'updated_at' => now()]);
        }
        PermissionAccess::forgetRequestSnapshot();

        return ['branch' => $branch, 'actor' => $actor, 'supplier' => $supplier, 'order' => $order, 'orderLine' => $orderLine, 'lines' => $lines];
    }
}

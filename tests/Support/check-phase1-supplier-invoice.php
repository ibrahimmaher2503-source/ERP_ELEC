<?php

// Source-driven M12 supplier invoice acceptance against isolated PostgreSQL only.
use App\Application\ActivateFinancialPosting;
use App\Application\PostPurchaseReceipt;
use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Domain\FinancialDecimal;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Purchasing\Application\PurchaseOrders;
use App\Modules\Purchasing\Application\PurchaseReceiving;
use App\Modules\ReceivablesPayables\Application\Contracts\ReceivablesPayablesApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
putenv('DB_CONNECTION=pgsql');
$_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'pgsql';
putenv('DB_URL=');
$_ENV['DB_URL'] = $_SERVER['DB_URL'] = '';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$storage = $root.'/.tmp/phase1-supplier-invoice-storage';
foreach (['app/private', 'framework/cache', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
    if (! is_dir($storage.'/'.$dir) && ! mkdir($storage.'/'.$dir, 0700, true) && ! is_dir($storage.'/'.$dir)) {
        throw new RuntimeException('Cannot create isolated Phase 1 storage path.');
    }
}
$app->useStoragePath($storage);
$app->make(Kernel::class)->bootstrap();

$db = DB::connection();
$connection = DB::selectOne('select current_database() as db, inet_server_addr()::text as address, inet_server_port() as port');
$database = (string) config('database.connections.pgsql.database');
$host = (string) config('database.connections.pgsql.host');
$port = (string) config('database.connections.pgsql.port');
if ($db->getDriverName() !== 'pgsql' || config('database.default') !== 'pgsql'
    || ! str_ends_with($database, '_test') || $database !== $connection->db
    || $host !== '127.0.0.1' || ! in_array($connection->address, ['127.0.0.1', '127.0.0.1/32'], true)
    || $port !== (string) $connection->port || ! in_array(config('database.connections.pgsql.url'), [null, ''], true)) {
    throw new RuntimeException('ABORT before writes: an isolated localhost PostgreSQL *_test database is required.');
}
$date = now('Africa/Cairo')->toDateString();

$need = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$expect = static function (callable $work, string $label, string $kind = 'validation'): Throwable {
    try {
        $work();
    } catch (Throwable $error) {
        $accepted = match ($kind) {
            'forbidden' => $error instanceof HttpExceptionInterface && $error->getStatusCode() === 403,
            'conflict' => $error instanceof HttpExceptionInterface && $error->getStatusCode() === 409,
            'duplicate' => $error instanceof QueryException && ($error->errorInfo[0] ?? null) === '23505'
                && str_contains($error->getMessage(), 'rp_supplier_invoices'),
            default => $error instanceof ValidationException,
        };
        if (! $accepted) {
            throw new RuntimeException('Unexpected error for '.$label.': '.$error::class.' '.$error->getMessage(), 0, $error);
        }

        return $error;
    }
    throw new RuntimeException('Expected '.$kind.' denial was not raised: '.$label);
};
$grant = static function (User $user, array $codes, ?int $branch = null): void {
    foreach ($codes as $code) {
        DB::table('access_grants')->insertOrIgnore(['user_id' => $user->id, 'permission_code' => $code,
            'branch_id' => $branch, 'created_at' => now(), 'updated_at' => now()]);
    }
};
$uuid = static fn (): string => (string) Str::uuid();

DB::beginTransaction();
$evidence = [];
try {
    $maker = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $poster = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $denied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $grant($maker, ['M01.company_update', 'M02.product_view', 'M03.supplier_view', 'M06.warehouse_create', 'M06.warehouse_view', 'M11.setup_manage', 'M11.tax_manage', 'M11.account_manage', 'M11.period_open', 'M11.opening_manage', 'M11.opening_entry', 'M11.tax_calculate']);
    $grant($poster, ['M03.supplier_view', 'M02.product_view', 'M11.tax_calculate', 'M11.opening_approve', 'M11.financial_activate']);
    $company = DB::table('companies')->where('id', 1)->first();
    if (! $company) {
        app(SaveCompany::class)->execute($maker, ['code' => 'PH1-SYNTHETIC', 'name' => 'شركة اختبار العمليات المالية',
            'legal_name' => 'Synthetic Financial Operations Test Company', 'tax_registration' => 'SYNTHETIC-TEST-ONLY',
            'address' => 'عنوان اصطناعي للاختبار فقط'], null);
    } elseif (! $company->setup_complete || ! $company->legal_name || ! $company->address) {
        throw new RuntimeException('Existing company identity is incomplete; preserving it rather than overwriting it for PDF testing.');
    }
    $branch = Branch::factory()->create(['code' => 'PH1-SI-'.strtoupper(Str::random(7))]);
    $supplier = Supplier::factory()->create(['status' => 'active']);
    $otherSupplier = Supplier::factory()->create(['status' => 'active']);
    $product = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);
    $activationRow = DB::table('accounting_activation')->where('id', 1)->first();
    if (! $activationRow || $activationRow->activated_at !== null) {
        throw new RuntimeException('Refusing to replace an existing activation state in the isolated test database.');
    }
    $mappings = [];
    $accountActions = app(AccountingActions::class);
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = $accountActions->account((int) $maker->id, null, 0, [
            'code' => 'PH1-'.strtoupper($purpose).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic '.$label,
            'kind' => $kind, 'parent_id' => null, 'postable' => true, 'active' => true,
        ]);
    }
    $periodStart = now('Africa/Cairo')->startOfYear()->toDateString();
    $periodEnd = now('Africa/Cairo')->endOfYear()->toDateString();
    if (DB::table('accounting_periods')->where('starts_on', '<=', $periodEnd)->where('ends_on', '>=', $periodStart)->exists()) {
        throw new RuntimeException('Current-year accounting period overlaps existing test data; refusing to rewrite period history.');
    }
    $accountActions->period((int) $maker->id, ['name' => 'Synthetic Phase 1 '.now('Africa/Cairo')->year,
        'starts_on' => $periodStart, 'ends_on' => $periodEnd]);
    $setup = app(FinancialActivation::class);
    $version = (int) $activationRow->version;
    $setup->save((int) $maker->id, $version, ['go_live_date' => $date, 'mappings' => $mappings]);
    $version++;
    foreach ([['sale', null], ['purchase', (int) $product->id]] as [$type, $taxProduct]) {
        $taxExists = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
            ->where('r.transaction_type', $type)->where('r.product_id', $taxProduct)->where('r.starts_on', '<=', $date)
            ->whereRaw('(coalesce(e.ends_on,r.ends_on) is null or coalesce(e.ends_on,r.ends_on)>=?::date)', [$date])
            ->whereRaw('coalesce(e.withdrawn,false)=false')->exists();
        if (! $taxExists) {
            $taxData = ['code' => 'PH1-'.strtoupper($type).'-'.strtoupper(Str::random(5)), 'name' => 'Synthetic Phase 1 '.$type.' tax',
                'transaction_type' => $type, 'product_id' => $taxProduct, 'rate' => '14', 'starts_on' => $date, 'ends_on' => null,
                'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic isolated PostgreSQL Phase 1 acceptance fixture'];
            if ($type === 'purchase') {
                $taxData['purchase_recoverable'] = true;
            }
            $setup->addTaxRule((int) $maker->id, $version, $taxData);
            $version++;
        }
    }
    $setup->validateTaxTreatment((int) $maker->id, $version, 'Synthetic isolated Phase 1 test tax treatment verification');
    $version++;
    $opening = app(OpeningBalances::class);
    $batch = $opening->save((int) $maker->id, $version, $uuid(), [], true,
        'Synthetic test declaration: no prior balances in this disposable PostgreSQL acceptance database.');
    $opening->advance((int) $maker->id, $batch, 1, 'submit');
    $opening->advance((int) $poster->id, $batch, 2, 'approve');
    app(ActivateFinancialPosting::class)->execute((int) $poster->id, $batch, 3);
    $purchaseTax = DB::table('accounting_tax_rules as r')->leftJoin('accounting_tax_rule_endings as e', 'e.rule_id', '=', 'r.id')
        ->where('r.transaction_type', 'purchase')->where('r.starts_on', '<=', $date)
        ->whereRaw('(coalesce(e.ends_on,r.ends_on) is null or coalesce(e.ends_on,r.ends_on)>=?::date)', [$date])
        ->whereRaw('coalesce(e.withdrawn,false)=false')->where(fn ($query) => $query->whereNull('r.product_id')->orWhere('r.product_id', $product->id))
        ->orderByRaw('case when r.product_id = ? then 0 else 1 end', [$product->id])->first(['r.id', 'r.rate', 'r.purchase_recoverable']);
    $need($purchaseTax && $purchaseTax->purchase_recoverable && bccomp((string) $purchaseTax->rate, '0', 8) > 0,
        'No effective recoverable purchase tax treatment applies to the synthetic product.');

    foreach ([[$maker, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M02.product_view', 'M05.order_view', 'M05.order_create', 'M05.order_submit', 'M05.receipt_view', 'M05.receipt_create', 'M06.warehouse_create', 'M06.warehouse_view', 'M06.receipt_create', 'M11.tax_calculate', 'M11.source_post', 'M12.supplier_invoice_manage', 'M12.supplier_invoice_post']],
        [$poster, ['M01.branch_view', 'M01.company_view', 'M03.supplier_view', 'M05.order_view', 'M05.order_approve', 'M05.order_issue', 'M05.receipt_view', 'M05.receipt_post', 'M06.receipt_post', 'M11.tax_calculate', 'M11.receipt_post', 'M11.source_post', 'M12.supplier_invoice_post']]] as [$user, $codes]) {
        DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        $grant($user, $codes, (int) $branch->id);
    }
    DB::table('user_branch_scopes')->insert(['user_id' => $denied->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($denied, ['M05.receipt_view'], (int) $branch->id);
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $maker->id, [
        'code' => 'PH1-SI-WH-'.strtoupper(Str::random(5)), 'name' => 'Synthetic invoice acceptance warehouse', 'branch_id' => $branch->id, 'active' => true,
    ]);
    foreach ([$maker, $poster] as $user) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $user->id, 'warehouse_id' => $warehouse['id'], 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    $makeOrderReceipts = static function (string $quantity, string $label) use ($maker, $poster, $supplier, $product, $branch, $warehouse, $date): array {
        $orders = app(PurchaseOrders::class);
        $receiving = app(PurchaseReceiving::class);
        $order = $orders->save((int) $maker->id, ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch->id,
            'supplier_id' => $supplier->id, 'order_date' => $date, 'expected_date' => null, 'currency' => 'EGP',
            'payment_terms' => 'Synthetic Phase 1 acceptance', 'notes' => $label, 'tax_mode' => 'exclusive', 'reported_tax' => null,
            'lines' => [['product_id' => $product->id, 'quantity' => $quantity, 'unit_price' => '10.00000000', 'discount_amount' => '0.00']]], null, 0);
        $orders->transition((int) $maker->id, $order, 1, 'submit');
        $orders->transition((int) $poster->id, $order, 2, 'approve');
        $orders->issue((int) $poster->id, $order);
        $orderLine = (int) DB::table('purchasing_order_lines')->where('order_id', $order)->value('id');
        $receiptIds = [];
        $remaining = $quantity;
        $split = [];
        if ($quantity === '4.00000000') {
            $split = ['1.00000000', '1.00000000', '2.00000000'];
        } else {
            $split = [$quantity];
        }
        foreach ($split as $index => $part) {
            $receipt = $receiving->save((int) $maker->id, ['creation_key' => (string) Str::uuid(), 'order_id' => $order,
                'warehouse_id' => $warehouse['id'], 'receipt_date' => $date, 'delivery_reference' => $label.'-R'.($index + 1),
                'notes' => 'Synthetic posted receipt for supplier invoice acceptance',
                'lines' => [['order_line_id' => $orderLine, 'quantity' => $part]]], null, 0);
            $receiving->submit((int) $maker->id, $receipt, 1);
            app(PostPurchaseReceipt::class)->execute((int) $poster->id, $receipt, 2);
            $receiptIds[] = ['id' => $receipt, 'line_id' => (int) DB::table('purchasing_receipt_lines')->where('receipt_id', $receipt)->value('id'), 'quantity' => $part];
            $remaining = bcsub($remaining, $part, 8);
        }
        if (bccomp($remaining, '0', 8) !== 0) {
            throw new RuntimeException('Internal receipt fixture split did not exhaust quantity.');
        }

        return ['order_id' => $order, 'order_line_id' => $orderLine, 'receipts' => $receiptIds];
    };
    $makeInvoice = static function (int $actor, string $number, string $invoiceDate, int $supplierId, int $orderLine, array $matches, string $quantity = '1.00000000'): array {
        $data = ['creation_key' => (string) Str::uuid(), 'supplier_id' => $supplierId, 'supplier_invoice_number' => $number,
            'invoice_date' => $invoiceDate, 'due_date' => date('Y-m-d', strtotime($invoiceDate.' + 30 days')), 'tax_mode' => 'exclusive',
            'lines' => [['order_line_id' => $orderLine, 'quantity' => $quantity, 'unit_price' => '11.00000000', 'discount_amount' => '0.00', 'matches' => $matches]]];
        $api = app(ReceivablesPayablesApi::class);
        $id = $api->createSupplierInvoice($actor, $data);

        return [$id, $data, $api];
    };
    $postLifecycle = static function (int $actor, int $id, ReceivablesPayablesApi $api): array {
        $api->validateSupplierInvoice($actor, $id, 1);
        $api->matchSupplierInvoice($actor, $id, 2);
        $api->approveSupplierInvoice($actor, $id, 3);

        return $api->postSupplierInvoice($actor, $id, 4);
    };

    // Four received units arrive in three actual posted receipt documents.
    $main = $makeOrderReceipts('4.00000000', 'MAIN');
    $stockBeforeInvoice = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $fifoBeforeInvoice = DB::table('inventory_fifo_layers')->whereIn('movement_id', DB::table('purchasing_receipt_postings')->whereIn('receipt_id', array_column($main['receipts'], 'id'))->select('movement_id'))->count();
    $receipts = $main['receipts'];
    $invoiceAData = ['creation_key' => $uuid(), 'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'PH1-MULTI-A-'.strtoupper(Str::random(5)),
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $main['order_line_id'], 'quantity' => '1.50000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $receipts[0]['line_id'], 'quantity' => '1.00000000'], ['receipt_line_id' => $receipts[1]['line_id'], 'quantity' => '0.50000000']]]]];
    $api = app(ReceivablesPayablesApi::class);
    $invoiceA = $api->createSupplierInvoice((int) $maker->id, $invoiceAData);
    $invoiceAReplay = $api->createSupplierInvoice((int) $maker->id, $invoiceAData);
    $need($invoiceAReplay === $invoiceA, 'Same-key supplier invoice create replay did not return the original draft.');
    $api->validateSupplierInvoice((int) $maker->id, $invoiceA, 1);
    $api->matchSupplierInvoice((int) $maker->id, $invoiceA, 2);
    // ACC-11 explicitly permits this same authorized accountant to approve and post an invoice they created.
    $api->approveSupplierInvoice((int) $maker->id, $invoiceA, 3);
    $postA = $api->postSupplierInvoice((int) $maker->id, $invoiceA, 4);
    $invoiceBData = ['creation_key' => $uuid(), 'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'PH1-MULTI-B-'.strtoupper(Str::random(5)),
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $main['order_line_id'], 'quantity' => '1.50000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $receipts[1]['line_id'], 'quantity' => '0.50000000'], ['receipt_line_id' => $receipts[2]['line_id'], 'quantity' => '1.00000000']]]]];
    $invoiceB = $api->createSupplierInvoice((int) $maker->id, $invoiceBData);
    $postB = $postLifecycle((int) $maker->id, $invoiceB, $api);
    $invoiceC = $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH1-MULTI-C-'.strtoupper(Str::random(5)), 'invoice_date' => $date,
        'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $main['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $receipts[2]['line_id'], 'quantity' => '1.00000000']]]]]);
    $postC = $postLifecycle((int) $maker->id, $invoiceC, $api);

    $invoices = DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->orderBy('id')->get();
    $need($invoices->count() === 3 && $invoices->every(fn ($row) => $row->status === 'posted'), 'Three supplier invoice lifecycle records did not reach posted.');
    $pdfEvidence = [];
    foreach ($invoices as $row) {
        $pdf = app(OfficialDocuments::class)->retrieve((string) $row->official_document_id);
        $need(str_starts_with($pdf['bytes'], '%PDF-') && hash_equals($pdf['sha256'], hash('sha256', $pdf['bytes'])), 'Supplier invoice official PDF bytes/hash do not reconcile.');
        $pdfEvidence[] = ['invoice_id' => $row->id, 'official_number' => $row->official_number, 'sha256' => $pdf['sha256'], 'bytes' => strlen($pdf['bytes'])];
    }
    $tax15 = FinancialDecimal::round(bcdiv(bcmul('16.50000000000000000000000000000000', (string) $purchaseTax->rate, 32), '100', 32));
    $tax10 = FinancialDecimal::round(bcdiv(bcmul('11.00000000000000000000000000000000', (string) $purchaseTax->rate, 32), '100', 32));
    $expectedTax = bcadd(bcadd($tax15, $tax15, 2), $tax10, 2);
    $postedNet = (string) DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->selectRaw('coalesce(sum(net_amount),0)::text as total')->value('total');
    $postedTax = (string) DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->selectRaw('coalesce(sum(tax_amount),0)::text as total')->value('total');
    $postedGross = (string) DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])->selectRaw('coalesce(sum(total_amount),0)::text as total')->value('total');
    $need($postedNet === '44.00' && $postedTax === $expectedTax && $postedGross === bcadd('44.00', $expectedTax, 2), 'Invoice net/tax/gross totals do not reconcile to 4 units at EGP 11 and the configured tax rate.');
    $matches = DB::table('rp_supplier_invoice_matches as m')->join('rp_supplier_invoice_lines as l', 'l.id', '=', 'm.invoice_line_id')->whereIn('l.invoice_id', [$invoiceA, $invoiceB, $invoiceC]);
    $matchTotals = (clone $matches)->selectRaw('coalesce(sum(m.quantity),0)::text as quantity, coalesce(sum(m.grni_amount),0)::text as grni')->first();
    $matchQuantity = (string) $matchTotals->quantity;
    $matchGrni = (string) $matchTotals->grni;
    $need(bccomp($matchQuantity, '4.00000000', 8) === 0 && $matchGrni === '40.00', 'Supplier invoice matching did not allocate exactly four received units and EGP 40.00 GRNI.');
    $invoiceValues = DB::table('rp_supplier_invoices')->whereIn('id', [$invoiceA, $invoiceB, $invoiceC])
        ->selectRaw('coalesce(sum(grni_amount),0)::text as grni, coalesce(sum(ppv_amount),0)::text as ppv')->first();
    $need($invoiceValues->grni === '40.00' && $invoiceValues->ppv === '4.00', 'GRNI/PPV reconciliation failed for higher supplier invoice price.');
    $apOriginal = (string) DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->whereIn('source_id', [$invoiceA, $invoiceB, $invoiceC])
        ->selectRaw('coalesce(sum(original_amount),0)::text as total')->value('total');
    $need($apOriginal === $postedGross, 'Posted AP original balance does not equal supplier invoice total.');

    $journalTotals = [];
    foreach ([$invoiceA, $invoiceB, $invoiceC] as $id) {
        $journal = DB::table('accounting_journals')->where('source_key', 'supplier-invoice:'.$id)->first();
        $need($journal && $journal->status === 'posted', 'Supplier invoice source journal missing/not posted.');
        $rows = DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->get();
        $dr = '0.00';
        $cr = '0.00';
        $byAccount = [];
        foreach ($rows as $row) {
            $dr = bcadd($dr, $row->debit, 2);
            $cr = bcadd($cr, $row->credit, 2);
            $byAccount[$row->account_id] = bcadd($byAccount[$row->account_id] ?? '0.00', bcsub($row->debit, $row->credit, 2), 2);
        }
        $need($dr === $cr, 'Supplier invoice source journal does not balance exactly.');
        $journalTotals[] = ['invoice_id' => $id, 'journal_id' => $journal->id, 'debit' => $dr, 'credit' => $cr,
            'grni_net' => $byAccount[$mappings['grni']] ?? '0.00', 'ppv_net' => $byAccount[$mappings['ppv']] ?? '0.00',
            'tax_input_net' => $byAccount[$mappings['tax_input']] ?? '0.00', 'ap_net' => $byAccount[$mappings['ap']] ?? '0.00'];
    }
    $allGrni = $allPpv = $allTax = $allAp = '0.00';
    foreach ($journalTotals as $row) {
        $allGrni = bcadd($allGrni, $row['grni_net'], 2);
        $allPpv = bcadd($allPpv, $row['ppv_net'], 2);
        $allTax = bcadd($allTax, $row['tax_input_net'], 2);
        $allAp = bcadd($allAp, $row['ap_net'], 2);
    }
    $need($allGrni === '40.00' && $allPpv === '4.00' && $allTax === $expectedTax && $allAp === bcsub('0.00', $postedGross, 2), 'GL purpose totals do not reconcile to GRNI, PPV, recoverable input tax, and AP.');
    $stockAfterInvoice = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $product->id)->value('on_hand');
    $fifoAfterInvoice = DB::table('inventory_fifo_layers')->whereIn('movement_id', DB::table('purchasing_receipt_postings')->whereIn('receipt_id', array_column($receipts, 'id'))->select('movement_id'))->count();
    $need($stockAfterInvoice === $stockBeforeInvoice && $fifoAfterInvoice === $fifoBeforeInvoice, 'Supplier invoice posting changed stock quantity or receipt FIFO layers.');
    $need($postA['journal_id'] && $postB['journal_id'] && $postC['journal_id'], 'Posted invoice API responses omitted journal identities.');

    // Isolated one-unit PO/receipt sources provide valid material for negative cases.
    $negativeFixture = $makeOrderReceipts('1.00000000', 'NEG-A');
    $negativeLine = $negativeFixture['receipts'][0]['line_id'];
    $expect(fn () => $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $otherSupplier->id,
        'supplier_invoice_number' => 'PH1-MISMATCH', 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '1.00000000']]]]]), 'supplier identity mismatch');
    $expect(fn () => $api->createSupplierInvoice((int) $denied->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH1-UNAUTHORIZED', 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '1.00000000']]]]]), 'unauthorized actor', 'forbidden');
    $expect(fn () => $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH1-OVERMATCH', 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '2.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '2.00000000']]]]]), 'receipt overmatch');
    $draftData = ['creation_key' => $uuid(), 'supplier_id' => $supplier->id, 'supplier_invoice_number' => 'PH1-DUP-'.strtoupper(Str::random(5)),
        'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $negativeFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $negativeLine, 'quantity' => '1.00000000']]]]];
    $draftId = $api->createSupplierInvoice((int) $maker->id, $draftData);
    $need($api->createSupplierInvoice((int) $maker->id, $draftData) === $draftId, 'Same-key draft replay changed invoice identity.');
    $duplicateFixture = $makeOrderReceipts('1.00000000', 'NEG-B');
    $expect(fn () => $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => $draftData['supplier_invoice_number'], 'invoice_date' => $date, 'due_date' => date('Y-m-d', strtotime($date.' + 30 days')), 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $duplicateFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $duplicateFixture['receipts'][0]['line_id'], 'quantity' => '1.00000000']]]]]), 'duplicate supplier invoice number', 'duplicate');
    $closedFixture = $makeOrderReceipts('1.00000000', 'NEG-CLOSED');
    $closedInvoice = $api->createSupplierInvoice((int) $maker->id, ['creation_key' => $uuid(), 'supplier_id' => $supplier->id,
        'supplier_invoice_number' => 'PH1-CLOSED-'.strtoupper(Str::random(5)), 'invoice_date' => '2038-01-01', 'due_date' => '2038-01-31', 'tax_mode' => 'exclusive',
        'lines' => [['order_line_id' => $closedFixture['order_line_id'], 'quantity' => '1.00000000', 'unit_price' => '11.00000000', 'discount_amount' => '0.00',
            'matches' => [['receipt_line_id' => $closedFixture['receipts'][0]['line_id'], 'quantity' => '1.00000000']]]]]);
    $api->validateSupplierInvoice((int) $maker->id, $closedInvoice, 1);
    $api->matchSupplierInvoice((int) $maker->id, $closedInvoice, 2);
    $api->approveSupplierInvoice((int) $maker->id, $closedInvoice, 3);
    $expect(fn () => $api->postSupplierInvoice((int) $maker->id, $closedInvoice, 4), 'closed accounting period');
    $need(DB::table('rp_supplier_invoices')->where('id', $closedInvoice)->where('status', 'approved')->exists()
        && ! DB::table('rp_open_items')->where('source_type', 'supplier_invoice')->where('source_id', $closedInvoice)->exists()
        && ! DB::table('accounting_journals')->where('source_key', 'supplier-invoice:'.$closedInvoice)->exists(), 'Closed-period rejection left partial AP/GL posting effects.');

    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $evidence = ['database' => $connection->db, 'postgres_port' => (int) $connection->port, 'financial_date' => $date,
        'synthetic' => true, 'source_order_id' => $main['order_id'], 'posted_receipts' => array_column($receipts, 'id'),
        'invoice_ids' => [$invoiceA, $invoiceB, $invoiceC], 'source_journals' => $journalTotals,
        'official_pdf_integrity' => $pdfEvidence,
        'matching' => ['invoice_count' => 3, 'receipt_count' => 3, 'quantity' => $matchQuantity, 'grni' => $matchGrni,
            'net' => '44.00', 'recoverable_input_tax' => $expectedTax, 'ppv' => '4.00', 'ap' => $postedGross],
        'stock_and_fifo_unchanged_by_invoice_posting' => true, 'same_key_replay' => true,
        'negative_cases' => ['duplicate_supplier_number' => 'rejected', 'supplier_mismatch' => 'rejected', 'overmatch' => 'rejected',
            'unauthorized' => '403', 'closed_period' => 'rejected_without_partial_post'], 'all_pass' => true];
} catch (Throwable $error) {
    $evidence = ['database' => $connection->db, 'postgres_port' => (int) $connection->port, 'all_pass' => false,
        'error_class' => $error::class, 'error' => mb_substr($error->getMessage(), 0, 1500), 'trace' => array_slice($error->getTrace(), 0, 8)];
    throw $error;
} finally {
    if ($db->transactionLevel() > 0) {
        DB::rollBack();
    }
    $json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    $evidencePath = $root.'/docs/qa/evidence/phase1-supplier-invoice.json';
    if (! is_dir(dirname($evidencePath))) {
        mkdir(dirname($evidencePath), 0770, true);
    }
    file_put_contents($evidencePath, $json, LOCK_EX);
    echo $json;
}

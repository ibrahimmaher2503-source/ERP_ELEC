<?php

declare(strict_types=1);

// Gate 5 serial-tracked opening balances through M11 and the owning Inventory contract.
use App\Application\ActivateFinancialPosting;
use App\Models\User;
use App\Modules\Accounting\Application\FinancialActivation;
use App\Modules\Accounting\Application\OpeningBalances;
use App\Modules\Accounting\Domain\AccountingAccount;
use App\Modules\Inventory\Application\InventoryActions;
use App\Modules\Organization\Application\SaveCompany;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Products\Domain\Product;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$root = dirname(__DIR__, 2);
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require $root.'/vendor/autoload.php';

$environment = ['APP_ENV' => 'testing', 'GITHUB_ACTIONS' => (string) (getenv('GITHUB_ACTIONS') ?: ''),
    'DB_HOST' => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('E2E_DB_PORT') ?: (getenv('DB_PORT') ?: '49685')),
    'DB_DATABASE' => (string) (getenv('DB_DATABASE') ?: 'erp_elec_new_test'),
    'DB_USERNAME' => (string) (getenv('DB_USERNAME') ?: 'postgres')];
require_once $root.'/tests/Support/OptimizationRegressionDatabaseGuard.php';
$target = Tests\Support\OptimizationRegressionDatabaseGuard::target($root, $environment);
if ($target['port'] !== 49685 || $target['database'] !== 'erp_elec_new_test' || $target['username'] !== 'postgres') {
    throw new RuntimeException('Serial financial opening requires the exact owned PostgreSQL target on port 49685.');
}

$taskRoot = $root.'/.tmp/master-remediation-20261101';
$privateStorage = $taskRoot.'/serial-opening-storage-'.bin2hex(random_bytes(8));
$cachePath = '.tmp/master-remediation-20261101/serial-opening-cache';
$viewsPath = '.tmp/master-remediation-20261101/serial-opening-views';
foreach ([$privateStorage, $root.'/'.$cachePath, $root.'/'.$viewsPath] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create private serial-opening runtime directory.');
    }
}
foreach (['DB_CONNECTION' => 'pgsql', 'DB_HOST' => $target['connection_host'], 'DB_PORT' => (string) $target['port'],
    'DB_DATABASE' => $target['database'], 'DB_USERNAME' => $target['username'], 'DB_PASSWORD' => (string) (getenv('DB_PASSWORD') ?: ''),
    'DB_URL' => '', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'LARAVEL_STORAGE_PATH' => $privateStorage, 'APP_CONFIG_CACHE' => $cachePath.'/config.php',
    'APP_ROUTES_CACHE' => $cachePath.'/routes.php', 'APP_EVENTS_CACHE' => $cachePath.'/events.php',
    'APP_SERVICES_CACHE' => $cachePath.'/services.php', 'APP_PACKAGES_CACHE' => $cachePath.'/packages.php',
    'APP_VIEW_COMPILED_PATH' => $viewsPath, 'VIEW_COMPILED_PATH' => $viewsPath,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'DEBUGBAR_ENABLED' => 'false', 'PULSE_ENABLED' => 'false'] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->useStoragePath($privateStorage);
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql.host' => $target['connection_host'],
    'database.connections.pgsql.port' => $target['port'], 'database.connections.pgsql.database' => $target['database'],
    'database.connections.pgsql.username' => $target['username'], 'database.connections.pgsql.password' => (string) (getenv('DB_PASSWORD') ?: ''),
    'database.connections.pgsql.url' => null, 'filesystems.disks.local.root' => $privateStorage,
    'filesystems.disks.local.visibility' => 'private', 'filesystems.disks.local.throw' => true,
    'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
$identity = DB::selectOne("select current_database() as database, host(inet_server_addr()) as server_address, inet_server_port() as port,
    current_setting('data_directory') as data_directory, current_user as current_user, current_setting('server_version_num') as server_version_num,
    (select owner.rolname from pg_database d join pg_roles owner on owner.oid=d.datdba where d.datname=current_database()) as database_owner");
$receiptRow = $target['ci_receipt'] !== null ? DB::selectOne('select nonce from codex_test_guard.ownership_receipts') : null;
Tests\Support\OptimizationRegressionDatabaseGuard::verify($target, $identity, $receiptRow);
if ((int) $identity->server_version_num < 170000 || (int) $identity->server_version_num >= 180000 || $identity->database_owner !== 'postgres') {
    throw new RuntimeException('Serial financial opening requires the marker-owned PostgreSQL 17 test database.');
}
$requiredTables = ['companies', 'branches', 'users', 'access_grants', 'user_branch_scopes', 'user_warehouse_scopes', 'inventory_warehouses',
    'products', 'accounting_activation', 'accounting_accounts', 'accounting_account_mappings', 'accounting_periods', 'accounting_tax_rules', 'accounting_tax_rule_endings',
    'accounting_opening_batches', 'accounting_opening_lines', 'accounting_journals', 'accounting_journal_lines', 'inventory_movements',
    'inventory_balances', 'inventory_fifo_layers', 'inventory_fifo_allocations', 'inventory_serials', 'inventory_serial_events',
    'inventory_fifo_serial_allocations', 'inventory_movement_journals', 'audit_entries'];
foreach ($requiredTables as $table) {
    if (! Schema::hasTable($table)) throw new RuntimeException("Serial financial opening requires migrated table {$table}.");
}
if (! DB::selectOne("select 1 from information_schema.columns where table_schema='public' and table_name='accounting_opening_lines' and column_name='serials'")) {
    throw new RuntimeException('Serial financial opening requires the forward serials column on accounting_opening_lines.');
}

$runId = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(5));
$evidenceDirectory = $taskRoot.'/serial-opening-evidence-'.$runId;
if (! mkdir($evidenceDirectory, 0700, true)) throw new RuntimeException('Cannot create private serial-opening evidence directory.');
$receiptPath = $evidenceDirectory.'/receipt.json';
$tableNames = $requiredTables;
$counts = static function () use ($tableNames): array {
    $result = [];
    foreach ($tableNames as $table) $result[$table] = (int) DB::table($table)->count();

    return $result;
};
$assertions = 0;
$passed = [];
$diagnostics = [];
$stage = 'baseline and transactional synthetic company';
$assert = static function (bool $condition, string $message) use (&$assertions, &$passed): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
    $passed[] = $message;
    fwrite(STDOUT, 'PASS '.$message.PHP_EOL);
};
$assertDetails = static function (bool $condition, string $message, array $expected, array $actual) use ($assert, &$diagnostics): void {
    $diagnostics[] = ['assertion' => $message, 'expected' => $expected, 'actual' => $actual];
    $assert($condition, $message);
};
$reject = static function (callable $operation, string $message, string $class = ValidationException::class) use ($assert): Throwable {
    try {
        $operation();
    } catch (Throwable $error) {
        $accepted = $class === 'authorization'
            ? ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 403)
            : $error instanceof $class;
        $assert($accepted, $message.' (actual '.$error::class.')');

        return $error;
    }
    throw new RuntimeException($message.' (expected exception was not thrown)');
};
$grant = static function (User $actor, array $permissions, ?int $branch = null): void {
    foreach ($permissions as $permission) DB::table('access_grants')->insertOrIgnore(['user_id' => $actor->id,
        'permission_code' => $permission, 'branch_id' => $branch,
        'created_at' => now(), 'updated_at' => now()]);
};
$uuid = static fn (): string => (string) Str::uuid();
$beforeCounts = $counts();
$companyBefore = DB::table('companies')->where('id', 1)->first();
$activationBefore = DB::table('accounting_activation')->where('id', 1)->first();
if (! $activationBefore || $activationBefore->activated_at !== null) {
    throw new RuntimeException('ABORT before fixture writes: isolated target must have an unactivated M11 baseline.');
}
$error = null;
$rollbackVerified = false;
$companyRestored = false;
$activationRestored = false;
$afterCounts = [];
$stageArguments = [];
DB::beginTransaction();
try {
    $creator = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $scopeDenied = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $grant($creator, ['M01.company_update', 'M11.setup_manage', 'M11.tax_manage', 'M11.opening_manage', 'M11.opening_approve',
        'M11.tax_calculate', 'M02.product_view', 'M06.warehouse_create']);
    $grant($reviewer, ['M11.opening_approve', 'M11.financial_activate', 'M02.product_view']);

    app(SaveCompany::class)->execute($creator, ['code' => $companyBefore->code ?? 'G5-SERIAL-OPEN',
        'name' => $companyBefore->name ?? 'Gate 5 synthetic serial-opening test', 'legal_name' => 'Rollback-only Gate 5 Serial Opening Company',
        'tax_registration' => 'G5-SERIAL-OPEN-'.Str::upper(Str::random(6)), 'address' => 'Rollback-only synthetic address, Cairo, Egypt'],
        $companyBefore ? (int) $companyBefore->version : null);
    $branch = Branch::factory()->create(['code' => 'G5SOP-'.Str::upper(Str::random(7)), 'company_id' => 1, 'active' => true]);
    foreach ([$creator, $reviewer] as $actor) DB::table('user_branch_scopes')->insert(['user_id' => $actor->id,
        'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    $grant($creator, ['M01.branch_view', 'M11.opening_entry', 'M11.receipt_post', 'M11.journal_post'], (int) $branch->id);
    $grant($reviewer, ['M01.branch_view', 'M11.opening_review', 'M11.receipt_post', 'M11.journal_post'], (int) $branch->id);
    $grant($scopeDenied, ['M11.opening_manage', 'M11.opening_entry'], (int) $branch->id);

    $stage = 'serial inventory master and warehouse scopes';
    $warehouse = app(InventoryActions::class)->saveWarehouse((int) $creator->id, ['code' => 'G5SOP-WH-'.Str::upper(Str::random(5)),
        'name' => 'مستودع افتتاح مسلسلات تجريبي', 'branch_id' => $branch->id, 'active' => true]);
    foreach ([$creator, $reviewer] as $actor) {
        DB::table('user_warehouse_scopes')->insert(['user_id' => $actor->id, 'warehouse_id' => $warehouse['id'],
            'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $grant($creator, ['M06.balance_view', 'M06.receipt_post'], (int) $branch->id);
    $grant($reviewer, ['M06.balance_view', 'M06.receipt_post'], (int) $branch->id);
    $serialProduct = Product::factory()->create(['kind' => 'serialized_equipment', 'tracking' => 'serial', 'active' => true]);
    $quantityProduct = Product::factory()->create(['kind' => 'bulk', 'tracking' => 'quantity', 'active' => true]);

    $stage = 'M11 mappings, period, and validated synthetic tax treatment';
    $mappings = [];
    foreach (FinancialActivation::MAPPINGS as $purpose => [$label, $kind]) {
        $mappings[$purpose] = AccountingAccount::factory()->create(['code' => 'G5SOP-'.Str::upper($purpose).'-'.Str::upper(Str::random(5)),
            'name' => 'Synthetic '.$label, 'kind' => $kind])->id;
    }
    $equityAccount = AccountingAccount::factory()->create(['code' => 'G5SOP-EQUITY-'.Str::upper(Str::random(5)),
        'name' => 'Synthetic opening equity', 'kind' => 'equity'])->id;
    $goLiveDate = now('Africa/Cairo')->subDay()->toDateString();
    DB::table('accounting_periods')->insert(['name' => 'Gate5 serial opening '.Str::upper(Str::random(6)),
        'starts_on' => now('Africa/Cairo')->startOfYear()->toDateString(), 'ends_on' => now('Africa/Cairo')->endOfYear()->toDateString(),
        'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    $setup = app(FinancialActivation::class);
    $version = (int) DB::table('accounting_activation')->where('id', 1)->value('version');
    $setup->save((int) $creator->id, $version, ['go_live_date' => $goLiveDate, 'mappings' => $mappings]);
    $version++;
    foreach (['sale', 'purchase'] as $type) {
        $tax = ['code' => 'G5SOP-TAX-'.Str::upper($type).'-'.Str::upper(Str::random(5)), 'name' => 'Synthetic serial opening '.$type.' tax',
            'transaction_type' => $type, 'product_id' => null, 'rate' => '14', 'starts_on' => $goLiveDate, 'ends_on' => null,
            'treatment' => 'percentage', 'tax_scale' => 2, 'legal_reference' => 'Synthetic isolated serial-opening check only'];
        if ($type === 'purchase') $tax['purchase_recoverable'] = true;
        $setup->addTaxRule((int) $creator->id, $version++, $tax);
    }
    $setup->validateTaxTreatment((int) $creator->id, $version++, 'Synthetic serialized opening tax treatment verification');
    $opening = app(OpeningBalances::class);
    $serials = ['G5-OPEN-SERIAL-A'];
    $openingLines = static function (array $serialList, string $quantity = '1.00000000', string $amount = '10.00') use ($branch, $warehouse, $serialProduct, $mappings, $equityAccount): array {
        return [
            ['kind' => 'gl', 'reference' => 'SERIAL-INVENTORY', 'branch_id' => $branch->id, 'account_id' => $mappings['inventory'], 'debit' => $amount, 'credit' => '0.00'],
            ['kind' => 'gl', 'reference' => 'OPENING-EQUITY', 'branch_id' => $branch->id, 'account_id' => $equityAccount, 'debit' => '0.00', 'credit' => $amount],
            ['kind' => 'inventory', 'reference' => 'SERIAL-OPENING-001', 'branch_id' => $branch->id, 'warehouse_id' => $warehouse['id'],
                'product_id' => $serialProduct->id, 'quantity' => $quantity, 'unit_cost' => '10.0000000000000000', 'amount' => $amount, 'serials' => $serialList],
        ];
    };
    $batchCounts = static fn (): array => ['batches' => (int) DB::table('accounting_opening_batches')->count(),
        'lines' => (int) DB::table('accounting_opening_lines')->count(), 'audit' => (int) DB::table('audit_entries')->count()];
    $badCounts = $batchCounts();
    $reject(fn () => $opening->save((int) $creator->id, $version, $uuid(), $openingLines([]), false, 'Insufficient synthetic serial set'),
        'Serialized opening rejects no serials for one base unit');
    $reject(fn () => $opening->save((int) $creator->id, $version, $uuid(), $openingLines(['G5-OPEN-SERIAL-A', 'G5-OPEN-SERIAL-B']), false,
        'Nonmatching synthetic serial count'), 'Serialized opening rejects more serials than the declared base quantity');
    $reject(fn () => $opening->save((int) $creator->id, $version, $uuid(), $openingLines(['G5-OPEN-SERIAL-A', ' g5-open-serial-a '], '2.00000000', '20.00'), false,
        'Duplicate synthetic serial identity'), 'Serialized opening rejects duplicate normalized serials');
    $nonSerialLines = $openingLines(['SHOULD-NOT-APPLY']);
    $nonSerialLines[2]['product_id'] = $quantityProduct->id;
    $reject(fn () => $opening->save((int) $creator->id, $version, $uuid(), $nonSerialLines, false,
        'Nonserialized product with serial identity'), 'Quantity-tracked opening rejects nonempty serial identities');
    $assert($badCounts === $batchCounts(), 'Rejected serial validation creates no opening batches, lines, or audit events');

    $stage = 'scope denial and exact opening draft persistence';
    $beforeScopeDeny = $batchCounts();
    $reject(fn () => $opening->save((int) $scopeDenied->id, $version, $uuid(), $openingLines($serials), false,
        'Scoped denial check with synthetic serials'), 'Opening entry denies a granted actor without branch membership', 'authorization');
    $assert($beforeScopeDeny === $batchCounts(), 'Branch-scope denial leaves opening batch, lines, and audit unchanged');

    $creationKey = $uuid();
    $batch = $opening->save((int) $creator->id, $version, $creationKey, $openingLines($serials), false,
        'Rollback-only exact serialized inventory opening evidence');
    $storedLine = DB::table('accounting_opening_lines')->where('batch_id', $batch)->where('kind', 'inventory')->sole();
    $apiLine = collect($opening->lines($batch))->firstWhere('kind', 'inventory');
    $assertDetails(json_decode($storedLine->serials, true, 512, JSON_THROW_ON_ERROR) === $serials
        && ($apiLine['serials'] ?? null) === $serials && (string) $storedLine->quantity === '1.00000000'
        && (string) $storedLine->unit_cost === '10.0000000000000000' && (string) $storedLine->amount === '10.00',
        'Opening draft preserves exact serial identities and source quantity/cost/value strings',
        ['serials' => $serials, 'quantity' => '1.00000000', 'unit_cost' => '10.0000000000000000', 'amount' => '10.00'],
        ['serials' => json_decode($storedLine->serials, true, 512, JSON_THROW_ON_ERROR), 'lines_api_serials' => $apiLine['serials'] ?? null,
            'quantity' => (string) $storedLine->quantity, 'unit_cost' => (string) $storedLine->unit_cost, 'amount' => (string) $storedLine->amount]);
    $opening->advance((int) $creator->id, $batch, 1, 'submit');
    $reject(fn () => $opening->advance((int) $creator->id, $batch, 2, 'approve'),
        'Opening batch creator cannot approve the same serial opening');
    $assert(DB::table('accounting_opening_batches')->where('id', $batch)->where('status', 'submitted')->whereNull('approved_by')->exists(),
        'Self-approval denial leaves the submitted opening unchanged');
    $opening->advance((int) $reviewer->id, $batch, 2, 'approve');

    $stage = 'independent M11 financial activation and inventory source effects';
    app(ActivateFinancialPosting::class)->execute((int) $reviewer->id, $batch, 3);
    $movement = DB::table('inventory_movements')->where('operation', 'financial_opening')->where('source_module', 'Accounting')
        ->where('source_id', (string) $batch)->where('source_line', 'SERIAL-OPENING-001')->sole();
    $layer = DB::table('inventory_fifo_layers')->where('movement_id', $movement->id)->sole();
    $serialRows = DB::table('inventory_serials')->whereIn('normalized', $serials)->orderBy('normalized')->get();
    $serialEvents = DB::table('inventory_serial_events')->where('movement_id', $movement->id)->where('action', 'received')->orderBy('serial_id')->get();
    $fifoSerialRows = DB::table('inventory_fifo_serial_allocations as fs')->join('inventory_serials as s', 's.id', '=', 'fs.serial_id')
        ->where('fs.movement_id', $movement->id)->orderBy('s.normalized')->get(['s.normalized', 'fs.amount']);
    $balance = DB::table('inventory_balances')->where('warehouse_id', $warehouse['id'])->where('product_id', $serialProduct->id)->sole();
    $journal = DB::table('accounting_journals')->where('opening_batch_id', $batch)->where('status', 'posted')->sole();
    $journalLines = DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->orderBy('id')->get();
    $inventoryDebit = (string) DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->where('account_id', $mappings['inventory'])->sum('debit');
    $equityCredit = (string) DB::table('accounting_journal_lines')->where('journal_id', $journal->id)->where('account_id', $equityAccount)->sum('credit');
    $assertDetails($movement->quantity === '1.00000000' && $movement->idempotency_key === 'opening:'.$batch.':SERIAL-OPENING-001'
        && $layer->original_quantity === '1.00000000' && $layer->original_value === '10.00'
        && $layer->remaining_value === '10.0000000000000000' && $layer->unit_cost === '10.0000000000000000'
        && $layer->remaining_quantity === '1.00000000'
        && $serialRows->count() === 1 && $serialRows[0]->normalized === $serials[0] && $serialRows[0]->entered === $serials[0]
        && (int) $serialRows[0]->source_movement_id === (int) $movement->id && (int) $serialRows[0]->warehouse_id === (int) $warehouse['id']
        && $serialRows[0]->state === 'available' && $serialEvents->count() === 1 && (int) $serialEvents[0]->serial_id === (int) $serialRows[0]->id
        && $fifoSerialRows->isEmpty()
        && $balance->on_hand === '1.00000000' && $balance->reserved === '0.00000000'
        && $journalLines->count() === 2 && bccomp($inventoryDebit, '10.00', 2) === 0 && bccomp($equityCredit, '10.00', 2) === 0
        && DB::table('accounting_opening_batches')->where('id', $batch)->where('status', 'posted')->exists()
        && DB::table('accounting_activation')->where('id', 1)->whereNotNull('activated_at')->exists(),
        'Independent activation creates one real serial source, exact $10 FIFO valuation, available balance and balanced inventory/equity GL without inventing an issue allocation',
        ['movement_quantity' => '1.00000000', 'serial' => $serials[0], 'fifo_original_value' => '10.00',
            'fifo_remaining_quantity' => '1.00000000', 'fifo_remaining_value' => '10.0000000000000000',
            'outgoing_serial_fifo_allocations' => 0, 'opening_status' => 'posted', 'activation_recorded' => true,
            'inventory_debit' => '10.00', 'equity_credit' => '10.00', 'journal_lines' => 2, 'on_hand' => '1.00000000'],
        ['movement_quantity' => (string) $movement->quantity, 'source_key' => $movement->idempotency_key,
            'fifo' => ['quantity' => (string) $layer->original_quantity, 'original_value' => (string) $layer->original_value,
                'remaining_quantity' => (string) $layer->remaining_quantity, 'remaining_value' => (string) $layer->remaining_value,
                'unit_cost' => (string) $layer->unit_cost],
            'serials' => $serialRows->map(fn ($row): array => ['normalized' => $row->normalized, 'entered' => $row->entered,
                'source_movement_id' => (int) $row->source_movement_id, 'state' => $row->state])->all(),
            'serial_events' => $serialEvents->count(), 'serial_fifo' => $fifoSerialRows->map(fn ($row): array => ['serial' => $row->normalized, 'amount' => (string) $row->amount])->all(),
            'balance_on_hand' => (string) $balance->on_hand, 'journal_line_count' => $journalLines->count(),
            'opening_status' => DB::table('accounting_opening_batches')->where('id', $batch)->value('status'),
            'activation_recorded' => DB::table('accounting_activation')->where('id', 1)->whereNotNull('activated_at')->exists(),
            'inventory_debit' => $inventoryDebit, 'equity_credit' => $equityCredit]);

    $snapshotForReplay = static function () use ($tableNames): string {
        $rows = [];
        foreach (['accounting_opening_batches', 'accounting_opening_lines', 'accounting_activation', 'accounting_account_mappings',
            'inventory_movements', 'inventory_balances', 'inventory_fifo_layers', 'inventory_fifo_allocations', 'inventory_serials',
            'inventory_serial_events', 'inventory_fifo_serial_allocations', 'accounting_journals', 'accounting_journal_lines', 'audit_entries'] as $table) {
            $primaryKey = $table === 'accounting_account_mappings' ? 'purpose' : 'id';
            $rows[$table] = DB::table($table)->orderBy($primaryKey)->get()->map(fn ($row): array => (array) $row)->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    };
    $replayBefore = $snapshotForReplay();
    $reject(fn () => app(ActivateFinancialPosting::class)->execute((int) $reviewer->id, $batch, 3),
        'Exact opening activation replay is denied after activation');
    $assert(hash_equals($replayBefore, $snapshotForReplay()), 'Denied exact activation replay changes no opening, inventory, serial, FIFO, journal, or audit row');

    $stage = 'deferred source, serial, FIFO, and GL constraints';
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $assert(true, 'SET CONSTRAINTS ALL IMMEDIATE validates every deferred Gate5 opening/serial/FIFO/GL constraint');
} catch (Throwable $caught) {
    $error = $caught;
} finally {
    if (DB::transactionLevel() > 0) DB::rollBack();
    $afterCounts = $counts();
    $companyAfter = DB::table('companies')->where('id', 1)->first();
    $activationAfter = DB::table('accounting_activation')->where('id', 1)->first();
    $companyRestored = ($companyBefore === null && $companyAfter === null)
        || (is_object($companyBefore) && is_object($companyAfter) && (array) $companyBefore === (array) $companyAfter);
    $activationRestored = is_object($activationAfter) && (array) $activationAfter === (array) $activationBefore;
    $rollbackVerified = $beforeCounts === $afterCounts && $companyRestored && $activationRestored;
    Illuminate\Support\Facades\File::deleteDirectory($privateStorage);
}
$result = ['all_pass' => $error === null && $rollbackVerified, 'mode' => 'gate5-serial-financial-opening', 'phase' => $stage,
    'phase_arguments' => $stageArguments, 'database' => $target['database'], 'port' => $target['port'],
    'server_version_num' => (int) $identity->server_version_num, 'assertions' => $assertions,
    'rollback_verified' => $rollbackVerified, 'company_baseline_restored' => $companyRestored,
    'activation_baseline_restored' => $activationRestored, 'before_counts' => $beforeCounts, 'after_counts' => $afterCounts,
    'passed' => $passed, 'private_diagnostics' => $diagnostics,
    'failure' => $error ? ['class' => $error::class, 'message' => mb_substr($error->getMessage(), 0, 700),
        'trace_frames' => array_map(static fn (array $frame): array => array_filter(['file' => isset($frame['file']) ? basename($frame['file']) : null,
            'line' => $frame['line'] ?? null, 'class' => $frame['class'] ?? null, 'function' => $frame['function'] ?? null], static fn (mixed $value): bool => $value !== null), array_slice($error->getTrace(), 0, 12))] : null,
    'recorded_at_utc' => gmdate(DATE_ATOM)];
file_put_contents($receiptPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX);
@chmod($receiptPath, 0600);
echo 'RESULT all_pass='.(int) $result['all_pass'].' assertions='.$assertions.' rollback_verified='.(int) $rollbackVerified.' phase='.$stage.' receipt='.$receiptPath.PHP_EOL;
if (! $result['all_pass']) exit(1);

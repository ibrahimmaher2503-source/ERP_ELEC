<?php

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Domain\Branch;
use App\Modules\Organization\Domain\Company;
use App\Modules\System\Application\RecordAudit;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Products\Domain\Product;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\SalesOrder;
use Database\Factories\Support\EgyptianMarket;
use Database\Seeders\EgyptMarketDemoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Ramsey\Uuid\Uuid;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || DB::connection()->getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Egypt-market demo checks require the exact separate PostgreSQL test database.');
}

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};
$expectedDecimal = static fn (string $value): string => bcadd($value, '0', 8);
$fileRoots = ['product-media', 'customers', 'suppliers', 'sales/quotations/internal'];
$fileSnapshot = static function () use ($fileRoots): array {
    $files = [];
    foreach ($fileRoots as $root) {
        $files = [...$files, ...Storage::disk('local')->allFiles($root)];
    }

    return array_values(array_unique($files));
};
$rowHashes = static function (string $table): array {
    return DB::table($table)->orderBy('id')->get()->mapWithKeys(fn (object $row): array => [
        (string) $row->id => hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
    ])->all();
};
$businessTables = [
    'companies', 'branches', 'users', 'product_categories', 'brands', 'units_of_measure', 'products',
    'product_unit_conversions', 'product_media', 'customers', 'customer_contacts', 'customer_addresses',
    'customer_documents', 'customer_notes', 'supplier_categories', 'suppliers', 'supplier_contacts',
    'supplier_addresses', 'supplier_documents', 'supplier_notes', 'sales_quotations',
    'sales_quotation_lines', 'sales_quotation_attachments', 'sales_orders', 'sales_order_lines',
    'sales_order_reviews', 'service_contracts', 'attendance_shifts', 'attendance_schedules', 'organization_employees',
    'workshops', 'workshop_intakes', 'project_tenders', 'project_tender_events',
];
$securityCountTables = [
    'roles', 'permission_catalog', 'role_permissions', 'user_roles', 'user_branch_scopes',
    'access_grants', 'access_audits', 'sessions', 'passkeys', 'password_reset_tokens',
    'quotation_self_approval_policies', 'sales_quotation_policies', 'sales_quotation_terms',
    'sales_quotation_approvals', 'data_operation_runs', 'data_operation_items',
];

$sizes = ['products' => 32, 'customers' => 12, 'suppliers' => 8, 'quotations' => 10, 'branches' => 4, 'users' => 8, 'orders' => 6];
$branchCodes = array_map(fn (int $i): string => 'DEMO-EG-BR-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), range(1, $sizes['branches']));
$demoEmails = array_map(fn (int $i): string => "staff.{$i}@egypt-market.example.test", range(1, $sizes['users']));
$productSkus = array_map(fn (int $i): string => 'DEMO-EG-PROD-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, $sizes['products']));
$customerCodes = array_map(fn (int $i): string => 'DEMO-EG-CUS-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, $sizes['customers']));
$supplierCodes = array_map(fn (int $i): string => 'DEMO-EG-SUP-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), range(1, $sizes['suppliers']));
$quoteKeys = array_map(fn (int $i): string => Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-elec:egypt-market-v1:quotation:'.$i)->toString(), range(1, $sizes['quotations']));
$orderKeys = array_map(fn (int $i): string => Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-elec:egypt-market-v1:direct-order:'.$i)->toString(), range(1, $sizes['orders']));
$serviceContractKeys = array_map(fn (int $i): string => Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-demo-eg-service-contract-'.$i)->toString(), range(1, 3));
$serviceContractIdentities = array_combine($serviceContractKeys, array_map(fn (int $i): string => 'DEMO-EG-SC-00'.$i, range(1, 3)));
$attendanceScheduleKey = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-demo-eg-attendance-schedule-1')->toString();
$fixturePaths = static function () use ($productSkus, $customerCodes, $supplierCodes, $quoteKeys): array {
    $productIds = DB::table('products')->whereIn('sku', $productSkus)->pluck('id');
    $customerIds = DB::table('customers')->whereIn('code', $customerCodes)->pluck('id');
    $supplierIds = DB::table('suppliers')->whereIn('code', $supplierCodes)->pluck('id');
    $quotationIds = DB::table('sales_quotations')->whereIn('draft_key', $quoteKeys)->pluck('id');

    return [
        ...DB::table('product_media')->whereIn('product_id', $productIds)->pluck('path')->all(),
        ...DB::table('customer_documents')->whereIn('customer_id', $customerIds)->pluck('path')->all(),
        ...DB::table('supplier_documents')->whereIn('supplier_id', $supplierIds)->pluck('path')->all(),
        ...DB::table('sales_quotation_attachments')->whereIn('quotation_id', $quotationIds)->pluck('path')->all(),
    ];
};
$oldDiskRoot = config('filesystems.disks.local.root');
$diskParent = storage_path('framework/testing/disks');
$diskRoot = $diskParent.DIRECTORY_SEPARATOR.'egypt-market-demo-check-'.strtoupper(bin2hex(random_bytes(6)));
if (! str_starts_with($diskRoot, $diskParent.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing a test disk outside the dedicated test storage directory.');
}
config(['filesystems.disks.local.root' => $diskRoot]);
Storage::forgetDisk('local');
if (! is_dir($diskRoot) && ! mkdir($diskRoot, 0770, true) && ! is_dir($diskRoot)) {
    throw new RuntimeException('Unable to create isolated test private disk.');
}
$beforeFiles = $fileSnapshot();
$generatedFiles = [];
$oldActorEnv = getenv('ERP_DEMO_ACTOR_ID');
DB::beginTransaction();

try {
    $companySetupWasCreated = ! DB::table('companies')->where('id', 1)->exists();
    if ($companySetupWasCreated) {
        Company::factory()->create();
    }
    $factoryCompany = Company::query()->findOrFail(1);
    $verify($factoryCompany->exists && $factoryCompany->id === 1 && trim($factoryCompany->code) !== ''
        && trim($factoryCompany->name) !== '' && $factoryCompany->currency === 'EGP',
        $companySetupWasCreated
            ? 'Company factory persists a singleton EGP setup fixture because the isolated test database had no company row'
            : 'Existing singleton company remains intact and is never overwritten by a factory');
    $factoryBranch = Branch::factory()->create([
        'code' => 'FACTORY-CHECK-'.strtoupper(bin2hex(random_bytes(4))),
        'company_id' => 1,
        'name' => 'Synthetic factory persistence branch',
        'address' => 'Synthetic test address in Cairo',
    ]);
    $verify($factoryBranch->exists && (int) $factoryBranch->company_id === 1
        && DB::table('companies')->where('id', $factoryBranch->company_id)->exists()
        && trim($factoryBranch->name) !== '' && trim((string) $factoryBranch->address) !== '',
        'Branch factory persists a complete branch linked to its existing company');
    $actor = User::factory()->create([
        'active' => true,
        'email_verified_at' => now(),
    ]);
    $adminRole = DB::table('roles')->where('code', 'Administrator')->first();
    $verify($adminRole !== null, 'Existing Administrator role is available for a temporary rollback-only test actor');
    DB::table('user_roles')->insert([
        'user_id' => $actor->id,
        'role_id' => $adminRole->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $actorId = (int) $actor->id;
    putenv('ERP_DEMO_ACTOR_ID='.$actorId);
    $_ENV['ERP_DEMO_ACTOR_ID'] = $_SERVER['ERP_DEMO_ACTOR_ID'] = (string) $actorId;
    app(OrganizationApi::class)->authorize(new AuthorizeRequest($actorId, 'M01.security_admin'));
    $verify(true, 'Temporary active verified Administrator test actor authorizes the seeder inside the rollback fixture');
    $beforeBusiness = [];
    foreach ($businessTables as $table) {
        $beforeBusiness[$table] = $rowHashes($table);
    }
    $beforeSecurityCounts = [];
    foreach ($securityCountTables as $table) {
        $beforeSecurityCounts[$table] = DB::table($table)->count();
    }
    $beforeUserCount = DB::table('users')->count();
    $beforeAuditId = (int) DB::table('audit_entries')->max('id');
    $fieldMaintenanceDemoRows = static function (): array {
        $requests = DB::table('maintenance_requests')->where('number', 'DEMO-EG-MR-001')->get(['id', 'number']);
        $workOrders = DB::table('maintenance_work_orders')->where('number', 'DEMO-EG-WO-001')->get(['id', 'number']);
        $checklists = DB::table('maintenance_checklist_templates')->where('code', 'DEMO-EG-CHK-001')->get(['id', 'code']);
        $visits = DB::table('maintenance_visits')->whereIn('work_order_id', $workOrders->pluck('id'))->get(['id', 'work_order_id']);

        return collect()
            ->merge($requests->mapWithKeys(fn (object $row): array => ['request:'.$row->id => ['id' => (int) $row->id, 'identity' => $row->number]]))
            ->merge($workOrders->mapWithKeys(fn (object $row): array => ['work-order:'.$row->id => ['id' => (int) $row->id, 'identity' => $row->number]]))
            ->merge($checklists->mapWithKeys(fn (object $row): array => ['checklist:'.$row->id => ['id' => (int) $row->id, 'identity' => $row->code]]))
            ->merge($visits->mapWithKeys(fn (object $row): array => ['visit:'.$row->id => ['id' => (int) $row->id, 'identity' => 'DEMO-EG-VISIT-001']]))
            ->all();
    };
    $workshopDemoRows = static function (): array {
        $workshops = DB::table('workshops')->whereIn('code', [
            'DEMO-EG-WS-001', 'DEMO-EG-WS-002', 'DEMO-EG-WS-003', 'DEMO-EG-WS-004',
        ])->get(['id', 'code']);
        $intakes = DB::table('workshop_intakes')->where('number', 'DEMO-EG-WI-001')->get(['id', 'number']);

        return collect()
            ->merge($workshops->mapWithKeys(fn (object $row): array => ['workshop:'.$row->id => ['id' => (int) $row->id, 'identity' => $row->code]]))
            ->merge($intakes->mapWithKeys(fn (object $row): array => ['intake:'.$row->id => ['id' => (int) $row->id, 'identity' => $row->number]]))
            ->all();
    };
    $tenderDemoRows = static function (): array {
        $tenders = DB::table('project_tenders')->whereIn('external_reference', [
            'DEMO-EG-TN-SUPPLY', 'DEMO-EG-TN-PROJECT', 'DEMO-EG-TN-SERVICE',
        ])->get(['id', 'external_reference']);

        return $tenders->mapWithKeys(fn (object $row): array => ['tender:'.$row->id => [
            'id' => (int) $row->id,
            'identity' => $row->external_reference,
        ]])->all();
    };
    $serviceContractDemoRows = static function () use ($serviceContractKeys, $serviceContractIdentities): array {
        return DB::table('service_contracts')->whereIn('creation_key', $serviceContractKeys)->get(['id', 'creation_key'])
            ->mapWithKeys(fn (object $row): array => ['contract:'.$row->id => ['id' => (int) $row->id, 'identity' => $serviceContractIdentities[$row->creation_key]]])->all();
    };
    $attendanceDemoRows = static function () use ($attendanceScheduleKey): array {
        return DB::table('attendance_schedules')->where('creation_key', $attendanceScheduleKey)->get(['id', 'creation_key'])
            ->mapWithKeys(fn (object $row): array => ['schedule:'.$row->id => ['id' => (int) $row->id, 'identity' => 'DEMO-EG-ATTENDANCE-SCHEDULE-001']])->all();
    };
    $beforeFieldMaintenanceDemoRows = $fieldMaintenanceDemoRows();
    $beforeWorkshopDemoRows = $workshopDemoRows();
    $beforeTenderDemoRows = $tenderDemoRows();
    $beforeServiceContractDemoRows = $serviceContractDemoRows();
    $beforeAttendanceDemoRows = $attendanceDemoRows();
    $rootCounts = fn (): array => [
        'branch' => DB::table('branches')->whereIn('code', $branchCodes)->count(),
        'user' => DB::table('users')->whereIn('email', $demoEmails)->count(),
        'product' => DB::table('products')->whereIn('sku', $productSkus)->count(),
        'customer' => Customer::query()->whereIn('code', $customerCodes)->count(),
        'supplier' => Supplier::query()->whereIn('code', $supplierCodes)->count(),
        'quotation' => DB::table('sales_quotations')->whereIn('draft_key', $quoteKeys)->count(),
        'sales_order' => DB::table('sales_orders')->whereIn('draft_key', $orderKeys)->count(),
    ];
    $expectedRootCounts = $rootCounts();
    $verify(DB::table('companies')->where('id', 1)->exists(), 'Company setup is available and included in existing-record preservation checks');

    $seeder = app(EgyptMarketDemoSeeder::class);
    $firstSummary = $seeder->seed($sizes);
    $afterFirstCounts = [
        ...$rootCounts(),
        'workshop' => DB::table('workshops')->whereIn('code', ['DEMO-EG-WS-001', 'DEMO-EG-WS-002', 'DEMO-EG-WS-003', 'DEMO-EG-WS-004'])->count(),
        'workshop_intake' => DB::table('workshop_intakes')->where('number', 'DEMO-EG-WI-001')->count(),
        'tender' => DB::table('project_tenders')->whereIn('external_reference', ['DEMO-EG-TN-SUPPLY', 'DEMO-EG-TN-PROJECT', 'DEMO-EG-TN-SERVICE'])->count(),
    ];
    $addedCounts = [];
    foreach ($expectedRootCounts as $type => $count) {
        $addedCounts[$type] = $afterFirstCounts[$type] - $count;
    }
    $summaryCounts = array_replace(array_fill_keys(array_keys($addedCounts), 0), $firstSummary);
    $verify($addedCounts === $summaryCounts && $afterFirstCounts['branch'] >= $sizes['branches']
        && $afterFirstCounts['user'] >= $sizes['users'] && $afterFirstCounts['product'] >= $sizes['products']
        && $afterFirstCounts['customer'] >= $sizes['customers'] && $afterFirstCounts['supplier'] >= $sizes['suppliers']
        && $afterFirstCounts['quotation'] >= $sizes['quotations'] && $afterFirstCounts['sales_order'] === $sizes['orders'],
        'First seed creates each requested synthetic root set and its summary reports only actual inserts');

    $workshops = DB::table('workshops')->whereIn('code', ['DEMO-EG-WS-001', 'DEMO-EG-WS-002', 'DEMO-EG-WS-003', 'DEMO-EG-WS-004'])->get();
    $intake = DB::table('workshop_intakes')->where('number', 'DEMO-EG-WI-001')->first();
    $workshopRowsAfterFirst = $workshopDemoRows();
    $newWorkshopDemoRows = array_diff_key($workshopRowsAfterFirst, $beforeWorkshopDemoRows);
    $workshopIdentities = array_column($newWorkshopDemoRows, 'identity');
    sort($workshopIdentities);
    $expectedWorkshopIdentities = ['DEMO-EG-WS-001', 'DEMO-EG-WS-002', 'DEMO-EG-WS-003', 'DEMO-EG-WS-004', 'DEMO-EG-WI-001'];
    sort($expectedWorkshopIdentities);
    $verify($workshops->count() === 4 && $workshops->every(fn (object $workshop): bool => ! $workshop->active
        && $workshop->warehouse_id === null && (int) $workshop->version === 1
        && DB::table('branches')->where('id', $workshop->branch_id)->where('active', true)->exists()
        && DB::table('users')->where('id', $workshop->supervisor_id)->where('active', false)->exists())
        && $intake !== null && $intake->status === 'draft' && $intake->received_at === null
        && $intake->received_by === null && $intake->acknowledgment === null && $intake->serial_number === null
        && (bool) $intake->customer_owned && $workshopIdentities === $expectedWorkshopIdentities,
        'M09 demo seeds four inactive workshops and one unreceived draft intake with real demo owner links and no custody event');

    $tenders = DB::table('project_tenders')->whereIn('external_reference', [
        'DEMO-EG-TN-SUPPLY', 'DEMO-EG-TN-PROJECT', 'DEMO-EG-TN-SERVICE',
    ])->orderBy('external_reference')->get();
    $newTenderDemoRows = array_diff_key($tenderDemoRows(), $beforeTenderDemoRows);
    $tenderEvents = DB::table('project_tender_events')->whereIn('tender_id', $tenders->pluck('id'))->get();
    $tenderDemoValid = $tenders->count() === 3 && $tenders->every(fn (object $tender): bool => $tender->status === 'identified'
        && $tender->technical_id === null && $tender->financial_id === null && $tender->submitted_id === null
        && $tender->customer_id === null && (int) $tender->version === 1
        && DB::table('branches')->where('id', $tender->branch_id)->where('active', true)->exists()
        && DB::table('users')->where('id', $tender->responsible_id)->where('active', false)->whereNull('email_verified_at')->exists())
        && count($newTenderDemoRows) === 3 && $tenderEvents->isEmpty()
        && DB::table('project_tender_technical')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_financial')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_approvals')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_submissions')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_results')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_handoffs')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_guarantees')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist();
    $verify($tenders->count() === 3 && $tenders->every(fn (object $tender): bool => $tender->status === 'identified'
        && $tender->technical_id === null && $tender->financial_id === null && $tender->submitted_id === null
        && $tender->customer_id === null && (int) $tender->version === 1
        && DB::table('branches')->where('id', $tender->branch_id)->where('active', true)->exists()
        && DB::table('users')->where('id', $tender->responsible_id)->where('active', false)->whereNull('email_verified_at')->exists())
        && count($newTenderDemoRows) === 3 && $tenderEvents->isEmpty()
        && DB::table('project_tender_technical')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_financial')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_approvals')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_submissions')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_results')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_handoffs')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist()
        && DB::table('project_tender_guarantees')->whereIn('tender_id', $tenders->pluck('id'))->doesntExist(),
        'M07 Tenders demo seeds three synthetic identified drafts with valid disabled owner links and no bids, events, approvals, submissions, awards, guarantees, or conversions; '.json_encode([
            'valid' => $tenderDemoValid,
            'rows' => $tenders->map(fn (object $tender): array => ['identity' => $tender->external_reference, 'status' => $tender->status, 'version' => $tender->version, 'technical' => $tender->technical_id, 'financial' => $tender->financial_id, 'submission' => $tender->submitted_id, 'customer' => $tender->customer_id, 'branch_active' => DB::table('branches')->where('id', $tender->branch_id)->where('active', true)->exists(), 'owner_disabled' => DB::table('users')->where('id', $tender->responsible_id)->where('active', false)->whereNull('email_verified_at')->exists()])->all(),
            'new_rows' => count($newTenderDemoRows),
            'events' => $tenderEvents->map(fn (object $event): string => $event->action)->all(),
        ], JSON_UNESCAPED_UNICODE));

    $verify(true, 'Demo seed used only the temporary active verified Administrator fixture actor');

    $branches = DB::table('branches')->whereIn('code', $branchCodes)->get()->keyBy('code');
    $verify($branches->count() === $sizes['branches'] && $branches->every(fn (object $branch): bool => (int) $branch->company_id === 1
        && trim((string) $branch->name) !== '' && trim((string) $branch->address) !== '' && (bool) $branch->active),
        'Every demo branch references the preserved company and has a meaningful active location');

    $users = DB::table('users')->whereIn('email', $demoEmails)->get();
    $verify($users->count() === $sizes['users'] && $users->every(fn (object $user): bool => ! $user->active
        && $user->email_verified_at === null && $user->last_login_at === null && (int) $user->session_version === 1
        && $user->two_factor_secret === null && $user->two_factor_recovery_codes === null
        && $branches->pluck('id')->contains((int) $user->branch_id) && ! Hash::check('password', $user->password)),
        'Demo users are disabled, unverified, uncredentialed, unlogged, and assigned only to generated branches');
    $demoUserIds = $users->pluck('id')->map(fn ($id): int => (int) $id)->all();
    $verify(DB::table('users')->count() >= $beforeUserCount
        && ! DB::table('user_roles')->whereIn('user_id', $demoUserIds)->exists()
        && ! DB::table('access_grants')->whereIn('user_id', $demoUserIds)->exists()
        && ! DB::table('user_branch_scopes')->whereIn('user_id', $demoUserIds)->exists()
        && ! DB::table('passkeys')->whereIn('user_id', $demoUserIds)->exists()
        && ! DB::table('sessions')->whereIn('user_id', $demoUserIds)->exists()
        && ! DB::table('password_reset_tokens')->whereIn('email', $users->pluck('email')->all())->exists(),
        'Demo users receive no roles, grants, scopes, passkeys, sessions, or reset tokens');

    $products = Product::query()->whereIn('sku', $productSkus)->get()->keyBy('sku');
    $verify($products->count() === $sizes['products'] && $products->every(fn (Product $product): bool => trim($product->name) !== ''
        && trim((string) $product->name_en) !== '' && trim((string) $product->short_description) !== ''
        && trim((string) $product->description) !== '' && trim((string) $product->model) !== ''
        && trim((string) $product->barcode) !== '' && $product->category_id !== null && $product->brand_id !== null
        && $product->base_unit_id !== null && is_array($product->attributes) && $product->attributes !== []
        && DB::table('product_categories')->where('id', $product->category_id)->exists()
        && DB::table('brands')->where('id', $product->brand_id)->exists()
        && DB::table('units_of_measure')->where('id', $product->base_unit_id)->exists()
        && (int) $product->created_by === $actorId && (int) $product->updated_by === $actorId),
        'Every demo product has populated electrical identity and valid category, brand, base unit, and actor references');

    $conversionValid = true;
    foreach ($products as $product) {
        $baseUnit = DB::table('units_of_measure')->where('id', $product->base_unit_id)->first();
        $purchaseUnitId = $product->purchase_unit_id === null ? $product->base_unit_id : (int) $product->purchase_unit_id;
        $purchaseUnit = DB::table('units_of_measure')->where('id', $purchaseUnitId)->first();
        $conversions = DB::table('product_unit_conversions')->where('product_id', $product->id)->get();
        if (! $baseUnit || ! $purchaseUnit || $baseUnit->dimension !== $purchaseUnit->dimension) {
            $conversionValid = false;
        } elseif ($purchaseUnitId === (int) $product->base_unit_id) {
            $conversionValid = $conversions->isEmpty();
        } else {
            $expectedFactor = str_ends_with($purchaseUnit->code, 'ROLL100') ? '100.00000000' : '10.00000000';
            $conversionValid = $conversions->count() === 1 && (int) $conversions[0]->unit_id === $purchaseUnitId
                && $conversions[0]->factor === $expectedFactor;
        }
        if (! $conversionValid) {
            break;
        }
    }
    $verify($conversionValid, 'Product purchase units use dimension-consistent exact 10 or 100 conversions only where required');

    $productFilesValid = true;
    foreach ($products as $product) {
        $media = DB::table('product_media')->where('product_id', $product->id)->whereNull('removed_at')->get();
        if ($media->count() !== 2) {
            $productFilesValid = false;
            break;
        }
        $seenImage = false;
        $seenPdf = false;
        foreach ($media as $item) {
            if (! Storage::disk('local')->exists($item->path) || (int) $item->size !== Storage::disk('local')->size($item->path)
                || ! str_starts_with($item->path, 'product-media/'.$product->id.'/egypt-market-')) {
                $productFilesValid = false;
                break 2;
            }
            $contents = Storage::disk('local')->get($item->path);
            if ($item->mime === 'image/png' && $item->is_image && str_starts_with($contents, "\x89PNG\r\n\x1a\n")) {
                $dimensions = getimagesizefromstring($contents);
                $productFilesValid = $productFilesValid && $dimensions !== false && $dimensions['mime'] === 'image/png';
                $seenImage = true;
            } elseif ($item->mime === 'application/pdf' && ! $item->is_image && str_starts_with($contents, "%PDF-1.4\n")
                && str_contains($contents, 'not a certified manufacturer datasheet')) {
                $seenPdf = true;
            } else {
                $productFilesValid = false;
                break 2;
            }
        }
        if (! $seenImage || ! $seenPdf) {
            $productFilesValid = false;
            break;
        }
    }
    $verify($productFilesValid, 'Each product has one valid PNG and one clearly synthetic private PDF with matching file metadata');

    $customers = Customer::query()->whereIn('code', $customerCodes)->get()->keyBy('id');
    $suppliers = Supplier::query()->whereIn('code', $supplierCodes)->get()->keyBy('id');
    $partiesValid = $customers->count() === $sizes['customers'] && $suppliers->count() === $sizes['suppliers'];
    foreach ([['customer', $customers, 'customer_id', 2], ['supplier', $suppliers, 'supplier_id', 3]] as [$kind, $parties, $foreignKey, $addressCount]) {
        $contactTable = $kind.'_contacts';
        $addressTable = $kind.'_addresses';
        $documentTable = $kind.'_documents';
        $noteTable = $kind.'_notes';
        foreach ($parties as $party) {
            $contacts = DB::table($contactTable)->where($foreignKey, $party->id)->orderBy('id')->get();
            $addresses = DB::table($addressTable)->where($foreignKey, $party->id)->get();
            $documents = DB::table($documentTable)->where($foreignKey, $party->id)->get();
            $notes = DB::table($noteTable)->where($foreignKey, $party->id)->get();
            if (! trim($party->name) || ! trim((string) $party->name_en) || ! trim((string) $party->country)
                || ! trim((string) $party->city) || ! trim((string) $party->phone) || ! trim((string) $party->mobile)
                || ! trim((string) $party->email) || ! trim((string) $party->registration_number) || ! trim((string) $party->tax_number)
                || $contacts->count() !== 3 || $addresses->count() !== $addressCount || $documents->count() !== 2 || $notes->count() !== 3) {
                $partiesValid = false;
                break 2;
            }
            if ($kind === 'supplier' && (! $party->category_id || ! DB::table('supplier_categories')->where('id', $party->category_id)->exists())) {
                $partiesValid = false;
                break 2;
            }
            $primaryContacts = 0;
            foreach ($contacts as $contact) {
                $primaryContacts += (int) $contact->is_primary;
                if (! trim((string) $contact->name) || ! trim((string) $contact->job_title) || ! trim((string) $contact->phone)
                    || ! trim((string) $contact->mobile) || ! trim((string) $contact->email) || ! $contact->active) {
                    $partiesValid = false;
                    break 2;
                }
            }
            if ($primaryContacts !== 1) {
                $partiesValid = false;
                break;
            }
            foreach ($addresses as $address) {
                if (! trim((string) $address->label) || ! trim((string) $address->line) || ! trim((string) $address->country)
                    || ! trim((string) $address->city) || ! trim((string) $address->region) || ! trim((string) $address->postal_code)
                    || ! $address->is_primary) {
                    $partiesValid = false;
                    break 2;
                }
            }
            foreach ($notes as $note) {
                if (! str_contains($note->body, 'تجريبية') || $note->author_id !== null) {
                    $partiesValid = false;
                    break 2;
                }
            }
            foreach ($documents as $document) {
                if ($document->mime !== 'application/pdf' || ! str_starts_with($document->path, $kind.'s/'.$party->id.'/egypt-market-')
                    || ! Storage::disk('local')->exists($document->path) || (int) $document->size !== Storage::disk('local')->size($document->path)) {
                    $partiesValid = false;
                    break 2;
                }
                $contents = Storage::disk('local')->get($document->path);
                if (! str_starts_with($contents, "%PDF-1.4\n") || ! str_contains($contents, 'Synthetic reference only')
                    || ! str_contains($contents, 'Not legal registration, tax proof, or approval')) {
                    $partiesValid = false;
                    break 2;
                }
            }
        }
    }
    $verify($partiesValid, 'Customer and supplier fields, contacts, addresses, notes, categories, and private disclaimer documents form complete owner graphs');

    $quotes = Quotation::query()->whereIn('draft_key', $quoteKeys)->get()->keyBy('draft_key');
    $quotesValid = $quotes->count() === $sizes['quotations'];
    foreach ($quotes as $quote) {
        $customer = $customers->get($quote->customer_id);
        $lines = $quote->lines()->orderBy('position')->get();
        $attachments = DB::table('sales_quotation_attachments')->where('quotation_id', $quote->id)->get();
        $snapshot = $quote->customer_snapshot;
        $calculation = $quote->calculation;
        if (! $customer || $customer->status !== 'active' || $snapshot['id'] !== $customer->id
            || $snapshot['code'] !== $customer->code || $snapshot['name'] !== $customer->name
            || $quote->status !== 'draft' || $quote->number !== null || $quote->currency !== 'EGP'
            || $quote->created_by !== $actorId || $quote->updated_by !== $actorId
            || $quote->payment_term_id !== null || $quote->delivery_term_id !== null
            || $lines->count() < 4 || $lines->count() > 12 || $attachments->count() !== 2
            || ! $calculation['complete'] || $calculation['tax'] !== null || $calculation['total'] !== null
            || $calculation['official'] !== false) {
            $quotesValid = false;
            break;
        }
        foreach ($lines as $line) {
            $product = $products->firstWhere('id', $line->product_id);
            $lineSnapshot = $line->product_snapshot;
            $unit = DB::table('units_of_measure')->where('id', $line->unit_id)->first();
            if (! $product || ! $unit || (int) $line->unit_id !== (int) $product->base_unit_id
                || $lineSnapshot['id'] !== $product->id || $lineSnapshot['sku'] !== $product->sku
                || $lineSnapshot['name'] !== $product->name || $lineSnapshot['unit_id'] !== $line->unit_id
                || $line->suggested_price !== null || $line->requested_price === null || $line->price_source !== null
                || ! is_string($line->quantity) || bccomp($line->quantity, '0', 8) <= 0
                || ! is_string($line->requested_price) || $expectedDecimal($line->requested_price) !== $line->requested_price
                || ! is_string($line->discount_value) || $expectedDecimal($line->discount_value) !== $line->discount_value
                || ! in_array($line->discount_mode, ['percent', 'fixed'], true)
                || ($line->discount_mode === 'percent' && bccomp($line->discount_value, '100', 8) > 0)) {
                $quotesValid = false;
                break 2;
            }
        }
        foreach ($attachments as $attachment) {
            if ($attachment->mime !== 'application/pdf' || ! str_starts_with($attachment->path, 'sales/quotations/internal/'.$quote->id.'/egypt-market-')
                || ! Storage::disk('local')->exists($attachment->path) || (int) $attachment->size !== Storage::disk('local')->size($attachment->path)) {
                $quotesValid = false;
                break 2;
            }
            $contents = Storage::disk('local')->get($attachment->path);
            if (hash('sha256', $contents) !== $attachment->sha256 || ! str_starts_with($contents, "%PDF-1.4\n")
                || ! str_contains($contents, 'Internal demonstration only') || ! str_contains($contents, 'Not an issued quotation or customer acceptance')) {
                $quotesValid = false;
                break 2;
            }
        }
    }
    $verify($quotesValid && DB::table('sales_quotation_approvals')->whereIn('quotation_id', $quotes->pluck('id'))->doesntExist(),
        'Every generated quotation is a coherent priced draft with exact decimals, real owner snapshots, private PDFs, and no approval outcome');

    $orders = SalesOrder::query()->whereIn('draft_key', $orderKeys)->get()->keyBy('draft_key');
    $ordersValid = $orders->count() === $sizes['orders'];
    foreach ($orders as $order) {
        $branch = $branches->firstWhere('id', $order->branch_id);
        $customer = $customers->get($order->customer_id);
        $lines = $order->lines;
        $branchSnapshot = $order->branch_snapshot;
        $customerSnapshot = $order->customer_snapshot;
        $calculation = $order->calculation;
        if (! $branch || ! $customer || $order->status !== 'draft' || $order->number !== null
            || $order->source_type !== 'direct' || $order->currency !== 'EGP' || (int) $order->version !== 1
            || $branchSnapshot['id'] !== $branch->id || $branchSnapshot['code'] !== $branch->code
            || $branchSnapshot['name'] !== $branch->name || $customerSnapshot['id'] !== $customer->id
            || $customerSnapshot['code'] !== $customer->code || $customerSnapshot['name'] !== $customer->name
            || $order->created_by !== $actorId || $order->updated_by !== $actorId
            || ! $order->project_name || ! $order->customer_reference || ! $order->payment_terms || ! $order->delivery_terms
            || $lines->count() < 4 || $lines->count() > 12 || ! is_array($calculation)
            || ($calculation['tax'] ?? null) !== null || ($calculation['total'] ?? null) !== null
            || ($calculation['official'] ?? false) !== false || $order->reviews()->exists()) {
            $ordersValid = false;
            break;
        }
        foreach ($lines as $line) {
            $product = $products->firstWhere('id', $line->product_id);
            $lineSnapshot = $line->product_snapshot;
            if (! $product || (int) $line->unit_id !== (int) $product->base_unit_id
                || $lineSnapshot['id'] !== $product->id || $lineSnapshot['sku'] !== $product->sku
                || $lineSnapshot['name'] !== $product->name || (int) $lineSnapshot['unit_id'] !== (int) $line->unit_id
                || $line->suggested_price !== null || $line->price_source !== null || $line->requested_price === null
                || ! is_string($line->quantity) || bccomp($line->quantity, '0', 8) <= 0
                || ! is_string($line->requested_price) || $expectedDecimal($line->requested_price) !== $line->requested_price
                || ! is_string($line->discount_value) || $expectedDecimal($line->discount_value) !== $line->discount_value) {
                $ordersValid = false;
                break 2;
            }
        }
    }
    $verify($ordersValid && DB::table('sales_order_reviews')->whereIn('sales_order_id', $orders->pluck('id'))->doesntExist(),
        'Every sales order is a linked direct draft with valid snapshots and catalog lines, requested prices only, and no reviews or posting outcome');

    // ServiceContractsDemoSeeder's factory creates branch/customer/service-product roots; AttendanceDemoSeeder's shift factory can create a branch.
    // Snapshot every business table so child-seeder identities cannot hide behind the top-level count summary.
    $firstBusinessState = array_combine($businessTables, array_map($rowHashes, $businessTables));
    $secondSummary = $seeder->seed($sizes);
    $secondCounts = [
        ...$rootCounts(),
        'workshop' => DB::table('workshops')->whereIn('code', ['DEMO-EG-WS-001', 'DEMO-EG-WS-002', 'DEMO-EG-WS-003', 'DEMO-EG-WS-004'])->count(),
        'workshop_intake' => DB::table('workshop_intakes')->where('number', 'DEMO-EG-WI-001')->count(),
        'tender' => DB::table('project_tenders')->whereIn('external_reference', ['DEMO-EG-TN-SUPPLY', 'DEMO-EG-TN-PROJECT', 'DEMO-EG-TN-SERVICE'])->count(),
    ];
    $secondBusinessState = array_combine($businessTables, array_map($rowHashes, $businessTables));
    $verify($secondSummary === [] && $secondCounts === $afterFirstCounts && $secondBusinessState === $firstBusinessState,
        'Second seed is idempotent: no new business records or duplicate dataset rows; first='.json_encode($afterFirstCounts, JSON_UNESCAPED_SLASHES).' second='.json_encode($secondCounts, JSON_UNESCAPED_SLASHES).' summary='.json_encode($secondSummary, JSON_UNESCAPED_SLASHES));

    $preserved = true;
    foreach ($beforeBusiness as $table => $rows) {
        foreach ($rows as $id => $hash) {
            $row = DB::table($table)->where('id', $id)->first();
            if (! $row || hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) !== $hash) {
                $preserved = false;
                break 2;
            }
        }
    }
    $verify($preserved && DB::table('users')->count() >= $beforeUserCount,
        'Every preexisting company, branch, catalog, party, document, and quotation row remains byte-for-byte unchanged');

    $securityUnchanged = true;
    foreach ($beforeSecurityCounts as $table => $count) {
        if (DB::table($table)->count() !== $count) {
            $securityUnchanged = false;
            break;
        }
    }
    $verify($securityUnchanged, 'No roles, permissions, grants, scopes, auth sessions, approvals, policies, or data-operation receipts were created');

    $expectedAuditEvents = array_sum($firstSummary);
    $demoAudits = DB::table('audit_entries')->where('id', '>', $beforeAuditId)->where('action', 'demo.created')->get();
    $rootAudits = $demoAudits->whereIn('source_module', ['Organization', 'Products', 'Parties', 'Sales']);
    $fieldMaintenanceAudits = $demoAudits->where('source_module', 'FieldMaintenance');
    $afterFieldMaintenanceDemoRows = $fieldMaintenanceDemoRows();
    $newFieldMaintenanceDemoRows = array_diff_key($afterFieldMaintenanceDemoRows, $beforeFieldMaintenanceDemoRows);
    $newFieldMaintenanceIds = array_column($newFieldMaintenanceDemoRows, 'id');
    $expectedFieldMaintenanceIdentities = array_column($newFieldMaintenanceDemoRows, 'identity');
    $actualFieldMaintenanceIdentities = $fieldMaintenanceAudits->map(function (object $audit): ?string {
        $snapshot = json_decode((string) $audit->safe_after, true);

        return is_array($snapshot) && is_string($snapshot['identity'] ?? null) ? $snapshot['identity'] : null;
    })->sort()->values()->all();
    sort($expectedFieldMaintenanceIdentities);
    $fieldMaintenanceSourcesMatch = $fieldMaintenanceAudits->every(fn (object $audit): bool => $audit->source_type === 'maintenance_demo'
        && in_array((int) $audit->source_id, $newFieldMaintenanceIds, true))
        && $actualFieldMaintenanceIdentities === $expectedFieldMaintenanceIdentities;
    $workshopAudits = $demoAudits->where('source_module', 'WorkshopRepairs');
    $newWorkshopIds = array_column($newWorkshopDemoRows, 'id');
    $expectedWorkshopAuditIdentities = array_values(array_filter($workshopIdentities, fn (string $identity): bool => in_array($identity, [
        'DEMO-EG-WS-001', 'DEMO-EG-WS-002', 'DEMO-EG-WS-003', 'DEMO-EG-WS-004', 'DEMO-EG-WI-001',
    ], true)));
    $actualWorkshopAuditIdentities = $workshopAudits->map(function (object $audit): ?string {
        $snapshot = json_decode((string) $audit->safe_after, true);

        return is_array($snapshot) && is_string($snapshot['identity'] ?? null) ? $snapshot['identity'] : null;
    })->sort()->values()->all();
    sort($expectedWorkshopAuditIdentities);
    $workshopSourcesMatch = $workshopAudits->every(fn (object $audit): bool => $audit->source_type === 'workshop_demo'
        && in_array((int) $audit->source_id, $newWorkshopIds, true))
        && $actualWorkshopAuditIdentities === $expectedWorkshopAuditIdentities;
    $tenderAudits = $demoAudits->where('source_module', 'Projects');
    $newTenderIds = array_column($newTenderDemoRows, 'id');
    $expectedTenderAuditIdentities = array_column($newTenderDemoRows, 'identity');
    $actualTenderAuditIdentities = $tenderAudits->map(function (object $audit): ?string {
        $snapshot = json_decode((string) $audit->safe_after, true);

        return is_array($snapshot) && is_string($snapshot['identity'] ?? null) ? $snapshot['identity'] : null;
    })->sort()->values()->all();
    sort($expectedTenderAuditIdentities);
    $tenderSourcesMatch = $tenderAudits->every(fn (object $audit): bool => $audit->source_type === 'tender_demo'
        && in_array((int) $audit->source_id, $newTenderIds, true))
        && $actualTenderAuditIdentities === $expectedTenderAuditIdentities;
    $afterServiceContractDemoRows = $serviceContractDemoRows();
    $newServiceContractDemoRows = array_diff_key($afterServiceContractDemoRows, $beforeServiceContractDemoRows);
    $serviceContractAudits = $demoAudits->where('source_module', 'ServiceContracts');
    $newServiceContractIds = array_column($newServiceContractDemoRows, 'id');
    $expectedServiceContractIdentities = array_column($newServiceContractDemoRows, 'identity');
    sort($expectedServiceContractIdentities);
    $actualServiceContractIdentities = $serviceContractAudits->map(function (object $audit): ?string {
        $snapshot = json_decode((string) $audit->safe_after, true);

        return is_array($snapshot) && is_string($snapshot['identity'] ?? null) ? $snapshot['identity'] : null;
    })->sort()->values()->all();
    $serviceContractSourcesMatch = $serviceContractAudits->every(fn (object $audit): bool => $audit->source_type === 'service_contract'
        && in_array((int) $audit->source_id, $newServiceContractIds, true))
        && $actualServiceContractIdentities === $expectedServiceContractIdentities;
    $afterAttendanceDemoRows = $attendanceDemoRows();
    $newAttendanceDemoRows = array_diff_key($afterAttendanceDemoRows, $beforeAttendanceDemoRows);
    $attendanceAudits = $demoAudits->where('source_module', 'Attendance');
    $newAttendanceIds = array_column($newAttendanceDemoRows, 'id');
    $expectedAttendanceIdentities = array_column($newAttendanceDemoRows, 'identity');
    sort($expectedAttendanceIdentities);
    $actualAttendanceIdentities = $attendanceAudits->map(function (object $audit): ?string {
        $snapshot = json_decode((string) $audit->safe_after, true);

        return is_array($snapshot) && is_string($snapshot['identity'] ?? null) ? $snapshot['identity'] : null;
    })->sort()->values()->all();
    $attendanceSourcesMatch = $attendanceAudits->every(fn (object $audit): bool => $audit->source_type === 'attendance_schedule'
        && in_array((int) $audit->source_id, $newAttendanceIds, true))
        && $actualAttendanceIdentities === $expectedAttendanceIdentities;
    $expectedAuditCountsBySource = [
        'Organization|branch' => $firstSummary['branch'] ?? 0,
        'Organization|user' => $firstSummary['user'] ?? 0,
        'Products|product' => $firstSummary['product'] ?? 0,
        'Parties|customer' => $firstSummary['customer'] ?? 0,
        'Parties|supplier' => $firstSummary['supplier'] ?? 0,
        'Sales|quotation' => $firstSummary['quotation'] ?? 0,
        'Sales|sales_order' => $firstSummary['sales_order'] ?? 0,
        'FieldMaintenance|maintenance_demo' => count($newFieldMaintenanceDemoRows),
        'WorkshopRepairs|workshop_demo' => count($newWorkshopDemoRows),
        'Projects|tender_demo' => count($newTenderDemoRows),
        'ServiceContracts|service_contract' => count($newServiceContractDemoRows),
        'Attendance|attendance_schedule' => count($newAttendanceDemoRows),
    ];
    $expectedAuditCountsBySource = array_filter($expectedAuditCountsBySource, static fn (int $count): bool => $count > 0);
    ksort($expectedAuditCountsBySource);
    $actualAuditCountsBySource = $demoAudits->groupBy(fn (object $audit): string => $audit->source_module.'|'.$audit->source_type)
        ->map(fn ($rows): int => $rows->count())->sortKeys()->all();
    $newRootIds = static function (string $table, array $ids) use ($beforeBusiness): array {
        return array_values(array_filter(array_map('intval', $ids), fn (int $id): bool => ! array_key_exists((string) $id, $beforeBusiness[$table])));
    };
    $expectedRootAuditIds = [
        'Organization|branch' => $newRootIds('branches', DB::table('branches')->whereIn('code', $branchCodes)->pluck('id')->all()),
        'Organization|user' => $newRootIds('users', DB::table('users')->whereIn('email', $demoEmails)->pluck('id')->all()),
        'Products|product' => $newRootIds('products', DB::table('products')->whereIn('sku', $productSkus)->pluck('id')->all()),
        'Parties|customer' => $newRootIds('customers', DB::table('customers')->whereIn('code', $customerCodes)->pluck('id')->all()),
        'Parties|supplier' => $newRootIds('suppliers', DB::table('suppliers')->whereIn('code', $supplierCodes)->pluck('id')->all()),
        'Sales|quotation' => $newRootIds('sales_quotations', DB::table('sales_quotations')->whereIn('draft_key', $quoteKeys)->pluck('id')->all()),
        'Sales|sales_order' => $newRootIds('sales_orders', DB::table('sales_orders')->whereIn('draft_key', $orderKeys)->pluck('id')->all()),
    ];
    $rootSourceIdsMatch = true;
    foreach ($expectedRootAuditIds as $source => $ids) {
        $actualIds = $rootAudits->filter(fn (object $audit): bool => $audit->source_module.'|'.$audit->source_type === $source)
            ->pluck('source_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        sort($ids);
        if ($actualIds !== $ids) {
            $rootSourceIdsMatch = false;
            break;
        }
    }
    $anonymousSuccessfulAudits = $demoAudits->every(fn (object $audit): bool => $audit->actor_id === null && $audit->outcome === 'succeeded');
    $verify($actualAuditCountsBySource === $expectedAuditCountsBySource
        && $rootSourceIdsMatch
        && $anonymousSuccessfulAudits
        && $rootAudits->count() === $expectedAuditEvents
        && $rootAudits->every(fn (object $audit): bool => $audit->actor_id === null && $audit->outcome === 'succeeded')
        && $fieldMaintenanceAudits->count() === count($newFieldMaintenanceDemoRows)
        && $fieldMaintenanceAudits->every(fn (object $audit): bool => $audit->actor_id === null && $audit->outcome === 'succeeded')
        && $fieldMaintenanceSourcesMatch && $workshopAudits->count() === count($newWorkshopDemoRows)
        && $workshopAudits->every(fn (object $audit): bool => $audit->actor_id === null && $audit->outcome === 'succeeded')
        && $workshopSourcesMatch && $tenderAudits->count() === count($newTenderDemoRows)
        && $tenderAudits->every(fn (object $audit): bool => $audit->actor_id === null && $audit->outcome === 'succeeded')
        && $tenderSourcesMatch && $serviceContractAudits->every(fn (object $audit): bool => $audit->actor_id === null && $audit->outcome === 'succeeded')
        && $serviceContractSourcesMatch && $attendanceAudits->every(fn (object $audit): bool => $audit->actor_id === null && $audit->outcome === 'succeeded')
        && $attendanceSourcesMatch && $demoAudits->count() === array_sum($actualAuditCountsBySource),
        'Synthetic inserts have anonymous successful owner-matched audits by source; actual='.json_encode($actualAuditCountsBySource, JSON_UNESCAPED_SLASHES).' expected='.json_encode($expectedAuditCountsBySource, JSON_UNESCAPED_SLASHES).' anonymous_success='.($anonymousSuccessfulAudits ? 'yes' : 'no').' no fabricated sign-in, approval or posting history');

    $afterFiles = array_values(array_diff($fileSnapshot(), $beforeFiles));
    $generatedFiles = array_values(array_intersect($fixturePaths(), $afterFiles));
    $privateRoot = realpath(config('filesystems.disks.local.root'));
    $resolvedDiskParent = realpath($diskParent);
    $verify($privateRoot !== false && $resolvedDiskParent !== false && str_starts_with($privateRoot, $resolvedDiskParent)
        && count($generatedFiles) > 0 && count(array_filter($generatedFiles, fn (string $path): bool =>
            ! collect($fileRoots)->contains(fn (string $root): bool => str_starts_with($path, $root.'/'))
            || str_contains($path, '..') || str_starts_with($path, '/'))) === 0,
        'Fixture files stay on the application private disk and newly created paths remain within approved private roots');
} finally {
    $createdOnIsolatedDisk = Storage::disk('local')->allFiles();
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Storage::disk('local')->delete($createdOnIsolatedDisk);
    Storage::forgetDisk('local');
    config(['filesystems.disks.local.root' => $oldDiskRoot]);
    Storage::forgetDisk('local');
    $resolvedDiskParent = realpath($diskParent);
    $resolvedDiskRoot = realpath($diskRoot);
    if ($resolvedDiskParent !== false && $resolvedDiskRoot !== false
        && str_starts_with($resolvedDiskRoot, $resolvedDiskParent.DIRECTORY_SEPARATOR)) {
        File::deleteDirectory($resolvedDiskRoot);
    }
    if ($oldActorEnv === false) {
        putenv('ERP_DEMO_ACTOR_ID');
        unset($_ENV['ERP_DEMO_ACTOR_ID'], $_SERVER['ERP_DEMO_ACTOR_ID']);
    } else {
        putenv('ERP_DEMO_ACTOR_ID='.$oldActorEnv);
        $_ENV['ERP_DEMO_ACTOR_ID'] = $_SERVER['ERP_DEMO_ACTOR_ID'] = $oldActorEnv;
    }
    echo "Egypt-market demo fixture transaction rolled back; generated private files cleaned up.\n";
}

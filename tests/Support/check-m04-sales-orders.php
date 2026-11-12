<?php

use App\Models\User;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\PermissionCatalog;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesOrderActions;
use App\Modules\Sales\Application\SalesOrderApprovalPolicy;
use App\Modules\Sales\Application\SalesOrderCatalog;
use App\Modules\Sales\Application\SalesOrderExportDefinition;
use App\Modules\Sales\Application\SalesOrderQuery;
use App\Modules\Sales\Application\SalesOrderReadiness;
use App\Modules\Sales\Domain\SalesOrderHistoryReader;
use App\Modules\Sales\Presentation\SalesOrderFormPage;
use Database\Factories\BranchFactory;
use Database\Factories\CompanyFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Symfony\Component\HttpKernel\Exception\HttpException;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('M04 Sales Order checks require the exact separate PostgreSQL test database.');
}

$tables = ['companies', 'branches', 'users', 'roles', 'user_roles', 'access_grants', 'role_permissions', 'permission_catalog', 'sales_order_policies', 'sales_orders', 'sales_order_lines', 'sales_order_reviews', 'audit_entries'];
foreach ($tables as $table) {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException("Run test database migrations before checking {$table}.");
    }
}

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    fwrite(STDOUT, "PASS {$message}\n");
};
$expect = static function (string $exceptionClass, Closure $action, string $message, ?string $field = null, ?string $contains = null) use ($verify): Throwable {
    try {
        $action();
    } catch (Throwable $exception) {
        $verify($exception instanceof $exceptionClass, $message);
        if ($field !== null) {
            $verify($exception instanceof ValidationException && array_key_exists($field, $exception->errors()), $message.' reports '.$field);
        }
        if ($contains !== null) {
            $details = $exception instanceof ValidationException ? implode(' ', $exception->validator->errors()->all()) : $exception->getMessage();
            $verify(str_contains($details, $contains), $message.' explains '.$contains);
        }

        return $exception;
    }

    throw new RuntimeException($message.' (no exception)');
};
$reviewGuardFailures = [];
$reviewMutationMustBeRejected = static function (Closure $action, string $message) use ($verify, &$reviewGuardFailures): void {
    $mutationRan = false;
    try {
        DB::transaction(function () use ($action, &$mutationRan): void {
            $action();
            $mutationRan = true;
            throw new LogicException('rollback sales order review mutation probe');
        });
    } catch (QueryException) {
        $verify(true, $message);

        return;
    } catch (LogicException $exception) {
        if ($exception->getMessage() !== 'rollback sales order review mutation probe') {
            throw $exception;
        }
    }

    if ($mutationRan) {
        fwrite(STDOUT, "FAIL {$message}\n");
        $reviewGuardFailures[] = $message;
    }
};
$decode = static fn (mixed $json): array => is_array($json) ? $json : json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
$normalizeObjects = static function (array $value) use (&$normalizeObjects): array {
    foreach ($value as &$item) {
        if (is_array($item)) {
            $item = $normalizeObjects($item);
        }
    }
    unset($item);

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
};
$branchCode = static fn (): string => 'SO-CHECK-'.Str::upper(Str::random(10));
$exportPaths = [];

DB::beginTransaction();
$failure = null;

try {
    $salesOrderPermissions = array_values(array_filter(
        array_keys(PermissionCatalog::DEFINITIONS),
        static fn (string $code): bool => str_starts_with($code, 'M04.sales_order_'),
    ));
    $verify($salesOrderPermissions !== []
        && DB::table('permission_catalog')->whereIn('code', $salesOrderPermissions)->count() === count($salesOrderPermissions),
        'M04 Sales Order permissions are registered');

    $administratorRoleId = (int) DB::table('roles')->where('code', 'Administrator')->value('id');
    $verify($administratorRoleId > 0, 'Administrator role exists');
    $adminPermissionToRestore = $salesOrderPermissions[0];
    DB::table('role_permissions')->where('role_id', $administratorRoleId)
        ->where('permission_code', $adminPermissionToRestore)->delete();
    $addedBySync = app(SyncAdministratorPermissions::class)->execute();
    $verify(in_array($adminPermissionToRestore, $addedBySync, true)
        && ! array_diff($salesOrderPermissions, DB::table('role_permissions')->where('role_id', $administratorRoleId)->pluck('permission_code')->all()),
        'Administrator permission synchronization restores every registered Sales Order permission');

    if (! DB::table('companies')->where('id', 1)->exists()) {
        DB::table('companies')->insert(CompanyFactory::new()->raw([
            'id' => 1,
            'code' => 'SO-CHECK-COMPANY-'.Str::upper(Str::random(8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    $makeBranch = static function (string $name) use ($branchCode): array {
        $code = $branchCode();
        $attributes = [
            'company_id' => 1,
            'code' => $code,
            'name' => $name,
            'address' => 'عنوان فرع تجريبي',
            'active' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $id = (int) DB::table('branches')->insertGetId(BranchFactory::new()->raw($attributes));

        return ['id' => $id] + $attributes;
    };
    $branchA = $makeBranch('Synthetic Sales Order Branch A');
    $branchB = $makeBranch('Synthetic Sales Order Branch B');

    $actor = User::factory()->create(['name' => 'Synthetic Sales Order actor', 'active' => true, 'email_verified_at' => now()]);
    $reviewer = User::factory()->create(['name' => 'Synthetic Sales Order reviewer', 'active' => true, 'email_verified_at' => now()]);
    $otherActor = User::factory()->create(['name' => 'Synthetic Sales Order other branch actor', 'active' => true, 'email_verified_at' => now()]);
    $administrator = User::factory()->create(['name' => 'Synthetic Sales Order administrator', 'active' => true, 'email_verified_at' => now()]);
    DB::table('user_roles')->insert([
        'user_id' => $administrator->id,
        'role_id' => $administratorRoleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $grant = static function (User $user, string $code, ?int $branchId = null): void {
        DB::table('access_grants')->insert([
            'user_id' => $user->id,
            'permission_code' => $code,
            'branch_id' => $branchId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };
    $grantCatalogAccess = static function (User $user) use ($grant): void {
        $grant($user, 'M02.product_view');
        $grant($user, 'M03.customer_view');
    };
    $grantBranchAccess = static function (User $user, int $branchId, array $actions) use ($grant): void {
        foreach (array_unique($actions) as $action) {
            $grant($user, 'M04.sales_order_'.$action, $branchId);
        }
    };
    foreach ([$actor, $reviewer, $otherActor] as $user) {
        $grantCatalogAccess($user);
    }
    $branchAActions = ['view', 'create', 'edit', 'submit', 'withdraw', 'reject', 'cancel', 'export', 'history', 'approve', 'exception_approve', 'amend', 'delivery_create', 'delivery_confirm', 'invoice_view', 'return_view', 'return_create', 'close', 'convert'];
    $grantBranchAccess($actor, (int) $branchA['id'], $branchAActions);
    $grantBranchAccess($reviewer, (int) $branchA['id'], ['view', 'reject', 'history']);
    $grantBranchAccess($otherActor, (int) $branchB['id'], ['view', 'create', 'edit', 'submit', 'export']);

    $access = app(SalesOrderAccess::class);
    $permissionAccess = app(PermissionAccess::class);
    $dynamicApprover = User::factory()->create(['name' => 'Synthetic dynamic SO approver', 'active' => true, 'email_verified_at' => now()]);
    $approverRoleId = (int) DB::table('roles')->insertGetId([
        'code' => 'SO-CHECK-APPROVER-'.Str::upper(Str::random(8)),
        'name' => 'Synthetic Sales Order approver',
        'protected' => false,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('role_permissions')->insert([
        'role_id' => $approverRoleId,
        'permission_code' => 'M04.sales_order_approve',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_roles')->insert([
        'user_id' => $dynamicApprover->id,
        'role_id' => $approverRoleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_branch_scopes')->insert([
        'user_id' => $dynamicApprover->id,
        'branch_id' => $branchA['id'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $grant($dynamicApprover, 'M04.sales_order_view', (int) $branchA['id']);
    $exceptionApprover = User::factory()->create(['name' => 'Synthetic SO exception approver', 'active' => true, 'email_verified_at' => now()]);
    $unselectedExceptionApprover = User::factory()->create(['name' => 'Synthetic unselected SO exception approver', 'active' => true, 'email_verified_at' => now()]);
    $selectedRoleHolder = User::factory()->create(['name' => 'Synthetic SO policy role holder', 'active' => true, 'email_verified_at' => now()]);
    $salesClerkRoleId = (int) DB::table('roles')->where('code', 'SalesClerk')->value('id');
    $verify($salesClerkRoleId > 0, 'A configured exception-responsible role is available from M01 security roles');
    foreach ([$exceptionApprover, $unselectedExceptionApprover] as $user) {
        $grantBranchAccess($user, (int) $branchA['id'], ['view', 'approve', 'exception_approve']);
    }
    DB::table('user_roles')->insert([
        'user_id' => $exceptionApprover->id,
        'role_id' => $salesClerkRoleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_roles')->insert([
        'user_id' => $selectedRoleHolder->id,
        'role_id' => $salesClerkRoleId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('user_branch_scopes')->insert([
        'user_id' => $selectedRoleHolder->id,
        'branch_id' => $branchA['id'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $verify($permissionAccess->roleCapability($administrator->id, 'M04.sales_order_view')
        && ! $access->allows($administrator->id, 'view', (int) $branchA['id']),
        'Administrator receives registered Sales Order capability but branch scope is still required');
    DB::table('user_branch_scopes')->insert([
        'user_id' => $administrator->id,
        'branch_id' => $branchA['id'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $verify($access->allows($administrator->id, 'view', (int) $branchA['id'])
        && ! $access->allows($administrator->id, 'view', (int) $branchB['id']),
        'Administrator branch actions remain limited to the explicitly scoped branch');
    $verify($permissionAccess->roleCapability($dynamicApprover->id, 'M04.sales_order_approve')
        && $access->allows($dynamicApprover->id, 'approve', (int) $branchA['id'])
        && ! $access->allows($dynamicApprover->id, 'approve', (int) $branchB['id']),
        'M01-managed dynamic approval permission authorizes a role within its explicit branch scope');
    $verify($access->allows($actor->id, 'view', (int) $branchA['id'])
        && ! $access->allows($actor->id, 'view', (int) $branchB['id']),
        'Direct Sales Order grants remain limited to their assigned branch');
    $branchIds = $access->branchIds($actor->id);
    sort($branchIds);
    $verify($branchIds === [(int) $branchA['id']], 'OrganizationApi returns only explicitly granted Sales Order branches');

    $approvalPolicy = app(SalesOrderApprovalPolicy::class);
    $approvalPolicy->authorizeApprover($dynamicApprover->id, $actor->id, (int) $branchA['id']);
    $verify(true, 'M01-managed dynamic approval permission authorizes a different actor for a branch-scoped order');
    $expect(ValidationException::class,
        fn () => $approvalPolicy->authorizeApprover($exceptionApprover->id, $actor->id, (int) $branchA['id'], true),
        'Exception decision fails closed until a responsible role is configured', 'policy', 'حدد دور مسؤول الاستثناءات');
    $approvalPolicy->save($administrator->id, $salesClerkRoleId, null);
    $policyConfiguration = $approvalPolicy->configuration($administrator->id);
    $verify((int) $policyConfiguration->exception_role_id === $salesClerkRoleId && (int) $policyConfiguration->version === 1
        && (int) $policyConfiguration->updated_by === $administrator->id,
        'Security settings persist one versioned exception-responsible role without approval limits');
    $verify(! $access->allows($selectedRoleHolder->id, 'approve', (int) $branchA['id'])
        && ! $access->allows($selectedRoleHolder->id, 'exception_approve', (int) $branchA['id']),
        'Selecting a responsible role grants no Sales Order permission by itself');
    $expect(ValidationException::class, fn () => $approvalPolicy->save($administrator->id, $salesClerkRoleId, null),
        'Stale exception-settings version is rejected', 'policy');
    $verify((int) $approvalPolicy->configuration($administrator->id)->version === 1,
        'Stale settings save preserves the configured responsible role and version');
    $approvalPolicy->authorizeApprover($exceptionApprover->id, $actor->id, (int) $branchA['id'], true);
    $expect(HttpException::class,
        fn () => $approvalPolicy->authorizeApprover($unselectedExceptionApprover->id, $actor->id, (int) $branchA['id'], true),
        'Exception permission alone cannot bypass the configured responsible role');
    $expect(HttpException::class,
        fn () => $approvalPolicy->authorizeApprover($exceptionApprover->id, $exceptionApprover->id, (int) $branchA['id']),
        'Order requester cannot approve their own Sales Order');

    $customer = Customer::factory()->create([
        'code' => 'SO-CHECK-CUS-'.Str::upper(Str::random(8)),
        'name' => 'Synthetic Sales Order Customer',
        'name_en' => 'Synthetic Sales Order Customer',
    ]);
    $unit = Unit::factory()->create([
        'code' => 'SO-CHECK-UOM-'.Str::upper(Str::random(8)),
        'name' => 'وحدة أمر بيع تجريبية',
        'dimension' => 'count',
    ]);
    $product = Product::factory()->create([
        'sku' => 'SO-CHECK-SKU-'.Str::upper(Str::random(8)),
        'name' => 'منتج أمر بيع تجريبي',
        'name_en' => 'Synthetic Sales Order Product',
        'base_unit_id' => $unit->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
    $searchUnit = Unit::factory()->create(['code' => 'SO-SEARCH-UOM-'.Str::upper(Str::random(8))]);
    $searchProduct = Product::factory()->create([
        'sku' => 'SO-SEARCH-SKU-'.Str::upper(Str::random(8)),
        'base_unit_id' => $searchUnit->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);

    Livewire::actingAs($actor)->test(SalesOrderFormPage::class)
        ->set('form.branch_id', (int) $branchA['id'])
        ->set('form.customer_id', $customer->id)
        ->set('lines.0.product_id', $product->id)
        ->assertSet('lines.0.unit_id', $unit->id)
        ->call('addLine')
        ->set('lines.1.product_id', $searchProduct->id)
        ->assertSet('lines.1.unit_id', $searchUnit->id)
        ->set('productSearch', $searchProduct->sku)
        ->set('lines.0.requested_price', '8.50000000')
        ->set('lines.1.requested_price', '11.25000000')
        ->set('lines.1.product_id', $product->id)
        ->assertSet('lines.1.unit_id', $unit->id)
        ->assertSet('lines.1.sku', $product->sku)
        ->assertSet('lines.1.product_name', $product->name)
        ->assertSet('lines.1.requested_price', '');
    $verify(true, 'Sales Order product changes resolve full catalog records outside productSearch, refresh unit/name/SKU, and clear stale requested price');

    $inputFor = static fn (int $branchId): array => [
        'branch_id' => $branchId,
        'creation_key' => (string) Str::uuid(),
        'customer_id' => $customer->id,
        'source_type' => 'direct',
        'quotation_id' => 987654321,
        'quotation_revision_id' => 987654321,
        'order_date' => '2026-10-21',
        'requested_delivery_date' => '2026-10-31',
        'project_name' => 'Synthetic test project',
        'customer_reference' => 'SO-CHECK-REFERENCE',
        'discount_mode' => 'percent',
        'discount_value' => '0',
        'payment_terms' => 'Synthetic proposed terms',
        'delivery_terms' => 'Synthetic proposed delivery terms',
        'notes' => 'Synthetic Sales Order check record',
        'status' => 'approved',
        'version' => 99,
        'currency' => 'USD',
        'number' => 'SO-CLIENT-FORGED',
        'calculation' => ['gross' => '999999999.99', 'tax' => '999999999.99', 'total' => '999999999.99'],
        'lines' => [[
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'quantity' => '1.25000000',
            'description' => 'Synthetic direct draft line',
            'requested_price' => '8.50000000',
            'suggested_price' => '999999999.99',
            'price_source' => ['source' => 'client-forged'],
            'product_snapshot' => ['sku' => 'CLIENT-FORGED'],
            'position' => 99,
            'discount_mode' => 'percent',
            'discount_value' => '0',
            'override_reason' => '',
        ]],
    ];

    $actions = app(SalesOrderActions::class);
    $directInput = [...$inputFor((int) $branchA['id']), 'requested_delivery_date' => ''];
    $draft = $actions->save($actor->id, $directInput);
    $verify($draft->status === 'draft' && $draft->requested_delivery_date === null,
        'Sales Order draft save normalizes the empty Livewire delivery-date value to PostgreSQL NULL');
    $draft->load('lines');
    $line = $draft->lines->first();
    $trustedPrice = app(QuotationPricing::class)->suggested($actor->id, $product->id, $unit->id, '2026-10-21');
    $verify($draft->exists && $draft->status === 'draft' && $draft->version === 1 && $draft->number === null
        && $draft->currency === 'EGP' && $draft->source_type === 'direct' && $draft->created_by === $actor->id
        && $draft->branch_snapshot['id'] === $branchA['id'] && $draft->customer_snapshot['id'] === $customer->id,
        'Direct save persists a branch/customer snapshot as an unnumbered direct draft');

    $routeContracts = [
        'sales-orders.index' => 'sales-orders',
        'sales-orders.create' => 'sales-orders/create',
        'sales-orders.policy' => 'sales-orders/policy',
        'sales-orders.edit' => 'sales-orders/{order}/edit',
        'sales-orders.screen' => 'sales-orders/{order}/{screen}',
        'sales-orders.show' => 'sales-orders/{order}',
        'sales-returns.index' => 'sales-returns',
    ];
    foreach ($routeContracts as $routeName => $uri) {
        $verify(Route::getRoutes()->getByName($routeName)?->uri() === $uri,
            "Sales Order route {$routeName} is registered at its expected URI");
    }
    $httpKernel = app(HttpKernel::class);
    $renderChecks = [
        '/sales-orders' => 'أوامر البيع',
        '/sales-orders/create' => 'إنشاء أمر بيع مباشر',
        '/sales-orders/'.$draft->id.'/edit' => 'تعديل أمر البيع',
        '/sales-orders/'.$draft->id => 'البيانات الرئيسية',
        '/sales-orders/'.$draft->id.'/review' => 'مراجعة أمر البيع',
        '/sales-orders/'.$draft->id.'/amendment' => 'تعديل أمر البيع',
        '/sales-orders/'.$draft->id.'/delivery' => 'التسليم',
        '/sales-orders/'.$draft->id.'/invoices' => 'الفواتير',
        '/sales-orders/'.$draft->id.'/closure' => 'الإغلاق والمرتجعات',
    ];
    foreach ($renderChecks as $uri => $marker) {
        Auth::guard()->setUser($actor);
        $request = Request::create($uri, 'GET');
        $response = $httpKernel->handle($request);
        $html = (string) $response->getContent();
        $status = $response->getStatusCode();
        $httpKernel->terminate($request, $response);
        $verify($status === 200 && str_contains($html, $marker),
            "Authorized Livewire route {$uri} renders its expected screen content");
    }
    Auth::guard()->setUser($actor);
    $returnsRequest = Request::create('/sales-returns', 'GET');
    $returnsResponse = $httpKernel->handle($returnsRequest);
    $returnsStatus = $returnsResponse->getStatusCode();
    $httpKernel->terminate($returnsRequest, $returnsResponse);
    $verify($returnsStatus === 200, 'Authorized Sales Order actor can render the Sales Returns page');
    Auth::guard()->setUser($administrator);
    $policyRequest = Request::create('/sales-orders/policy', 'GET');
    $policyResponse = $httpKernel->handle($policyRequest);
    $policyHtml = (string) $policyResponse->getContent();
    $policyStatus = $policyResponse->getStatusCode();
    $httpKernel->terminate($policyRequest, $policyResponse);
    $verify($policyStatus === 200 && str_contains($policyHtml, 'مسؤول الاستثناءات')
        && str_contains($policyHtml, 'لا توجد حدود مالية أو نسب خصم افتراضية'),
        'M01 security administrator can render exception settings without invented approval limits');
    Auth::guard()->setUser($actor);

    $beforeReplay = [DB::table('sales_orders')->count(), DB::table('sales_order_lines')->count()];
    $replayedDraft = $actions->save($actor->id, $directInput);
    $verify($replayedDraft->id === $draft->id && $replayedDraft->version === $draft->version
        && [DB::table('sales_orders')->count(), DB::table('sales_order_lines')->count()] === $beforeReplay,
        'A repeated actor-scoped creation key returns the original draft without duplicating rows');
    $verify($line->quantity === '1.25000000' && $line->requested_price === '8.50000000'
        && $line->position === 1 && $line->product_snapshot['id'] === $product->id
        && $line->product_snapshot['unit_id'] === $unit->id
        && $line->suggested_price === ($trustedPrice['amount'] ?? null)
        && $line->price_source === $trustedPrice,
        'Draft quantity and proposed price persist exactly while client-supplied price source and snapshots are ignored');
    $verify($draft->calculation['complete'] === true && bccomp($draft->calculation['gross'], '10.625', 8) === 0
        && bccomp($draft->calculation['net'], '10.625', 8) === 0
        && $draft->calculation['tax'] === null && $draft->calculation['total'] === null
        && $draft->calculation['official'] === false,
        'Server recalculates exact unrounded draft amounts without inventing tax or an official total');

    $salesOrderCount = DB::table('sales_orders')->count();
    $salesLineCount = DB::table('sales_order_lines')->count();
    $invalidSource = $inputFor((int) $branchA['id']);
    $invalidSource['source_type'] = 'quotation';
    $expect(ValidationException::class, fn () => $actions->save($actor->id, $invalidSource), 'Quotation source is rejected while no accepted issued revision contract exists', 'source_type');
    $verify(DB::table('sales_orders')->count() === $salesOrderCount && DB::table('sales_order_lines')->count() === $salesLineCount,
        'Rejected quotation conversion creates no Sales Order or line allocation');

    $otherDraft = $actions->save($otherActor->id, $inputFor((int) $branchB['id']));
    $query = app(SalesOrderQuery::class);
    $verify($query->query($actor->id)->whereKey($draft->id)->exists()
        && ! $query->query($actor->id)->whereKey($otherDraft->id)->exists()
        && ! $query->query($actor->id, ['branch' => $branchB['id']])->exists(),
        'List queries enforce branch scope even when a different branch filter or record ID is supplied');
    $expect(HttpException::class, fn () => $actions->save($actor->id, $inputFor((int) $branchB['id'])), 'Creating in an ungranted branch is denied');
    $transfer = $inputFor((int) $branchB['id']);
    $transfer['project_name'] = 'Attempted cross-branch move';
    $expect(HttpException::class, fn () => $actions->save($actor->id, $transfer, $draft->id, $draft->version), 'Moving an editable draft to an ungranted branch is denied');
    $expect(HttpException::class, fn () => $actions->save($actor->id, $inputFor((int) $branchA['id']), $otherDraft->id, $otherDraft->version), 'Editing another branch draft is denied');
    $verify($draft->fresh()->branch_id === $branchA['id'] && $draft->fresh()->version === 1,
        'Denied branch moves preserve the original branch, version, and draft');

    DB::table('branches')->where('id', $branchB['id'])->update(['active' => false]);
    $inactiveBranches = app(SalesOrderCatalog::class)->branches($otherActor->id, 'view');
    $inactiveCreateBranches = app(SalesOrderCatalog::class)->branches($otherActor->id, 'create');
    $verify($query->query($otherActor->id)->whereKey($otherDraft->id)->exists()
        && $access->allows($otherActor->id, 'view', (int) $branchB['id'])
        && collect($inactiveBranches)->contains(fn (array $branch): bool => (int) $branch['id'] === (int) $branchB['id'] && ! $branch['active']),
        'Historical order remains visible on an inactive but still authorized branch');
    $verify(! collect($inactiveCreateBranches)->contains('id', (int) $branchB['id']),
        'Inactive authorized branch is excluded from create choices');
    $inactiveCreateInput = $inputFor((int) $branchB['id']);
    $expect(ValidationException::class, fn () => $actions->save($otherActor->id, $inactiveCreateInput),
        'Creating new orders in an inactive branch is rejected', 'branch_id');
    $expect(ValidationException::class, fn () => $actions->submit($otherActor->id, $otherDraft->id, $otherDraft->version),
        'Submitting a historical draft in an inactive branch is rejected', 'order');
    $verify($otherDraft->fresh()->status === 'draft' && $otherDraft->fresh()->version === 1,
        'Inactive-branch create/submit denials preserve the historical draft');
    DB::table('branches')->where('id', $branchB['id'])->update(['active' => true]);

    $editedInput = $inputFor((int) $branchA['id']);
    $editedInput['project_name'] = 'Synthetic updated project';
    $edited = $actions->save($actor->id, $editedInput, $draft->id, $draft->version);
    $verify($edited->version === 2 && $edited->project_name === 'Synthetic updated project',
        'A current-version draft edit advances exactly one version');
    $staleInput = $inputFor((int) $branchA['id']);
    $staleInput['project_name'] = 'Stale overwrite attempt';
    $expect(ValidationException::class, fn () => $actions->save($actor->id, $staleInput, $edited->id, 1), 'Stale concurrent draft edit is rejected', 'order');
    $verify($edited->fresh()->version === 2 && $edited->fresh()->project_name === 'Synthetic updated project',
        'A stale edit leaves current draft data unchanged');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, $editedInput, $edited->id, $edited->version + 1), 'Future draft version is rejected', 'order');
    $verify($edited->fresh()->version === 2, 'Version mismatch does not alter the persisted order');

    $expect(QueryException::class, fn () => DB::transaction(function () use ($edited): void {
        DB::table('sales_orders')->where('id', $edited->id)->update(['status' => 'approved']);
        DB::statement('SET CONSTRAINTS sales_order_commitments IMMEDIATE');
    }), 'Database requires an independent review and real Inventory commitments before an order can become approved');
    $verify($edited->fresh()->status === 'draft', 'The database rejects an approved order without independent review and Inventory commitments');

    $readiness = app(SalesOrderReadiness::class);
    $approvalBlockers = implode(' ', $readiness->blockers($edited, 'approve'));
    $verify(count($readiness->blockers($edited, 'approve')) === 1 && str_contains($approvalBlockers, 'مراجعة مستقل'),
        'A direct draft has one independent-review prerequisite before approval readiness');
    $expect(ValidationException::class, fn () => $readiness->guard($dynamicApprover->id, $edited->id, $edited->version, 'approve'),
        'An authorized M01 approver cannot approve a direct draft before an independent review exists', 'readiness');
    $operationEvidence = [
        'approve' => 'مراجعة مستقل',
        'amend' => 'معتمدة',
        'delivery_create' => 'غير معتمد',
        'delivery_confirm' => 'غير معتمد',
        'invoice_view' => 'فاتورة',
        'close' => 'فواتير',
        'return_create' => 'مرتجع',
        'convert' => 'المقبول',
    ];
    foreach ($operationEvidence as $operation => $evidence) {
        $blockers = $readiness->blockers($edited, $operation);
        $verify($blockers !== [] && str_contains(implode(' ', $blockers), $evidence),
            "{$operation} readiness identifies its actual unavailable business dependency");
        $expect(ValidationException::class, fn () => $readiness->guard($actor->id, $edited->id, $edited->version, $operation),
            "{$operation} is blocked despite explicit permission grants", 'readiness', $evidence);
        $verify($edited->fresh()->status === 'draft' && $edited->fresh()->version === 2,
            "{$operation} denial leaves the order unchanged");
    }

    $exportPath = app(SalesOrderExportDefinition::class)->export($actor->id);
    $exportPaths[] = $exportPath;
    $allowedExportRoot = realpath(storage_path('app/private/data-operations/tmp'));
    $actualExportPath = realpath($exportPath);
    $verify($allowedExportRoot !== false && $actualExportPath !== false
        && str_starts_with($actualExportPath, $allowedExportRoot.DIRECTORY_SEPARATOR),
        'Sales Order export is written through WorkbookCodec to its private temporary folder');
    $reader = new Xlsx;
    $reader->setReadDataOnly(true);
    $workbook = $reader->load($exportPath);
    try {
        $rows = $workbook->getActiveSheet()->toArray(null, false, false, false);
    } finally {
        $workbook->disconnectWorksheets();
    }
    $verify(($rows[0][0] ?? null) === 'معرف المسودة'
        && count($rows) === 2
        && ($rows[1][0] ?? null) === 'DRAFT-'.$edited->draft_key
        && ($rows[1][1] ?? null) === $branchA['code']
        && ! in_array($branchB['code'], $rows[1], true)
        && ($rows[1][14] ?? null) === 'EGP'
        && ($rows[1][15] ?? null) === 'PROPOSED UNROUNDED DRAFT; NO TAX OR OFFICIAL TOTAL',
        'WorkbookCodec export contains only authorized branch rows and labels values as unrounded proposals');
    $expect(ModelNotFoundException::class, fn () => app(SalesOrderExportDefinition::class)->export($actor->id, [], $otherDraft->id),
        'Single-order export cannot cross the actor branch scope');

    $audit = DB::table('audit_entries')->where('source_module', 'Sales')->where('source_type', 'sales_order')
        ->where('source_id', $draft->id)->where('action', 'draft_created')->orderByDesc('id')->first();
    $verify($audit !== null && (int) $audit->actor_id === $actor->id && $audit->outcome === 'succeeded',
        'Draft creation writes an attributed append-only Sales audit event');
    $safeAfter = $decode($audit->safe_after);
    $safeJson = Str::lower(json_encode($safeAfter, JSON_THROW_ON_ERROR));
    $verify(($safeAfter['order']['source_type'] ?? null) === 'direct'
        && ($safeAfter['lines'][0]['quantity'] ?? null) === '1.25000000'
        && ($safeAfter['lines'][0]['suggested_price'] ?? null) === ($trustedPrice['amount'] ?? null)
        && ! str_contains($safeJson, 'client-forged')
        && ! str_contains($safeJson, 'password')
        && ! str_contains($safeJson, 'token'),
        'Audit snapshot records server-resolved commercial data and excludes forged or credential fields');
    $expect(QueryException::class, fn () => DB::transaction(fn () => DB::table('audit_entries')->where('id', $audit->id)->update(['action' => 'tampered'])),
        'Sales audit entries reject updates at the database boundary');
    $expect(QueryException::class, fn () => DB::transaction(fn () => DB::table('audit_entries')->where('id', $audit->id)->delete()),
        'Sales audit entries reject deletion at the database boundary');
    $verify(DB::table('audit_entries')->where('id', $audit->id)->where('action', 'draft_created')->exists(),
        'Rejected audit mutations preserve the original event');
    $verify(app()->bound(SalesOrderHistoryReader::class), 'Sales Order history reader is bound');
    $history = app(SalesOrderHistoryReader::class)->read($actor->id, $draft->id);
    $verify(collect($history)->contains(fn (array $entry): bool => $entry['action'] === 'draft_created' && (int) $entry['actor_id'] === $actor->id),
        'Authorized history reader returns the persisted Sales Order audit event');

    $beforeSubmit = $edited->fresh()->load('lines');
    $expectedReviewSnapshot = app(SalesOrderActions::class)->snapshot($beforeSubmit);
    $review = $actions->submit($actor->id, $edited->id, $beforeSubmit->version);
    $pending = $edited->fresh()->load('lines');
    $expectedReviewJson = json_decode(json_encode($expectedReviewSnapshot, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    $verify($pending->status === 'pending_approval' && $pending->version === 3
        && $review->status === 'pending' && $review->target_version === $pending->version,
        'Submission records the pending review against the exact new order version');
    $verify($normalizeObjects($review->snapshot) === $normalizeObjects($expectedReviewJson),
        'Submission stores the complete immutable review snapshot');
    $verify($review->content_hash === hash('sha256', json_encode($expectedReviewSnapshot, JSON_THROW_ON_ERROR)),
        'Review content hash matches the submitted snapshot');
    $reviewMutationMustBeRejected(fn () => DB::table('sales_order_reviews')->where('id', $review->id)->update(['snapshot' => json_encode(['tampered' => true], JSON_THROW_ON_ERROR)]),
        'Database rejects mutation of a pending review snapshot');
    $reviewMutationMustBeRejected(fn () => DB::table('sales_order_reviews')->where('id', $review->id)->delete(),
        'Database rejects deletion of pending review history');
    $verify($normalizeObjects($review->fresh()->snapshot) === $normalizeObjects($review->snapshot)
        && $review->fresh()->content_hash === $review->content_hash,
        'Rejected pending-review database probes preserve snapshot and hash');
    $reviewAudit = DB::table('audit_entries')->where('source_module', 'Sales')->where('source_type', 'sales_order')
        ->where('source_id', $edited->id)->where('action', 'review_submitted')->orderByDesc('id')->first();
    $reviewAuditAfter = $reviewAudit ? $decode($reviewAudit->safe_after) : [];
    $verify($reviewAudit !== null && (int) $reviewAudit->actor_id === $actor->id
        && ($reviewAuditAfter['status'] ?? null) === 'pending_approval'
        && ($reviewAuditAfter['reservation'] ?? null) === 'not_requested',
        'Review audit states that submission requested review and did not reserve inventory');

    $expect(ValidationException::class, fn () => $actions->withdraw($actor->id, $edited->id, 2),
        'Stale review action is rejected', 'order');
    $expect(ValidationException::class, fn () => $actions->save($actor->id, $editedInput, $edited->id, $pending->version),
        'Generic save cannot modify a pending-review snapshot', 'order');
    $verify($review->fresh()->status === 'pending' && $edited->fresh()->status === 'pending_approval',
        'Stale action and generic edit preserve the pending review');
    $actions->withdraw($actor->id, $edited->id, $pending->version);
    $withdrawn = $review->fresh();
    $afterWithdraw = $edited->fresh();
    $verify($withdrawn->status === 'withdrawn' && $withdrawn->decided_by === $actor->id
        && $withdrawn->decided_at !== null && $afterWithdraw->status === 'draft' && $afterWithdraw->version === 4,
        'Requester withdrawal closes only its pending review and returns the order to draft');

    $secondReview = $actions->submit($actor->id, $edited->id, $afterWithdraw->version);
    $secondPending = $edited->fresh();
    $expect(ValidationException::class, function () use ($actions, $reviewer, $edited, $secondPending): void {
        DB::transaction(function () use ($actions, $reviewer, $edited, $secondPending): void {
            DB::table('sales_order_lines')->where('sales_order_id', $edited->id)
                ->update(['quantity' => '9.00000000']);
            $actions->reject($reviewer->id, $edited->id, $secondPending->version, 'تعديل بيانات نسخة المراجعة');
        });
    }, 'A decision against content changed after review submission is rejected', 'order');
    $verify($secondReview->fresh()->status === 'pending'
        && $edited->fresh()->lines->first()->quantity === '1.25000000',
        'Rejected stale-content decision preserves the pending review and original line quantity');
    $expect(ValidationException::class, fn () => $actions->reject($actor->id, $edited->id, $secondPending->version, 'رفض ذاتي تجريبي'),
        'Review creator cannot reject their own request', 'order');
    $verify($secondReview->fresh()->status === 'pending' && $edited->fresh()->status === 'pending_approval',
        'Self-rejection leaves the review and order pending');
    $actions->reject($reviewer->id, $edited->id, $secondPending->version, 'سبب رفض تجريبي كافٍ');
    $rejected = $secondReview->fresh();
    $afterReject = $edited->fresh();
    $verify($rejected->status === 'rejected' && $rejected->decided_by === $reviewer->id
        && $rejected->reason === 'سبب رفض تجريبي كافٍ' && $rejected->decided_at !== null
        && $afterReject->status === 'draft' && $afterReject->version === 6,
        'A separately authorized reviewer can reject with an attributable reason and preserve the draft');
    $reviewMutationMustBeRejected(fn () => DB::table('sales_order_reviews')->where('id', $rejected->id)->update(['status' => 'pending']),
        'Database rejects status changes to completed review history');
    $reviewMutationMustBeRejected(fn () => DB::table('sales_order_reviews')->where('id', $rejected->id)->delete(),
        'Database rejects deletion of completed review history');
    $verify($rejected->fresh()->status === 'rejected' && $rejected->fresh()->reason === 'سبب رفض تجريبي كافٍ'
        && $rejected->fresh()->content_hash === $secondReview->content_hash,
        'Rejected completed-review database probes preserve the decision and snapshot hash');

    $thirdReview = $actions->submit($actor->id, $edited->id, $afterReject->version);
    $thirdPending = $edited->fresh();
    $actions->cancel($actor->id, $edited->id, $thirdPending->version, 'إلغاء تجريبي للكمية غير المنفذة');
    $cancelled = $edited->fresh();
    $verify($cancelled->status === 'cancelled' && $cancelled->version === 8
        && $thirdReview->fresh()->status === 'withdrawn'
        && $thirdReview->fresh()->decided_by === $actor->id
        && $cancelled->lines->first()->quantity === '1.25000000',
        'Pending cancellation preserves line quantities and closes review as withdrawn without operational release');

    $readinessCount = DB::table('sales_order_reviews')->where('sales_order_id', $edited->id)->count();
    $expect(ValidationException::class, fn () => $readiness->guard($actor->id, $edited->id, $cancelled->version, 'approve'),
        'Cancelled order remains blocked from approval', 'readiness');
    $verify(DB::table('sales_order_reviews')->where('sales_order_id', $edited->id)->count() === $readinessCount
        && $edited->fresh()->status === 'cancelled' && $edited->fresh()->version === 8,
        'Blocked downstream operations create no approval or inventory records');

    $revocationDraft = $actions->save($actor->id, $inputFor((int) $branchA['id']));
    DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M04.sales_order_edit')
        ->where('branch_id', $branchA['id'])->delete();
    $verify($access->allows($actor->id, 'view', (int) $branchA['id'])
        && ! $access->allows($actor->id, 'edit', (int) $branchA['id']),
        'Permission revocation takes effect immediately for draft edits');
    $revokeEditInput = $inputFor((int) $branchA['id']);
    $expect(HttpException::class, fn () => $actions->save($actor->id, $revokeEditInput, $revocationDraft->id, $revocationDraft->version),
        'Revoked edit grant immediately blocks a write');
    $verify($revocationDraft->fresh()->version === 1, 'Revoked edit attempt leaves the order unchanged');
    DB::table('access_grants')->where('user_id', $actor->id)->where('permission_code', 'M04.sales_order_view')
        ->where('branch_id', $branchA['id'])->delete();
    $verify(! $access->allows($actor->id, 'view', (int) $branchA['id']),
        'Permission revocation takes effect immediately for reads');
    $expect(HttpException::class, fn () => $query->query($actor->id), 'Revoked view grant immediately blocks queries');
    $expect(HttpException::class, fn () => $actions->cancel($actor->id, $revocationDraft->id, $revocationDraft->version, 'رفض بسبب سحب الصلاحية'),
        'Revoked view grant immediately blocks actions');
    $verify($revocationDraft->fresh()->status === 'draft' && $revocationDraft->fresh()->version === 1,
        'Revoked read/action access cannot change order state');

    if ($reviewGuardFailures !== []) {
        throw new RuntimeException('Database allowed Sales Order review-history mutation: '.implode('; ', $reviewGuardFailures));
    }

    fwrite(STDOUT, "Sales Order checks passed: direct drafts, exact recalculation, branch-scoped authorization/export, route and screen rendering, review lifecycle, cancellation, audit integrity, Administrator scope enforcement, and fail-closed downstream readiness.\n");
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    Auth::forgetGuards();
    foreach ($exportPaths as $path) {
        if (is_file($path)) {
            File::delete($path);
        }
    }
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'FAIL '.$failure::class.': '.$failure->getMessage()."\n");
    exit(1);
}

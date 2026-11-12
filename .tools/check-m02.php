<?php

use App\Models\User;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\FindByBarcodeRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Application\ProductActions;
use App\Modules\Products\Application\ProductMasters;
use App\Modules\Products\Application\ProductMediaActions;
use App\Modules\Products\Application\ProductsService;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductHistoryReader;
use App\Modules\Products\Domain\ProductMedia;
use App\Modules\Products\Domain\Unit;
use App\Modules\Products\Presentation\CatalogPage;
use App\Modules\Products\Presentation\MastersPage;
use App\Modules\Products\Presentation\ProductDetailsPage;
use App\Modules\Products\Presentation\ProductFormPage;
use App\Modules\Products\Presentation\ProductMediaController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('M02 check requires the separate PostgreSQL test database.');
}

function m02Verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
}

function m02Validation(Closure $action, string $field, string $message): void
{
    try {
        $action();
    } catch (ValidationException $exception) {
        m02Verify(array_key_exists($field, $exception->errors()), $message);

        return;
    }

    throw new RuntimeException($message.' (no validation error)');
}

function m02Forbidden(Closure $action, string $message): void
{
    try {
        $action();
    } catch (HttpException $exception) {
        m02Verify($exception->getStatusCode() === 403, $message);

        return;
    }

    throw new RuntimeException($message.' (no authorization error)');
}

function m02NotFound(Closure $action, string $message): void
{
    try {
        $action();
    } catch (ModelNotFoundException) {
        m02Verify(true, $message);

        return;
    }

    throw new RuntimeException($message.' (record remained visible)');
}

function m02Grant(User $user, array $permissions): void
{
    foreach ($permissions as $permission) {
        DB::table('access_grants')->insert([
            'user_id' => $user->id,
            'permission_code' => $permission,
            'branch_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

$suffix = strtoupper(bin2hex(random_bytes(5)));
$testDiskParent = storage_path('framework/testing/disks');
$testDiskRoot = $testDiskParent.DIRECTORY_SEPARATOR.'m02-check-'.$suffix;
if (! str_starts_with($testDiskRoot, $testDiskParent.DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Refusing to use a media test path outside the isolated test disk folder.');
}
config(['filesystems.disks.local.root' => $testDiskRoot]);
Storage::forgetDisk('local');

DB::beginTransaction();
try {
    $registered = DB::table('permission_catalog')->where('code', 'like', 'M02.%')->get(['code', 'scope']);
    m02Verify($registered->isNotEmpty() && $registered->every(fn ($row) => $row->scope === 'company'), 'M02 permissions are registered at company scope');
    $administratorRoleId = (int) DB::table('roles')->where('code', 'Administrator')->value('id');
    $registeredCodes = $registered->pluck('code')->sort()->values()->all();
    $administratorCodes = DB::table('role_permissions')->where('role_id', $administratorRoleId)->whereIn('permission_code', $registeredCodes)
        ->orderBy('permission_code')->pluck('permission_code')->all();
    $nonAdministratorM02 = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')
        ->where('r.code', '<>', 'Administrator')->where('rp.permission_code', 'like', 'M02.%')->exists();
    m02Verify($administratorRoleId > 0 && $administratorCodes === $registeredCodes && ! $nonAdministratorM02,
        'M02 permissions default-deny for non-administrator roles while Administrator receives all registered keys');

    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $withoutGrants = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $viewOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $mediaOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $updaterOnly = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    $access = app(ProductAccess::class);
    $readOnlyRoleId = (int) DB::table('roles')->where('code', 'ManagerReadOnly')->value('id');
    DB::table('user_roles')->insert(['user_id' => $withoutGrants->id, 'role_id' => $readOnlyRoleId, 'created_at' => now(), 'updated_at' => now()]);
    m02Verify(! $access->allows($withoutGrants->id, 'M02.product_view') && ! $access->allows($withoutGrants->id, 'M02.unknown'), 'non-administrator ManagerReadOnly membership does not grant Product permissions');
    m02Forbidden(fn () => app(ProductMasters::class)->save($withoutGrants->id, 'category', ['code' => 'DENIED-'.$suffix, 'name' => 'Denied', 'active' => true]), 'category mutation requires an explicit grant');

    m02Grant($actor, [
        'M02.product_view', 'M02.product_create', 'M02.product_update', 'M02.product_status',
        'M02.category_manage', 'M02.brand_manage', 'M02.unit_manage', 'M02.media_manage',
    ]);
    m02Grant($viewOnly, ['M02.product_view']);
    m02Grant($mediaOnly, ['M02.media_manage']);
    m02Grant($updaterOnly, ['M02.product_update']);
    m02Verify(DB::table('user_roles')->where('user_id', $actor->id)->doesntExist(), 'company-wide product access is a direct grant without role assignment');
    m02Verify($access->allows($actor->id, 'M02.product_view'), 'explicit company grant authorizes product access');

    $masters = app(ProductMasters::class);
    $category = $masters->save($actor->id, 'category', ['code' => 'cat-'.$suffix, 'name' => 'التصنيفات', 'active' => true]);
    m02Verify($category instanceof Category && $category->code === 'CAT-'.$suffix && $category->version === 1, 'category code is canonical and versioned');
    $child = $masters->save($actor->id, 'category', ['code' => 'SUB-'.$suffix, 'name' => 'فرعي', 'active' => true, 'parent_id' => $category->id]);
    m02Verify((int) $child->parent_id === (int) $category->id, 'category parent-child relationship persists');
    m02Validation(fn () => $masters->save($actor->id, 'category', ['code' => 'SELF-'.$suffix, 'name' => 'ذاتي', 'active' => true, 'parent_id' => $category->id], $category->id, 1), 'parent_id', 'category rejects self-parenting');
    m02Validation(fn () => $masters->save($actor->id, 'category', ['code' => $category->code, 'name' => 'حلقة', 'active' => true, 'parent_id' => $child->id], $category->id, 1), 'parent_id', 'category rejects circular ancestry');
    m02Validation(fn () => $masters->save($actor->id, 'category', ['code' => strtolower($category->code), 'name' => 'مكرر', 'active' => true]), 'code', 'category code uniqueness applies after canonicalization');
    $category = $masters->save($actor->id, 'category', ['code' => $category->code, 'name' => 'التصنيفات المحدثة', 'active' => true], $category->id, 1);
    m02Validation(fn () => $masters->save($actor->id, 'category', ['code' => $category->code, 'name' => 'قديم', 'active' => true], $category->id, 1), 'name', 'master updates reject stale versions');

    $brand = $masters->save($actor->id, 'brand', ['code' => 'BR-'.$suffix, 'name' => 'M02 Brand '.$suffix, 'active' => true]);
    m02Validation(fn () => $masters->save($actor->id, 'brand', ['code' => 'BR-DUP-'.$suffix, 'name' => '  m02 brand '.$suffix.'  ', 'active' => true]), 'name', 'brand names reject case and surrounding-space duplicates');
    $baseUnit = $masters->save($actor->id, 'unit', ['code' => 'PCS-'.$suffix, 'name' => 'قطعة', 'active' => true, 'dimension' => 'count']);
    $packUnit = $masters->save($actor->id, 'unit', ['code' => 'BOX-'.$suffix, 'name' => 'عبوة', 'active' => true, 'dimension' => 'count']);
    $lengthUnit = $masters->save($actor->id, 'unit', ['code' => 'MTR-'.$suffix, 'name' => 'متر', 'active' => true, 'dimension' => 'length']);
    m02Verify($brand instanceof Brand && $baseUnit instanceof Unit && $packUnit instanceof Unit && $lengthUnit instanceof Unit, 'brand and dimensional unit masters persist');

    $productInput = static fn (string $sku, string $barcode, int $unitId, int $categoryId, int $brandId): array => [
        'sku' => $sku,
        'name' => 'منتج اختبار '.$suffix,
        'name_en' => 'M02 check '.$suffix,
        'short_description' => null,
        'description' => null,
        'kind' => 'bulk',
        'tracking' => 'quantity',
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'model' => 'MODEL-'.$suffix,
        'base_unit_id' => $unitId,
        'barcode' => $barcode,
        'active' => true,
    ];
    $actions = app(ProductActions::class);
    $mediaActions = app(ProductMediaActions::class);
    $sku = 'M02-'.$suffix;
    $barcode = 'BC-'.$suffix;
    $input = $productInput(' '.$sku.' ', $barcode, $baseUnit->id, $category->id, $brand->id);
    $input['purchase_unit_id'] = $packUnit->id;
    $input['sales_unit_id'] = $baseUnit->id;
    $attributes = [['name' => 'voltage', 'value' => '230 V', 'unit_id' => null]];
    $conversions = [['unit_id' => $packUnit->id, 'factor' => '10.12345678']];

    $missingTradeConversion = $productInput('TRADE-MISSING-'.$suffix, 'TRADE-MISSING-BC-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    $missingTradeConversion['purchase_unit_id'] = $packUnit->id;
    $missingTradeConversion['sales_unit_id'] = $baseUnit->id;
    m02Validation(fn () => $actions->save($actor->id, $missingTradeConversion), 'purchase_unit_id', 'explicit trade unit requires a conversion to the base unit');

    $countBefore = Product::count();
    $notedInput = $input;
    $notedInput['internal_notes'] = 'restricted note';
    m02Forbidden(fn () => $actions->save($actor->id, $notedInput, null, null, $conversions, $attributes), 'internal notes require their separate update permission');
    m02Verify(Product::count() === $countBefore, 'denied sensitive-field write persists no product');

    $product = $actions->save($actor->id, $input, null, null, $conversions, $attributes);
    m02Verify($product->sku === $sku && (int) $product->base_unit_id === (int) $baseUnit->id
        && (int) $product->purchase_unit_id === (int) $packUnit->id && (int) $product->sales_unit_id === (int) $baseUnit->id,
        'product accepts an explicit converted purchase unit and base-unit sales default');
    $savedFactor = (string) $product->conversions()->where('unit_id', $packUnit->id)->value('factor');
    m02Verify(bccomp($savedFactor, '10.12345678', 8) === 0, 'unit conversion persists exact decimal precision');
    m02Verify(count($product->attributes) === 1 && $product->attributes[0]['value'] === '230 V', 'product attributes persist as structured values');

    $api = app(ProductsApi::class);
    m02Verify($api instanceof ProductsService && app(ProductsApi::class) instanceof ProductsService, 'ProductsApi resolves through ProductsService');
    foreach ([GetProductRequest::class, FindByBarcodeRequest::class, ConvertQuantityRequest::class, ListMastersRequest::class] as $type) {
        m02Verify((new ReflectionClass($type))->isReadOnly(), class_basename($type).' is readonly');
    }
    $publicProduct = $api->getProduct(new GetProductRequest($actor->id, $product->id))->product;
    m02Verify($publicProduct['sku'] === $sku && ! array_key_exists('internal_notes', $publicProduct) && ! array_key_exists('attributes', $publicProduct), 'getProduct returns the approved safe identity projection');
    $barcodeMatch = $api->findByBarcode(new FindByBarcodeRequest($actor->id, $barcode))->product;
    m02Verify($barcodeMatch['id'] === $product->id, 'barcode lookup resolves its active product');
    $listed = $api->listMasters(new ListMastersRequest($actor->id, 'products', $sku, true, 1, 10));
    m02Verify($listed->total === 1 && $listed->items[0]['sku'] === $sku, 'product selector uses bounded database search and pagination');
    $converted = $api->convertQuantity(new ConvertQuantityRequest($actor->id, $product->id, $packUnit->id, '2.5'));
    m02Verify($converted->baseQuantity === '25.30864195' && $converted->baseUnitId === $baseUnit->id, 'public conversion returns an exact base-unit decimal');
    $baseConverted = $api->convertQuantity(new ConvertQuantityRequest($actor->id, $product->id, $baseUnit->id, '2.5'));
    m02Verify($baseConverted->baseQuantity === '2.50000000', 'public conversion preserves quantity in the base unit');
    m02Validation(fn () => $api->convertQuantity(new ConvertQuantityRequest($actor->id, $product->id, $packUnit->id, '0.00000001')), 'quantity', 'public conversion rejects silent rounding beyond supported precision');
    m02Forbidden(fn () => $api->getProduct(new GetProductRequest($withoutGrants->id, $product->id)), 'public product lookup reauthorizes every actor');

    $guard = Auth::guard();
    $guard->setUser($actor);
    Livewire::test(CatalogPage::class)->assertSee($sku);
    Livewire::test(MastersPage::class, ['kind' => 'category'])->assertSee($category->name);
    Livewire::test(ProductFormPage::class, ['product' => $product->id])->assertSee($sku);
    Livewire::test(ProductDetailsPage::class, ['product' => $product->id])->assertSee($sku);
    m02Verify(true, 'catalog, category master, edit form and details render persisted records');

    $duplicateSku = $productInput(strtolower($sku), 'NEW-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    m02Validation(fn () => $actions->save($actor->id, $duplicateSku), 'sku', 'SKU uniqueness is enforced after canonicalization');
    $duplicateBarcode = $productInput('OTHER-'.$suffix, $barcode, $baseUnit->id, $category->id, $brand->id);
    m02Validation(fn () => $actions->save($actor->id, $duplicateBarcode), 'barcode', 'barcode cannot belong to two products');

    $crossDimension = $productInput('CROSS-'.$suffix, 'CROSSBC-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    m02Validation(fn () => $actions->save($actor->id, $crossDimension, null, null, [['unit_id' => $lengthUnit->id, 'factor' => '2']], []), 'conversions.0.factor', 'conversion rejects a different unit dimension');
    $zeroFactor = $productInput('ZERO-'.$suffix, 'ZERObc-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    m02Validation(fn () => $actions->save($actor->id, $zeroFactor, null, null, [['unit_id' => $packUnit->id, 'factor' => '0']], []), 'conversions.0.factor', 'conversion rejects zero factors');
    $negativeFactor = $productInput('NEG-'.$suffix, 'NEGbc-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    m02Validation(fn () => $actions->save($actor->id, $negativeFactor, null, null, [['unit_id' => $packUnit->id, 'factor' => '-1']], []), 'conversions.0.factor', 'conversion rejects negative factors');
    $floatFactor = $productInput('FLOAT-'.$suffix, 'FLOATbc-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    m02Validation(fn () => $actions->save($actor->id, $floatFactor, null, null, [['unit_id' => $packUnit->id, 'factor' => 1.25]], []), 'conversions.0.factor', 'conversion input must remain a decimal string');
    m02Verify(Product::count() === $countBefore + 1, 'invalid catalog writes leave no partial products');

    Livewire::test(ProductFormPage::class)
        ->set('form.sku', 'UI-'.$suffix)
        ->set('form.name', 'منتج من النموذج')
        ->set('form.kind', 'bulk')
        ->set('form.tracking', 'quantity')
        ->set('form.category_id', $category->id)
        ->set('form.brand_id', $brand->id)
        ->set('form.base_unit_id', $baseUnit->id)
        ->set('form.barcode', 'UIBC-'.$suffix)
        ->set('form.active', true)
        ->set('conversions', [['unit_id' => $packUnit->id, 'factor' => '2.5']])
        ->set('specifications', [['name' => 'current', 'value' => '15 A', 'unit_id' => null]])
        ->call('save');
    $formProduct = Product::where('sku', 'UI-'.$suffix)->firstOrFail();
    m02Verify($formProduct->attributes[0]['name'] === 'current' && $formProduct->attributes[0]['value'] === '15 A', 'product form maps public specifications into persisted attributes');

    Livewire::test(ProductFormPage::class)
        ->set('form.sku', 'BAD-SPEC-'.$suffix)
        ->set('form.name', 'منتج بمواصفة غير صالحة')
        ->set('form.kind', 'bulk')
        ->set('form.tracking', 'quantity')
        ->set('form.category_id', $category->id)
        ->set('form.brand_id', $brand->id)
        ->set('form.base_unit_id', $baseUnit->id)
        ->set('form.barcode', 'BAD-SPEC-BC-'.$suffix)
        ->set('form.active', true)
        ->set('specifications', [['name' => '', 'value' => '230 V', 'unit_id' => null]])
        ->call('save')->assertHasErrors(['specifications.0.name']);
    m02Verify(! Product::where('sku', 'BAD-SPEC-'.$suffix)->exists(), 'invalid specification errors bind to the public form field without saving');

    m02Validation(fn () => $actions->save($actor->id, $input, $product->id, 1, [['unit_id' => $packUnit->id, 'factor' => '11']], $attributes), 'conversions', 'saved conversion definitions cannot be rewritten');
    $changedSku = $input;
    $changedSku['sku'] = 'RENAMED-'.$suffix;
    m02Validation(fn () => $actions->save($actor->id, $changedSku, $product->id, 1, $conversions, $attributes), 'sku', 'product SKU is stable after creation');
    $changedUnit = $input;
    $changedUnit['base_unit_id'] = $packUnit->id;
    m02Validation(fn () => $actions->save($actor->id, $changedUnit, $product->id, 1, $conversions, $attributes), 'base_unit_id', 'product base unit is stable after creation');

    m02Grant($actor, ['M02.notes_update']);
    $notedInput['name'] = 'منتج بملاحظة';
    $product = $actions->save($actor->id, $notedInput, $product->id, 1, $conversions, $attributes);
    m02Verify($product->internal_notes === 'restricted note' && ! array_key_exists('internal_notes', $product->toArray()), 'internal notes are stored only with permission and hidden from default serialization');
    $productAudit = DB::table('audit_entries')->where('source_module', 'Products')->where('source_type', 'product')
        ->where('source_id', $product->id)->where('action', 'updated')->orderByDesc('id')->first();
    $productHistory = app(ProductHistoryReader::class)->forProduct($product->id)->first();
    m02Verify($productAudit && (int) $productAudit->actor_id === $actor->id
        && ! str_contains((string) $productAudit->safe_before, 'restricted note') && ! str_contains($productAudit->safe_after, 'restricted note')
        && $productHistory['scope'] === 'الصنف #'.$product->id && $productHistory['actor'] === 'مستخدم #'.$actor->id
        && ! in_array('ملاحظات داخلية', array_keys($productHistory['after']), true),
        'product audit uses the Products owner, records its actor and scope, and excludes internal notes');
    $updatedInput = $input;
    $updatedInput['name'] = 'منتج محدث';
    $product = $actions->save($actor->id, $updatedInput, $product->id, 2, $conversions, $attributes);
    m02Verify($product->name === 'منتج محدث' && $product->version === 3, 'product update persists atomically and advances its version');
    m02Validation(fn () => $actions->save($actor->id, $updatedInput, $product->id, 2, $conversions, $attributes), 'sku', 'product update rejects stale versions');
    m02Forbidden(fn () => $actions->changeStatus($updaterOnly->id, $product->id, 3), 'product edit grant does not imply status authority');
    m02Verify(Product::findOrFail($product->id)->active, 'denied status change leaves product active');
    $product = $actions->changeStatus($actor->id, $product->id, 3);
    m02Verify(! $product->active && $product->version === 4, 'authorized status change deactivates without deleting the product');
    m02Validation(fn () => $actions->changeStatus($actor->id, $product->id, 3), 'status', 'status change rejects stale versions');

    $tradeInput = $productInput('TRADE-EXISTING-'.$suffix, 'TRADE-EXISTING-BC-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    $tradeInput['purchase_unit_id'] = $packUnit->id;
    $tradeInput['sales_unit_id'] = $baseUnit->id;
    $tradeProduct = $actions->save($actor->id, $tradeInput, null, null, $conversions);
    $inactivePack = $masters->save($actor->id, 'unit', ['code' => $packUnit->code, 'name' => $packUnit->name, 'active' => false, 'dimension' => $packUnit->dimension], $packUnit->id, $packUnit->version);
    $newInactiveConversion = $productInput('TRADE-INACTIVE-NEW-'.$suffix, 'TRADE-INACTIVE-NEW-BC-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    m02Validation(fn () => $actions->save($actor->id, $newInactiveConversion, null, null, $conversions), 'conversions.0.factor', 'new products reject inactive alternative conversion units');
    $tradeInput['name'] = 'منتج بوحدة تحويل محفوظة';
    $tradeProduct = $actions->save($actor->id, $tradeInput, $tradeProduct->id, $tradeProduct->version, $conversions);
    m02Verify($tradeProduct->version === 2 && (int) $tradeProduct->purchase_unit_id === (int) $inactivePack->id,
        'existing products remain editable while preserving an inactive historical conversion');

    $mediaFile = UploadedFile::fake()->image('unsafe<script>.png', 24, 24);
    m02Forbidden(fn () => $mediaActions->upload($viewOnly->id, $product->id, $mediaFile), 'media upload requires separate media-management permission');
    m02Forbidden(fn () => $mediaActions->upload($mediaOnly->id, $product->id, $mediaFile), 'media upload also requires product-view permission');
    $media = $mediaActions->upload($actor->id, $product->id, $mediaFile);
    m02Verify($media instanceof ProductMedia && $media->created_by === $actor->id && Storage::disk('local')->exists($media->path), 'safe product image is stored privately with actor metadata');
    m02Verify(str_starts_with($media->path, 'product-media/'.$product->id.'/') && ! preg_match('/[<>\\\\\/]/', $media->name), 'media path is product-owned and filename is sanitized');
    m02Validation(fn () => $mediaActions->upload($actor->id, $product->id, UploadedFile::fake()->create('payload.exe', 10, 'application/x-msdownload')), 'file', 'media rejects unsupported upload types');
    m02Validation(fn () => $mediaActions->upload($actor->id, $product->id, UploadedFile::fake()->create('oversized.pdf', 5121, 'application/pdf')), 'file', 'media rejects oversized uploads');

    $rollbackUploadInput = $productInput('ROLLBACK-UPLOAD-'.$suffix, 'ROLLBACK-UPLOAD-BC-'.$suffix, $baseUnit->id, $category->id, $brand->id);
    $productsBeforeFailedUpload = Product::count();
    $mediaBeforeFailedUpload = ProductMedia::count();
    $auditsBeforeFailedUpload = DB::table('audit_entries')->where('source_module', 'Products')->count();
    $filesBeforeFailedUpload = collect(Storage::disk('local')->allFiles())->sort()->values()->all();
    $oversizedImageRejected = false;
    try {
        $actions->saveWithMedia($actor->id, $rollbackUploadInput, null, null, [], [], [
            UploadedFile::fake()->image('valid-first.png', 24, 24),
            UploadedFile::fake()->image('too-wide-second.png', 6001, 24),
        ]);
    } catch (ValidationException $exception) {
        $oversizedImageRejected = array_key_exists('uploads', $exception->errors());
    }
    m02Verify($oversizedImageRejected && ! Product::where('sku', 'ROLLBACK-UPLOAD-'.$suffix)->exists()
        && Product::count() === $productsBeforeFailedUpload && ProductMedia::count() === $mediaBeforeFailedUpload
        && DB::table('audit_entries')->where('source_module', 'Products')->count() === $auditsBeforeFailedUpload
        && collect(Storage::disk('local')->allFiles())->sort()->values()->all() === $filesBeforeFailedUpload,
        'invalid second oversized image rolls back the product, media, audit entries, and earlier stored file');

    $guard->setUser($withoutGrants);
    m02Forbidden(fn () => app(ProductMediaController::class)($media->id), 'direct media download requires current product-view access');
    $guard->setUser($viewOnly);
    $response = app(ProductMediaController::class)($media->id);
    $cacheDirectives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
    m02Verify($response->headers->get('X-Content-Type-Options') === 'nosniff' && in_array('private', $cacheDirectives, true) && in_array('no-store', $cacheDirectives, true), 'authorized media response carries private content-safety headers');
    $mediaActions->remove($actor->id, $media->id);
    m02Verify(ProductMedia::findOrFail($media->id)->removed_at !== null && Storage::disk('local')->exists($media->path), 'media removal preserves its file and history');
    m02NotFound(fn () => app(ProductMediaController::class)($media->id), 'removed media is no longer downloadable');
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Storage::forgetDisk('local');
    File::deleteDirectory($testDiskRoot);
    echo "Disposable M02 database transaction rolled back.\n";
}

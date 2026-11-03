<?php

namespace Database\Seeders;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerAddress;
use App\Modules\Parties\Domain\CustomerContact;
use App\Modules\Parties\Domain\CustomerDocument;
use App\Modules\Parties\Domain\CustomerNote;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierAddress;
use App\Modules\Parties\Domain\SupplierCategory;
use App\Modules\Parties\Domain\SupplierContact;
use App\Modules\Parties\Domain\SupplierDocument;
use App\Modules\Parties\Domain\SupplierNote;
use App\Modules\Products\Domain\Brand;
use App\Modules\Products\Domain\Category;
use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductConversion;
use App\Modules\Products\Domain\ProductMedia;
use App\Modules\Products\Domain\Unit;
use App\Modules\Sales\Application\DraftAmounts;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationAttachment;
use App\Modules\Sales\Domain\SalesOrder;
use App\Modules\System\Application\RecordAudit;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use Database\Factories\Support\EgyptianMarket as Egypt;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/** Explicit, repeat-safe local/testing composition of owned synthetic business fixtures. */
class EgyptMarketDemoSeeder extends Seeder
{
    public const DEFAULTS = ['products' => 2000, 'customers' => 1200, 'suppliers' => 600, 'quotations' => 600, 'branches' => 30, 'users' => 120, 'orders' => 400];

    public array $summary = [];

    private array $createdFiles = [];

    private int $actor;

    public function run(): void
    {
        $this->seed();
        $this->call(ProjectsPurchasingVolumeSeeder::class);
    }

    public function seed(array $sizes = []): array
    {
        $this->assertTarget();
        $this->createdFiles = [];
        $this->summary = [];
        foreach (self::DEFAULTS as $key => $default) {
            $sizes[$key] = $sizes[$key] ?? env('ERP_DEMO_'.strtoupper($key), $default);
        }
        $rules = array_fill_keys(array_keys(self::DEFAULTS), ['required', 'integer', 'min:1', 'max:50000']);
        $sizes = Validator::make($sizes, $rules)->validate();
        $this->actor = (int) env('ERP_DEMO_ACTOR_ID', 2);
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($this->actor, 'M01.security_admin'));
        try {
            DB::transaction(function () use ($sizes) {
                DB::select('SELECT pg_advisory_xact_lock(20420)');
                $branches = $this->organization((int) $sizes['branches'], (int) $sizes['users']);
                $products = $this->products((int) $sizes['products']);
                $customers = $this->parties((int) $sizes['customers'], false);
                $this->parties((int) $sizes['suppliers'], true);
                $this->quotations((int) $sizes['quotations'], $customers, $products);
                $this->orders((int) $sizes['orders'], $branches);
                $this->call(PurchasingDemoSeeder::class);
                $this->call(ProjectsDemoSeeder::class);
                $this->call(FieldMaintenanceDemoSeeder::class);
                $this->call(WorkshopDemoSeeder::class);
                $this->call(TendersDemoSeeder::class);
                $this->call(ServiceContractsDemoSeeder::class);
                $this->call(AttendanceDemoSeeder::class);
                $this->call(FinancialActivationDemoSeeder::class);
                $this->call(FinancialOperationsDemoSeeder::class);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($this->createdFiles);
            throw $e;
        }
        $this->command?->info('Egyptian-market demo data ready: '.json_encode($this->summary, JSON_UNESCAPED_UNICODE));

        $this->call(InventoryDemoSeeder::class);

        return $this->summary;
    }

    private function assertTarget(): void
    {
        $expected = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== $expected) {
            throw new \RuntimeException('Egyptian demo data is restricted to the exact local/testing PostgreSQL databases.');
        }
    }

    private function audit(string $owner, string $type, int $id, array $after): void
    {
        app(RecordAudit::class)->execute(null, $type, $id, 'demo.created', null, $after + ['dataset' => 'egypt-market-v1'], 'succeeded', $owner);
        $this->summary[$type] = ($this->summary[$type] ?? 0) + 1;
    }

    private function progress(string $message, int $i): void
    {
        if ($i % 100 === 0) {
            $this->command?->getOutput()->writeln($message.' '.$i);
        }
    }

    private function file(string $path, string $contents): string
    {
        if (Storage::disk('local')->exists($path)) {
            throw new \RuntimeException('A fixture path already exists; refusing to overwrite it.');
        }
        $this->createdFiles[] = $path;
        if (! Storage::disk('local')->put($path, $contents)) {
            throw new \RuntimeException('Unable to write private demo fixture.');
        }

        return $path;
    }

    private function organization(int $branchCount, int $userCount): array
    {
        if (! DB::table('companies')->where('id', 1)->exists()) {
            throw new \RuntimeException('Complete Organization company setup before seeding market data. Existing legal company data is never replaced.');
        }
        $ids = [];
        for ($i = 1; $i <= $branchCount; $i++) {
            $code = Egypt::PREFIX.'BR-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $existing = DB::table('branches')->where('code', $code)->first();
            if ($existing) {
                $ids[] = (int) $existing->id;

                continue;
            }
            $l = Egypt::location($i);
            $row = BranchFactory::new()->raw(['code' => $code, 'name' => 'فرع '.$l['city'].' - '.$l['governorate'], 'address' => $l['line'], 'company_id' => 1, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $id = (int) DB::table('branches')->insertGetId($row);
            $ids[] = $id;
            $this->audit('Organization', 'branch', $id, ['code' => $code, 'name' => $row['name']]);
        }
        for ($i = 1; $i <= $userCount; $i++) {
            $email = 'staff.'.$i.'@egypt-market.example.test';
            if (DB::table('users')->where('email', $email)->exists()) {
                continue;
            }
            $row = UserFactory::new()->disabledDemo()->raw(['name' => Egypt::person($i - 1), 'email' => $email, 'gender' => ($i - 1) % 20 < 10 ? 'male' : 'female', 'branch_id' => $ids[($i - 1) % count($ids)], 'created_at' => now(), 'updated_at' => now()]);
            $id = (int) DB::table('users')->insertGetId($row);
            $this->audit('Organization', 'user', $id, ['name' => $row['name'], 'email' => $email, 'active' => false, 'branch_id' => $row['branch_id']]);
        }

        return $ids;
    }

    private function products(int $count): array
    {
        $root = Category::firstOrCreate(['code' => Egypt::PREFIX.'CAT-ELECTRICAL'], ['name' => 'التجهيزات الكهربائية - بيانات مصر التجريبية', 'parent_id' => null, 'active' => true, 'version' => 1]);
        $cats = [];
        foreach (['PROTECTION' => 'القواطع وأجهزة الحماية', 'CABLES' => 'الأسلاك والكابلات', 'CONTROL' => 'التحكم والحماية الصناعية', 'LIGHTING' => 'الإنارة والكشافات', 'PANELS' => 'لوحات التوزيع', 'INSTALLATION' => 'مستلزمات التمديدات', 'ACCESSORIES' => 'ملحقات التوصيل', 'EQUIPMENT' => 'المحركات والمعدات'] as $key => $name) {
            $c = Category::firstOrCreate(['code' => Egypt::PREFIX.'CAT-'.$key], ['name' => $name, 'parent_id' => $root->id, 'active' => true, 'version' => 1]);
            $cats[$key] = $c->id;
        }
        $units = [];
        foreach ([['PIECE', 'قطعة', 'count'], ['BOX10', 'علبة 10 قطع', 'count'], ['METER', 'متر', 'length'], ['ROLL100', 'لفة 100 متر', 'length'], ['KG', 'كيلوجرام', 'mass'], ['LITER', 'لتر', 'volume'], ['HOUR', 'ساعة عمل', 'time']] as [$key,$name,$dimension]) {
            $u = Unit::firstOrCreate(['code' => Egypt::PREFIX.'UOM-'.$key], ['name' => $name, 'dimension' => $dimension, 'active' => true, 'version' => 1]);
            $units[$key] = $u->id;
        }
        $brandIds = [];
        foreach (['دلتا إليكتريك', 'النيل للتجهيزات', 'كايرو تك', 'المصرية للكابلات', 'الأفق للتحكم', 'الرواد للإنارة', 'الصفوة الصناعية', 'المنار للكهرباء', 'العاصمة للتوريدات', 'الشروق إليكتريك', 'مصر الحديثة', 'إتقان للصناعات'] as $i => $name) {
            $b = Brand::firstOrCreate(['code' => Egypt::PREFIX.'BRAND-'.($i + 1)], ['name' => $name.' (تجريبي)', 'active' => true, 'version' => 1]);
            $brandIds[$i + 1] = $b->id;
        }
        $result = [];
        for ($i = 1; $i <= $count; $i++) {
            $d = Egypt::product($i);
            $existing = Product::where('sku', $d['sku'])->first();
            if ($existing) {
                $result[] = $existing;

                continue;
            }
            $category = $d['fixture_category'];
            $base = $d['fixture_base'];
            $trade = $d['fixture_trade'];
            $brand = $d['fixture_brand'];
            $family = $d['fixture_family'];
            foreach (array_keys($d) as $key) {
                if (str_starts_with($key, 'fixture_')) {
                    unset($d[$key]);
                }
            }
            $d += ['category_id' => $cats[$category], 'brand_id' => $brandIds[$brand], 'base_unit_id' => $units[$base], 'purchase_unit_id' => $units[$trade], 'sales_unit_id' => $units[$base], 'created_by' => $this->actor, 'updated_by' => $this->actor];
            $p = Product::factory()->create($d);
            $result[] = $p;
            if ($trade !== $base) {
                ProductConversion::factory()->create(['product_id' => $p->id, 'unit_id' => $units[$trade], 'factor' => $trade === 'ROLL100' ? '100.00000000' : '10.00000000']);
            }
            $contents = $this->illustration($family, $i);
            $path = $this->file('product-media/'.$p->id.'/egypt-market-'.Uuid::uuid4().'.png', $contents);
            ProductMedia::create(['product_id' => $p->id, 'path' => $path, 'name' => 'رسم توضيحي - '.$p->name.'.png', 'mime' => 'image/png', 'size' => strlen($contents), 'is_image' => true, 'created_by' => $this->actor]);
            $pdf = $this->pdf('Egypt market catalogue illustration', $p->sku.' | '.$p->model, 'Synthetic specification, not a certified manufacturer datasheet.');
            $path = $this->file('product-media/'.$p->id.'/egypt-market-'.Uuid::uuid4().'.pdf', $pdf);
            ProductMedia::create(['product_id' => $p->id, 'path' => $path, 'name' => 'بطاقة مواصفات تجريبية - '.$p->sku.'.pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf), 'is_image' => false, 'created_by' => $this->actor]);
            $this->audit('Products', 'product', $p->id, ['sku' => $p->sku, 'name' => $p->name]);
            $this->progress('أصناف مصر التجريبية', $i);
        }

        return $result;
    }

    private function parties(int $count, bool $supplier): array
    {
        $classes = $supplier ? [Supplier::class, SupplierContact::class, SupplierAddress::class, SupplierDocument::class, SupplierNote::class] : [Customer::class, CustomerContact::class, CustomerAddress::class, CustomerDocument::class, CustomerNote::class];
        [$root,$contact,$address,$document,$note] = $classes;
        $foreign = $supplier ? 'supplier_id' : 'customer_id';
        $type = $supplier ? 'supplier' : 'customer';
        $result = [];
        $categories = [];
        if ($supplier) {
            foreach (['قواطع وحماية', 'كابلات وأسلاك', 'لوحات وتحكم', 'إنارة وكشافات', 'مستلزمات تركيب', 'معدات ومحركات'] as $i => $name) {
                $c = SupplierCategory::firstOrCreate(['code' => Egypt::PREFIX.'SCAT-'.($i + 1)], ['name' => $name, 'active' => true]);
                $categories[] = $c->id;
            }
        }
        for ($i = 1; $i <= $count; $i++) {
            $d = Egypt::party($i, $type);
            $existing = $root::where('code', $d['code'])->first();
            if ($existing) {
                $result[] = $existing;

                continue;
            }
            if ($supplier) {
                $d['category_id'] = $categories[($i - 1) % count($categories)];
            }
            $party = $root::factory()->create($d);
            $result[] = $party;
            for ($n = 0; $n < 3; $n++) {
                $contact::factory()->create([$foreign => $party->id, ...Egypt::contact($i + ($supplier ? 100000 : 0), $n), 'phone' => $d['phone']]);
            }
            foreach ($supplier ? ['registered', 'office', 'pickup'] : ['billing', 'shipping'] as $purpose) {
                $address::factory()->create([$foreign => $party->id, ...Egypt::address($i, $purpose)]);
            }
            foreach (['يتطلب تنسيق موعد التوريد مع مسؤول المشتريات قبل الوصول.', 'يراجع مهندس المشروع المقاسات وأكواد الأصناف قبل تأكيد الطلب.', 'يرجى إرفاق بيان تعبئة واضح وكود الصنف بكل شحنة.'] as $body) {
                $note::factory()->create([$foreign => $party->id, 'author_id' => null, 'body' => 'ملاحظة تجريبية: '.$body]);
            }
            foreach (['بيانات التعامل التجريبية', 'المواصفات المطلوبة للتوريد'] as $n => $name) {
                $contents = $this->pdf('Egypt market internal party fixture', $party->code.' | document '.($n + 1), 'Synthetic reference only. Not legal registration, tax proof, or approval.');
                $path = $this->file(($supplier ? 'suppliers' : 'customers').'/'.$party->id.'/egypt-market-'.Uuid::uuid4().'.pdf', $contents);
                $document::create([$foreign => $party->id, 'name' => $name.' - '.$party->code.'.pdf', 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($contents)]);
            }
            $this->audit('Parties', $type, $party->id, ['code' => $party->code, 'name' => $party->name, 'status' => $party->status]);
            $this->progress($supplier ? 'موردو مصر التجريبيون' : 'عملاء مصر التجريبيون', $i);
        }

        return $result;
    }

    private function quotations(int $count, array $customers, array $products): void
    {
        $customers = array_values(array_filter($customers, fn ($c) => $c->status === 'active'));
        $products = array_values(array_filter($products, fn ($p) => $p->active));
        if (! $customers || ! $products) {
            throw new \RuntimeException('Demo quotations need active generated customer and product references.');
        }
        for ($i = 1; $i <= $count; $i++) {
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-elec:egypt-market-v1:quotation:'.$i)->toString();
            if (Quotation::where('draft_key', $key)->exists()) {
                continue;
            }
            $customer = $customers[($i - 1) % count($customers)];
            $date = now('Africa/Cairo')->subDays(($i - 1) % 7)->toDateString();
            $lines = [];
            $lineCount = 4 + ($i % 9);
            for ($n = 0; $n < $lineCount; $n++) {
                $p = $products[($i * 7 + $n * 13) % count($products)];
                $u = Unit::findOrFail($p->base_unit_id);
                $original = (int) substr($p->sku, -6);
                $fixture = Egypt::product($original);
                $quantity = $p->tracking === 'serial' ? (string) (1 + $n % 3) : ($u->dimension === 'length' ? (string) (50 + ($n % 5) * 50) : (string) (5 + ($n % 6) * 5));
                $lines[] = ['position' => $n + 1, 'product_id' => $p->id, 'unit_id' => $u->id, 'product_snapshot' => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'description' => $p->short_description, 'unit_id' => $u->id, 'unit_code' => $u->code, 'unit_name' => $u->name, 'version' => $p->version], 'description' => $p->name.'، توريد فقط وفق بيان الكميات التجريبي.', 'quantity' => $quantity, 'suggested_price' => null, 'requested_price' => $fixture['fixture_price'].'.00', 'price_source' => null, 'discount_mode' => $n % 3 === 0 ? 'fixed' : 'percent', 'discount_value' => $n % 3 === 0 ? '5.00' : (string) ($n % 4), 'override_reason' => 'سعر مقترح ضمن بيانات العرض التجريبية؛ يحتاج مصدر أسعار معتمد وموافقة عند اللزوم.'];
            }
            $mode = $i % 2 === 0 ? 'percent' : 'fixed';
            $discount = $mode === 'percent' ? (string) ($i % 4) : '25.00';
            $calculation = app(DraftAmounts::class)->calculate($lines, $mode, $discount);
            $r = Quotation::create(['draft_key' => $key, 'number' => null, 'customer_id' => $customer->id, 'customer_snapshot' => ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name, 'entityType' => $customer->entity_type, 'status' => $customer->status], 'project_name' => ['تجهيز مبنى إداري', 'إنارة مخزن صناعي', 'لوحة تحكم خط إنتاج', 'توسعة مصنع', 'كهرباء مجمع تجاري', 'تجديد شبكة توزيع'][$i % 6].' - '.Egypt::location($i)['city'], 'rfq_reference' => Egypt::PREFIX.'RFQ-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT), 'quotation_date' => $date, 'valid_until' => CarbonImmutable::parse($date, 'Africa/Cairo')->addDays([7, 14, 21, 30][$i % 4])->toDateString(), 'currency' => 'EGP', 'status' => 'draft', 'revision' => 1, 'version' => 1, 'created_by' => $this->actor, 'updated_by' => $this->actor, 'copied_from_id' => null, 'discount_mode' => $mode, 'discount_value' => $discount, 'payment_term_id' => null, 'delivery_term_id' => null, 'payment_terms' => 'شروط مقترحة للمراجعة: يحدد جدول السداد باتفاق مكتوب قبل إصدار العرض الرسمي.', 'delivery_terms' => 'شروط مقترحة للمراجعة: تسليم إلى '.Egypt::location($i)['city'].' بعد تأكيد المواصفات والتوافر.', 'notes' => 'مسودة تجريبية للسوق المصري. لا تعني اعتماد سعر أو موافقة تجارية أو حجز مخزون أو إصدار مستند رسمي.', 'exception_reason' => 'مقترح تفاوض تجريبي يحتاج مراجعة سياسة الخصومات والصلاحية والشروط قبل التقديم.', 'calculation' => $calculation]);
            $r->lines()->createMany($lines);
            for ($n = 1; $n <= 2; $n++) {
                $contents = $this->pdf('Synthetic Egyptian RFQ working paper', $r->rfq_reference.' | attachment '.$n, 'Internal demonstration only. Not an issued quotation or customer acceptance.');
                $path = $this->file('sales/quotations/internal/'.$r->id.'/egypt-market-'.Uuid::uuid4().'.pdf', $contents);
                QuotationAttachment::create(['quotation_id' => $r->id, 'name' => ($n === 1 ? 'طلب تسعير تجريبي' : 'مواصفات توريد تجريبية').' - '.$r->rfq_reference.'.pdf', 'path' => $path, 'mime' => 'application/pdf', 'size' => strlen($contents), 'uploaded_by' => $this->actor, 'sha256' => hash('sha256', $contents)]);
            }
            $this->audit('Sales', 'quotation', $r->id, ['draft_key' => $key, 'rfq_reference' => $r->rfq_reference, 'status' => 'draft', 'lines' => count($lines)]);
            $this->progress('مسودات عروض مصر التجريبية', $i);
        }
    }

    private function orders(int $count, array $branchIds): void
    {
        if (! Schema::hasTable('sales_orders')) {
            return;
        }
        $quotes = Quotation::with('lines')->where('rfq_reference', 'like', Egypt::PREFIX.'RFQ-%')->where('status', 'draft')->orderBy('id')->get();
        if ($quotes->isEmpty()) {
            return;
        }
        for ($i = 1; $i <= $count; $i++) {
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-elec:egypt-market-v1:direct-order:'.$i)->toString();
            if (SalesOrder::where('draft_key', $key)->exists()) {
                continue;
            }
            $q = $quotes[($i - 1) % $quotes->count()];
            $branch = DB::table('branches')->where('id', $branchIds[($i - 1) % count($branchIds)])->first();
            $order = SalesOrder::create([
                'draft_key' => $key, 'number' => null, 'branch_id' => $branch->id,
                'branch_snapshot' => ['id' => (int) $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address],
                'customer_id' => $q->customer_id, 'customer_snapshot' => $q->customer_snapshot, 'source_type' => 'direct',
                'project_name' => 'طلب مباشر تجريبي - '.$q->project_name, 'customer_reference' => Egypt::PREFIX.'DIRECT-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'order_date' => now('Africa/Cairo')->toDateString(), 'requested_delivery_date' => now('Africa/Cairo')->addDays(3 + $i % 15)->toDateString(),
                'currency' => 'EGP', 'status' => 'draft', 'version' => 1, 'discount_mode' => $q->discount_mode, 'discount_value' => $q->discount_value,
                'payment_terms' => $q->payment_terms, 'delivery_terms' => 'شروط مقترحة للمراجعة: توريد إلى '.$branch->name.' بعد اعتماد المواصفات والتوافر.',
                'notes' => 'أمر بيع مباشر تجريبي غير معتمد. لا يرتبط بتحويل عرض سعر، ولا يحجز مخزونًا أو يصدر فاتورة أو ينشئ أثرًا ماليًا.',
                'calculation' => $q->calculation, 'created_by' => $this->actor, 'updated_by' => $this->actor,
            ]);
            $order->lines()->createMany($q->lines->map(fn ($l) => $l->only(['position', 'product_id', 'unit_id', 'product_snapshot', 'description', 'quantity', 'suggested_price', 'requested_price', 'price_source', 'discount_mode', 'discount_value', 'override_reason']))->all());
            $this->audit('Sales', 'sales_order', $order->id, ['draft_key' => $key, 'reference' => $order->customer_reference, 'status' => 'draft', 'branch_id' => (int) $branch->id]);
            $this->progress('مسودات أوامر البيع المباشرة التجريبية', $i);
        }
    }

    private function pdf(string $title, string $reference, string $notice): string
    {
        $escape = fn ($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $stream = "BT\n/F1 16 Tf\n40 760 Td\n(".$escape($title).") Tj\n0 -34 Td\n/F1 10 Tf\n(".$escape($reference).") Tj\n0 -28 Td\n(".$escape($notice).") Tj\n0 -28 Td\n(Synthetic local/testing fixture. No real transaction or certification.) Tj\nET\n";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream"];
        $pdf = "%PDF-1.4\n% Egyptian synthetic fixture\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }$xref = strlen($pdf);
        $pdf .= "xref\n0 ".count($offsets)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".count($offsets)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function illustration(int $family, int $i): string
    {
        $im = imagecreatetruecolor(360, 260);
        $bg = imagecolorallocate($im, 246, 249, 252);
        $ink = imagecolorallocate($im, 37, 53, 75);
        $teal = imagecolorallocate($im, 7, 127, 112);
        $gray = imagecolorallocate($im, 202, 211, 223);
        $white = imagecolorallocate($im, 252, 253, 255);
        $copper = imagecolorallocate($im, 181, 105, 48);
        imagefill($im, 0, 0, $bg);
        if (in_array($family, [4, 5], true)) {
            for ($n = 0; $n < 8; $n++) {
                imageellipse($im, 180, 125, 230 - $n * 17, 160 - $n * 11, $ink);
            }imagefilledellipse($im, 275, 145, 40, 40, $copper);
            imagefilledellipse($im, 275, 145, 24, 24, $gray);
        } elseif ($family === 8) {
            imagefilledrectangle($im, 65, 55, 290, 190, $ink);
            imagefilledrectangle($im, 80, 70, 275, 175, $gray);
            for ($x = 95; $x < 270; $x += 25) {
                for ($y = 85; $y < 175; $y += 25) {
                    imagefilledrectangle($im, $x, $y, $x + 10, $y + 10, $white);
                }
            }
        } elseif ($family === 9) {
            imagefilledrectangle($im, 95, 30, 265, 215, $gray);
            imagefilledrectangle($im, 108, 45, 252, 200, $white);
            for ($x = 122; $x < 250; $x += 31) {
                imagefilledrectangle($im, $x, 90, $x + 21, 151, $gray);
                imagefilledrectangle($im, $x + 4, 105, $x + 17, 130, $teal);
            }
        } elseif ($family === 15) {
            imagefilledellipse($im, 180, 130, 210, 130, $teal);
            imagefilledrectangle($im, 110, 75, 220, 185, $teal);
            for ($x = 115; $x < 220; $x += 14) {
                imageline($im, $x, 80, $x, 180, $ink);
            }imagefilledrectangle($im, 225, 115, 300, 140, $gray);
        } elseif (in_array($family, [11, 12], true)) {
            for ($n = 0; $n < 4; $n++) {
                imagefilledrectangle($im, 60, 65 + $n * 30, 290, 80 + $n * 30, $gray);
                imageline($im, 60, 65 + $n * 30, 290, 65 + $n * 30, $ink);
            }
        } else {
            $poles = in_array($family, [1, 2, 6], true) ? 3 : ($family === 3 ? 2 : 1);
            $start = 180 - intdiv($poles * 60, 2);
            for ($n = 0; $n < $poles; $n++) {
                $x = $start + $n * 60;
                imagefilledrectangle($im, $x, 40, $x + 52, 205, $gray);
                imagefilledrectangle($im, $x + 5, 48, $x + 47, 195, $white);
                imagefilledrectangle($im, $x + 12, 95, $x + 41, 140, $teal);
                imagefilledellipse($im, $x + 26, 65, 13, 13, $ink);
                imagefilledellipse($im, $x + 26, 178, 13, 13, $ink);
            }
        }
        imagestring($im, 3, 15, 230, 'EGYPT DEMO '.str_pad((string) $i, 6, '0', STR_PAD_LEFT), $ink);
        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);
        if (! is_string($data)) {
            throw new \RuntimeException('Unable to create illustration.');
        }

        return $data;
    }
}

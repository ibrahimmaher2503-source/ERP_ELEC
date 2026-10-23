<?php

namespace Database\Seeders;

use App\Modules\System\Application\RecordAudit;
use Database\Factories\BranchFactory;
use Database\Factories\BrandFactory;
use Database\Factories\CategoryFactory;
use Database\Factories\CompanyFactory;
use Database\Factories\ProductConversionFactory;
use Database\Factories\ProductFactory;
use Database\Factories\ProductMediaFactory;
use Database\Factories\UnitFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RealisticDemoSeeder extends Seeder
{
    private array $createdFiles = [];

    public function run(): void
    {
        $this->assertSafeTarget();
        $this->createdFiles = [];

        try {
            DB::transaction(function (): void {
                DB::select('SELECT pg_advisory_xact_lock(20401)');
                [$actorId, $organizationEvents] = $this->seedOrganization();
                $productEvents = $this->seedProducts($actorId);

                foreach ([...$organizationEvents, ...$productEvents] as $event) {
                    app(RecordAudit::class)->execute(null, $event['type'], $event['id'], 'demo.created', null, $event['after'], 'succeeded', $event['module']);
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($this->createdFiles);
            throw $exception;
        }
    }

    private function assertSafeTarget(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Demo seeding is restricted to local/testing PostgreSQL databases.');
        }

        if (app()->environment('testing') && DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
            throw new RuntimeException('Testing demo seeding requires the exact ERP test database.');
        }
    }

    private function seedOrganization(): array
    {
        $events = [];
        $company = DB::table('companies')->where('id', 1)->first();
        if (! $company) {
            $row = CompanyFactory::new()->raw([
                'id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('companies')->insert($row);
            $events[] = ['module' => 'Organization', 'type' => 'company', 'id' => 1, 'after' => ['code' => $row['code'], 'name' => $row['name'], 'currency' => 'EGP']];
        }

        $branches = [
            ['DEMO-BR-CAI', 'فرع القاهرة - العتبة', 'شارع عبدالعزيز، العتبة، القاهرة'],
            ['DEMO-BR-ALX', 'فرع الإسكندرية - محرم بك', 'شارع الرصافة، محرم بك، الإسكندرية'],
            ['DEMO-BR-GIZ', 'فرع الجيزة - فيصل', 'شارع الملك فيصل، الجيزة'],
            ['DEMO-BR-MAN', 'فرع المنصورة', 'شارع الجمهورية، المنصورة'],
            ['DEMO-BR-ASW', 'فرع أسيوط', 'شارع الهلالي، أسيوط'],
        ];
        $branchIds = [];
        foreach ($branches as [$code, $name, $address]) {
            $existing = DB::table('branches')->where('code', $code)->first();
            if ($existing) {
                $branchIds[$code] = (int) $existing->id;

                continue;
            }

            $row = BranchFactory::new()->raw(['code' => $code, 'name' => $name, 'address' => $address, 'created_at' => now(), 'updated_at' => now()]);
            $id = (int) DB::table('branches')->insertGetId($row);
            $branchIds[$code] = $id;
            $events[] = ['module' => 'Organization', 'type' => 'branch', 'id' => $id, 'after' => ['code' => $code, 'name' => $name, 'company_id' => 1, 'address' => $address]];
        }

        $users = [
            ['أحمد محمود عبدالله', 'male', 'DEMO-BR-CAI'], ['مصطفى السيد حسن', 'male', 'DEMO-BR-CAI'], ['محمد إبراهيم علي', 'male', 'DEMO-BR-CAI'],
            ['فاطمة أحمد محمد', 'female', 'DEMO-BR-ALX'], ['منى خالد يوسف', 'female', 'DEMO-BR-ALX'], ['ياسمين محمود علي', 'female', 'DEMO-BR-ALX'],
            ['عمر حسن عبدالرحمن', 'male', 'DEMO-BR-GIZ'], ['خالد سمير فوزي', 'male', 'DEMO-BR-GIZ'],
            ['نورهان محمد صالح', 'female', 'DEMO-BR-MAN'], ['آية عبدالفتاح حسن', 'female', 'DEMO-BR-MAN'],
            ['يوسف عادل إبراهيم', 'male', 'DEMO-BR-ASW'], ['سلمى إبراهيم محمود', 'female', 'DEMO-BR-ASW'],
        ];
        $actorId = null;
        foreach ($users as $index => [$name, $gender, $branchCode]) {
            $email = sprintf('demo.user%02d@example.test', $index + 1);
            $existing = DB::table('users')->whereRaw('lower(email) = ?', [$email])->first();
            if ($existing) {
                $id = (int) $existing->id;
            } else {
                if (! DB::table('branches')->where('id', $branchIds[$branchCode])->where('active', true)->exists()) {
                    throw new RuntimeException("Cannot assign a new demo user to inactive branch {$branchCode}.");
                }
                $row = UserFactory::new()->disabledDemo()->raw([
                    'name' => $name,
                    'email' => $email,
                    'gender' => $gender,
                    'branch_id' => $branchIds[$branchCode],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $id = (int) DB::table('users')->insertGetId($row);
                $events[] = ['module' => 'Organization', 'type' => 'user', 'id' => $id, 'after' => ['name' => $name, 'email' => $email, 'gender' => $gender, 'branch_id' => $branchIds[$branchCode], 'active' => false]];
            }
            $actorId ??= $id;
        }

        return [$actorId, $events];
    }

    private function seedProducts(int $actorId): array
    {
        $events = [];
        $categories = [
            ['DEMO-CAT-01', 'الكابلات والأسلاك', null],
            ['DEMO-CAT-02', 'كابلات القوى النحاسية', 'DEMO-CAT-01'],
            ['DEMO-CAT-03', 'كابلات التحكم', 'DEMO-CAT-01'],
            ['DEMO-CAT-04', 'القواطع والحماية', null],
            ['DEMO-CAT-05', 'قواطع مصغرة MCB', 'DEMO-CAT-04'],
            ['DEMO-CAT-06', 'قواطع مصبوبة MCCB', 'DEMO-CAT-04'],
            ['DEMO-CAT-07', 'اللوحات الكهربائية', null],
            ['DEMO-CAT-08', 'ملحقات التركيبات والتحكم', null],
        ];
        $categoryIds = [];
        foreach ($categories as [$code, $name, $parentCode]) {
            $parentId = $parentCode ? ($categoryIds[$parentCode] ?? DB::table('product_categories')->where('code', $parentCode)->value('id')) : null;
            $existing = DB::table('product_categories')->where('code', $code)->first();
            if ($existing) {
                $id = (int) $existing->id;
            } else {
                $row = CategoryFactory::new()->raw(['code' => $code, 'name' => $name, 'parent_id' => $parentId, 'created_at' => now(), 'updated_at' => now()]);
                $id = (int) DB::table('product_categories')->insertGetId($row);
                $events[] = ['module' => 'Products', 'type' => 'category', 'id' => $id, 'after' => ['code' => $code, 'name' => $name, 'parent_id' => $parentId]];
            }
            $categoryIds[$code] = $id;
        }

        $brands = [
            ['DEMO-BRAND-SCHNEIDER', 'Schneider Electric'], ['DEMO-BRAND-ABB', 'ABB'], ['DEMO-BRAND-SIEMENS', 'Siemens'],
            ['DEMO-BRAND-LEGRAND', 'Legrand'], ['DEMO-BRAND-EATON', 'Eaton'], ['DEMO-BRAND-PHOENIX', 'Phoenix Contact'],
        ];
        $brandIds = [];
        foreach ($brands as [$code, $name]) {
            $existing = DB::table('brands')->where('code', $code)->first()
                ?? DB::table('brands')->whereRaw('lower(btrim(name)) = lower(?)', [$name])->first();
            if ($existing) {
                $id = (int) $existing->id;
            } else {
                $row = BrandFactory::new()->raw(['code' => $code, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                $id = (int) DB::table('brands')->insertGetId($row);
                $events[] = ['module' => 'Products', 'type' => 'brand', 'id' => $id, 'after' => ['code' => $code, 'name' => $name]];
            }
            $brandIds[$code] = $id;
        }

        $units = [
            ['DEMO-UOM-PIECE', 'قطعة', 'count'], ['DEMO-UOM-CARTON', 'كرتونة', 'count'],
            ['DEMO-UOM-METER', 'متر', 'length'], ['DEMO-UOM-MM', 'مليمتر', 'length'],
            ['DEMO-UOM-KG', 'كيلوجرام', 'mass'], ['DEMO-UOM-G', 'جرام', 'mass'],
            ['DEMO-UOM-LITER', 'لتر', 'volume'], ['DEMO-UOM-HOUR', 'ساعة', 'time'],
        ];
        $unitIds = [];
        foreach ($units as [$code, $name, $dimension]) {
            $existing = DB::table('units_of_measure')->where('code', $code)->first();
            if ($existing) {
                $id = (int) $existing->id;
            } else {
                $row = UnitFactory::new()->raw(['code' => $code, 'name' => $name, 'dimension' => $dimension, 'created_at' => now(), 'updated_at' => now()]);
                $id = (int) DB::table('units_of_measure')->insertGetId($row);
                $events[] = ['module' => 'Products', 'type' => 'unit', 'id' => $id, 'after' => ['code' => $code, 'name' => $name, 'dimension' => $dimension]];
            }
            $unitIds[$code] = $id;
        }

        $products = $this->productRows();
        foreach ($products as $index => $item) {
            $number = $index + 1;
            $sku = sprintf('DEMO-PROD-%03d', $number);
            $barcode = sprintf('DEMO-BAR-%04d', $number);
            $categoryId = $categoryIds[$item['category']];
            $brandId = $brandIds[$item['brand']];
            $baseUnitId = $unitIds[$item['unit']];
            $purchaseUnitId = $item['carton'] ? $unitIds['DEMO-UOM-CARTON'] : null;
            $existing = DB::table('products')->where('sku', $sku)->first();
            if ($existing) {
                $productId = (int) $existing->id;
            } else {
                $row = ProductFactory::new()->raw([
                    'sku' => $sku,
                    'name' => $item['name'],
                    'name_en' => $item['name_en'],
                    'short_description' => 'صنف كهربائي تجريبي بمواصفات كتالوج واضحة.',
                    'description' => 'سجل توضيحي للاستخدام المحلي؛ لا يتضمن سعرًا أو رصيدًا أو معاملة ضريبية.',
                    'kind' => $item['kind'],
                    'tracking' => $item['tracking'],
                    'category_id' => $categoryId,
                    'brand_id' => $brandId,
                    'model' => $item['model'],
                    'base_unit_id' => $baseUnitId,
                    'barcode' => $barcode,
                    'purchase_unit_id' => $purchaseUnitId,
                    'sales_unit_id' => $purchaseUnitId ? $unitIds['DEMO-UOM-PIECE'] : null,
                    'active' => true,
                    'version' => 1,
                    'internal_notes' => null,
                    'attributes' => $item['attributes'],
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $row['attributes'] = json_encode($row['attributes'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $productId = (int) DB::table('products')->insertGetId($row);
                $events[] = ['module' => 'Products', 'type' => 'product', 'id' => $productId, 'after' => ['sku' => $sku, 'name' => $item['name'], 'category_id' => $categoryId, 'brand_id' => $brandId, 'base_unit_id' => $baseUnitId, 'kind' => $item['kind'], 'tracking' => $item['tracking'], 'barcode' => $barcode, 'model' => $item['model']]];
            }

            if ($existing) {
                continue;
            }

            if ($item['carton']) {
                $conversion = DB::table('product_unit_conversions')->where('product_id', $productId)->where('unit_id', $unitIds['DEMO-UOM-CARTON'])->first();
                $canCreateConversion = (int) DB::table('products')->where('id', $productId)->value('base_unit_id') === $unitIds['DEMO-UOM-PIECE']
                    && DB::table('units_of_measure')->where('id', $unitIds['DEMO-UOM-CARTON'])->where('dimension', 'count')->exists()
                    && DB::table('units_of_measure')->where('id', $unitIds['DEMO-UOM-PIECE'])->where('dimension', 'count')->exists();
                if (! $conversion && $canCreateConversion) {
                    $row = ProductConversionFactory::new()->raw(['product_id' => $productId, 'unit_id' => $unitIds['DEMO-UOM-CARTON'], 'factor' => '10.00000000', 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('product_unit_conversions')->insert($row);
                }
            }

            if (! DB::table('product_media')->where('product_id', $productId)->exists()) {
                $row = ProductMediaFactory::new()->raw(['product_id' => $productId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
                $this->createdFiles[] = $row['path'];
                $mediaId = (int) DB::table('product_media')->insertGetId($row);
                $events[] = ['module' => 'Products', 'type' => 'product', 'id' => $productId, 'after' => ['sku' => $sku, 'media_id' => $mediaId, 'media_name' => $row['name'], 'mime' => $row['mime']]];
            }
        }

        return $events;
    }

    private function productRows(): array
    {
        $brands = ['DEMO-BRAND-SCHNEIDER', 'DEMO-BRAND-ABB', 'DEMO-BRAND-SIEMENS', 'DEMO-BRAND-LEGRAND', 'DEMO-BRAND-EATON', 'DEMO-BRAND-PHOENIX'];
        $rows = [];
        foreach ([['1.5', 'أزرق'], ['2.5', 'بني'], ['4', 'أسود'], ['6', 'أحمر'], ['10', 'أصفر']] as $index => [$size, $color]) {
            $rows[] = ['name' => "كابل نحاس مفرد {$size} مم² {$color}", 'name_en' => "Single core copper cable {$size} mm2", 'category' => 'DEMO-CAT-02', 'brand' => $brands[$index], 'unit' => 'DEMO-UOM-METER', 'model' => "PVC-CU-{$size}MM2", 'kind' => 'bulk', 'tracking' => 'quantity', 'carton' => false, 'attributes' => [['name' => 'مقطع الموصل', 'value' => "{$size} مم²", 'unit_id' => null], ['name' => 'لون العازل', 'value' => $color, 'unit_id' => null]]];
        }
        foreach ([['1', '16', true], ['1', '20', true], ['3', '32', true], ['2', '40', false], ['3', '63', false]] as $index => [$poles, $amps, $carton]) {
            $rows[] = ['name' => "قاطع مصغر MCB {$poles}P {$amps}A", 'name_en' => "Miniature circuit breaker {$poles}P {$amps}A", 'category' => 'DEMO-CAT-05', 'brand' => $brands[$index], 'unit' => 'DEMO-UOM-PIECE', 'model' => "MCB-{$poles}P-{$amps}A-C", 'kind' => 'bulk', 'tracking' => 'quantity', 'carton' => $carton, 'attributes' => [['name' => 'عدد الأقطاب', 'value' => $poles, 'unit_id' => null], ['name' => 'التيار المقنن', 'value' => "{$amps} A", 'unit_id' => null], ['name' => 'قدرة القطع', 'value' => '6 kA', 'unit_id' => null]]];
        }
        foreach ([['100', '25', 'ABB'], ['160', '25', 'Schneider'], ['250', '36', 'Siemens'], ['400', '36', 'Eaton'], ['630', '50', 'Legrand']] as [$amps, $kA, $brand]) {
            $rows[] = ['name' => "قاطع مصبوب MCCB 3P {$amps}A", 'name_en' => "Moulded case circuit breaker 3P {$amps}A", 'category' => 'DEMO-CAT-06', 'brand' => 'DEMO-BRAND-'.strtoupper(match ($brand) {
                'ABB' => 'ABB', 'Schneider' => 'SCHNEIDER', 'Siemens' => 'SIEMENS', 'Eaton' => 'EATON', default => 'LEGRAND'
            }), 'unit' => 'DEMO-UOM-PIECE', 'model' => "MCCB-3P-{$amps}A", 'kind' => 'serialized_equipment', 'tracking' => 'serial', 'carton' => false, 'attributes' => [['name' => 'عدد الأقطاب', 'value' => '3', 'unit_id' => null], ['name' => 'التيار المقنن', 'value' => "{$amps} A", 'unit_id' => null], ['name' => 'قدرة القطع', 'value' => "{$kA} kA", 'unit_id' => null]]];
        }
        foreach ([['12', 'IP40'], ['18', 'IP40'], ['24', 'IP40'], ['12', 'IP65'], ['24', 'IP65']] as $index => [$ways, $ip]) {
            $rows[] = ['name' => "لوحة توزيع {$ways} خط {$ip}", 'name_en' => "Distribution board {$ways} way {$ip}", 'category' => 'DEMO-CAT-07', 'brand' => $brands[$index], 'unit' => 'DEMO-UOM-PIECE', 'model' => "DB-{$ways}-{$ip}", 'kind' => 'bulk', 'tracking' => 'quantity', 'carton' => false, 'attributes' => [['name' => 'عدد الخطوط', 'value' => $ways, 'unit_id' => null], ['name' => 'درجة الحماية', 'value' => $ip, 'unit_id' => null]]];
        }
        foreach ([['سكة تثبيت DIN بطول متر', 'DIN mounting rail 1 m', 'DEMO-UOM-METER', 'DIN-RAIL-1M'], ['ترنك PVC مقاس 40×60 مم', 'PVC trunking 40x60 mm', 'DEMO-UOM-METER', 'TRUNK-40X60'], ['بلوك طرفي 2.5 مم²', 'Terminal block 2.5 mm2', 'DEMO-UOM-PIECE', 'TB-2.5'], ['جلاند كابل M20', 'Cable gland M20', 'DEMO-UOM-PIECE', 'GLAND-M20'], ['حامل فيوز 10×38 مم', 'Fuse holder 10x38 mm', 'DEMO-UOM-PIECE', 'FUSE-HOLDER-10X38']] as $index => [$name, $nameEn, $unit, $model]) {
            $rows[] = ['name' => $name, 'name_en' => $nameEn, 'category' => 'DEMO-CAT-08', 'brand' => $brands[$index], 'unit' => $unit, 'model' => $model, 'kind' => 'bulk', 'tracking' => 'quantity', 'carton' => false, 'attributes' => [['name' => 'الاستخدام', 'value' => 'تركيب كهربائي', 'unit_id' => null]]];
        }
        foreach ([['كونتاكتور 25A ملف 220VAC', 'Contactor 25A coil 220VAC', 'DEMO-BRAND-SCHNEIDER', 'CONT-25A'], ['مرحل حماية حراري 9-13A', 'Thermal overload relay 9-13A', 'DEMO-BRAND-ABB', 'TOR-9-13A'], ['زر ضغط أخضر 22 مم', 'Green push button 22 mm', 'DEMO-BRAND-SIEMENS', 'PB-GREEN-22'], ['لمبة بيان LED حمراء 220VAC', 'Red LED indicator 220VAC', 'DEMO-BRAND-LEGRAND', 'LED-RED-220'], ['مانع زيادة جهد Type 2 ثلاثي', 'Surge protection device Type 2 3P', 'DEMO-BRAND-PHOENIX', 'SPD-T2-3P']] as [$name, $nameEn, $brand, $model]) {
            $rows[] = ['name' => $name, 'name_en' => $nameEn, 'category' => 'DEMO-CAT-08', 'brand' => $brand, 'unit' => 'DEMO-UOM-PIECE', 'model' => $model, 'kind' => 'serialized_equipment', 'tracking' => 'serial', 'carton' => false, 'attributes' => [['name' => 'الفئة', 'value' => 'مكوّن تحكم وحماية', 'unit_id' => null]]];
        }

        return $rows;
    }
}

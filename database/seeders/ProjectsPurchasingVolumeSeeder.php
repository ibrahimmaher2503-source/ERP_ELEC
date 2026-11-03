<?php

namespace Database\Seeders;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectDocument;
use App\Modules\Projects\Domain\ProjectDocumentRevision;
use App\Modules\Projects\Domain\ProjectMilestone;
use App\Modules\Projects\Domain\ProjectPhase;
use App\Modules\Projects\Domain\ProjectRequirement;
use App\Modules\Projects\Domain\ProjectTask;
use App\Modules\Purchasing\Domain\PurchaseOrder;
use App\Modules\Purchasing\Domain\PurchaseOrderLine;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRequisitionLine;
use App\Modules\System\Application\RecordAudit;
use Carbon\CarbonImmutable;
use Database\Factories\Support\EgyptianMarket;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/** Large, repeat-safe planning graphs; no approvals, execution or financial/stock postings. */
class ProjectsPurchasingVolumeSeeder extends Seeder
{
    public function run(): void
    {
        $expected = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== $expected) {
            throw new \RuntimeException('Volume demo requires the exact local/testing PostgreSQL database.');
        }

        $sizes = Validator::make([
            'projects' => env('ERP_DEMO_PROJECTS', 120),
            'requisitions' => env('ERP_DEMO_REQUISITIONS', 300),
            'purchase_orders' => env('ERP_DEMO_PURCHASE_ORDERS', 250),
        ], ['projects' => 'required|integer|between:1,5000', 'requisitions' => 'required|integer|between:1,5000', 'purchase_orders' => 'required|integer|between:1,5000'])->validate();

        $organization = app(OrganizationApi::class);
        $actor = (int) env('ERP_DEMO_ACTOR_ID', 2);
        $organization->authorize(new AuthorizeRequest($actor, 'M01.security_admin'));
        $scopes = [];
        foreach (['M07.project_view', 'M05.requisition_view', 'M05.order_view'] as $permission) {
            $scopes[] = array_column($organization->listBranchesForPermission(new AuthorizeRequest($actor, $permission)), 'id');
        }
        $branches = DB::table('branches')->whereIn('id', array_intersect(...$scopes))->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->get();
        $customers = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->get();
        $suppliers = DB::table('suppliers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->get();
        $products = DB::table('products')->where('sku', 'like', 'DEMO-EG%')->whereIn('kind', ['bulk', 'serialized_equipment'])->where('active', true)->orderBy('id')->get();
        $manager = DB::table('users')->where('active', false)->whereNull('email_verified_at')
            ->where('email', 'like', 'staff.%@egypt-market.example.test')->orderBy('id')->value('id');
        if ($branches->isEmpty() || $customers->isEmpty() || $suppliers->isEmpty() || $products->count() < 8 || ! $manager) {
            throw new \RuntimeException('Run Egyptian market masters first; existing demo branch scopes and disabled demo staff are required.');
        }

        $types = ['توريد فقط', 'تركيب فقط', 'توريد وتركيب', 'صيانة كهربائية', 'إحلال وتجديد', 'إنارة داخلية وخارجية', 'لوحات توزيع وتحكم', 'شبكات كابلات', 'أنظمة تيار خفيف', 'طاقة شمسية'];
        $files = [];
        $created = ['projects' => 0, 'requisitions' => 0, 'purchase_orders' => 0];
        try {
            DB::transaction(function () use ($sizes, $branches, $customers, $suppliers, $products, $manager, $types, &$files, &$created) {
                foreach ([10401, 50501, 70701] as $lock) {
                    DB::select('select pg_advisory_xact_lock(?)', [$lock]);
                }
                $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
                for ($i = 1; $i <= (int) $sizes['projects']; $i++) {
                    $code = 'DEMO-EG-VOL-PRJ-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                    if (Project::where('code', $code)->exists()) {
                        continue;
                    }
                    $type = $types[($i - 1) % count($types)];
                    $location = EgyptianMarket::location($i);
                    $branch = $branches[($i - 1) % $branches->count()];
                    $customer = $customers[($i * 7 - 1) % $customers->count()];
                    $start = $today->addDays($i % 60);
                    $project = Project::factory()->create([
                        'code' => $code, 'creation_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, $code)->toString(),
                        'name' => $type.' — '.['مصنع', 'مخزن', 'مبنى إداري', 'مجمع تجاري'][intdiv($i - 1, 10) % 4].' تجريبي في '.$location['city'].' — '.$i,
                        'project_type' => $type, 'priority' => ['normal', 'high', 'low', 'urgent'][$i % 4],
                        'branch_id' => $branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
                        'manager_id' => $manager, 'created_by' => $manager,
                        'start_date' => $start->toDateString(), 'end_date' => $start->addDays(89)->toDateString(),
                        'description' => 'DEMO-EG — مسودة تخطيط '.$type.' في '.$location['line'].'؛ لم تعتمد أو تنفذ أعمال أو تكاليف.',
                    ]);
                    foreach (['المعاينة وحصر الاحتياجات', 'إعداد خطة التوريد والأعمال', 'مراجعة المستندات والتنسيق'] as $j => $title) {
                        $phaseStart = $start->addDays($j * 30);
                        $phase = ProjectPhase::factory()->create(['project_id' => $project->id, 'title' => $title,
                            'start_date' => $phaseStart->toDateString(), 'end_date' => $phaseStart->addDays(29)->toDateString()]);
                        foreach (['إعداد قائمة المتطلبات', 'مراجعة المواصفات المقترحة', 'تنسيق خطة الموارد'] as $task) {
                            ProjectTask::factory()->create(['project_id' => $project->id, 'phase_id' => $phase->id,
                                'title' => $task.' — '.$title, 'priority' => $project->priority]);
                        }
                        ProjectMilestone::factory()->create(['project_id' => $project->id, 'responsible_id' => $manager,
                            'title' => 'مراجعة خطة '.$title, 'target_date' => $phase->end_date]);
                    }
                    for ($j = 0; $j < 6; $j++) {
                        $product = $products[(($i - 1) * 6 + $j) % $products->count()];
                        ProjectRequirement::factory()->create(['project_id' => $project->id, 'product_id' => $product->id,
                            'unit_id' => $product->base_unit_id, 'sku' => $product->sku, 'product_name' => $product->name,
                            'quantity' => (10 + ($i + $j * 11) % 190).'.00000000']);
                    }
                    foreach (['وصف نطاق الأعمال المقترح', 'حصر المواد المبدئي'] as $j => $title) {
                        $document = ProjectDocument::factory()->create(['project_id' => $project->id, 'number' => $code.'-DOC-'.($j + 1),
                            'title' => $title.' — '.$type, 'classification' => 'مرفق تخطيطي تجريبي']);
                        $revision = ProjectDocumentRevision::factory()->make(['document_id' => $document->id]);
                        $files[] = $revision->path;
                        if (! Storage::disk('local')->exists($revision->path)) {
                            throw new \RuntimeException('Unable to write private project fixture.');
                        }
                        $revision->save();
                    }
                    $this->audit('Projects', 'project', $project->id, $code);
                    $created['projects']++;
                }

                for ($i = 1; $i <= (int) $sizes['requisitions']; $i++) {
                    $identity = 'DEMO-EG-VOL-REQ-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                    $key = Uuid::uuid5(Uuid::NAMESPACE_URL, $identity)->toString();
                    if (PurchaseRequisition::where('creation_key', $key)->exists()) {
                        continue;
                    }
                    $branch = $branches[($i - 1) % $branches->count()];
                    $date = $today->subDays($i % 60);
                    $request = PurchaseRequisition::factory()->create(['creation_key' => $key, 'branch_id' => $branch->id,
                        'created_by' => $manager, 'request_date' => $date->toDateString(), 'required_date' => $today->addDays(7 + $i % 30)->toDateString(),
                        'department' => ['تجهيز المواقع', 'التوريدات', 'الصيانة', 'المشروعات'][$i % 4],
                        'purpose' => $identity.' — احتياجات '.$types[($i - 1) % count($types)].' — '.$branch->name]);
                    for ($j = 0; $j < 4 + $i % 5; $j++) {
                        $product = $products[(($i - 1) * 8 + $j) % $products->count()];
                        PurchaseRequisitionLine::factory()->create(['requisition_id' => $request->id, 'product_id' => $product->id,
                            'unit_id' => $product->base_unit_id, 'sku' => $product->sku, 'product_name' => $product->name,
                            'quantity' => (5 + ($i * 7 + $j * 3) % 96).'.00000000', 'required_date' => $request->required_date]);
                    }
                    $this->audit('Purchasing', 'purchase_requisition', $request->id, $identity);
                    $created['requisitions']++;
                }

                for ($i = 1; $i <= (int) $sizes['purchase_orders']; $i++) {
                    $identity = 'DEMO-EG-VOL-PO-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                    $key = Uuid::uuid5(Uuid::NAMESPACE_URL, $identity)->toString();
                    if (PurchaseOrder::where('creation_key', $key)->exists()) {
                        continue;
                    }
                    $branch = $branches[($i - 1) % $branches->count()];
                    $supplier = $suppliers[($i * 7 - 1) % $suppliers->count()];
                    $lines = [];
                    $total = '0.00000000';
                    for ($j = 0; $j < 4 + $i % 5; $j++) {
                        $product = $products[(($i - 1) * 8 + $j) % $products->count()];
                        $quantity = (5 + ($i * 7 + $j * 3) % 96).'.00000000';
                        $price = (25 + ($i * 13 + $j * 29) % 2975).'.00000000';
                        $net = bcmul($quantity, $price, 8);
                        $total = bcadd($total, $net, 8);
                        $lines[] = ['product_id' => $product->id, 'unit_id' => $product->base_unit_id, 'sku' => $product->sku,
                            'product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => $price,
                            'discount_amount' => '0.00000000', 'net_amount' => $net];
                    }
                    $order = PurchaseOrder::factory()->create(['creation_key' => $key, 'branch_id' => $branch->id,
                        'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'created_by' => $manager,
                        'order_date' => $today->subDays($i % 60)->toDateString(), 'net_amount' => $total,
                        'notes' => $identity.' — مسودة توريد '.$types[($i - 1) % count($types)].'؛ أسعار توضيحية بلا اعتماد أو إصدار أو استلام.']);
                    foreach ($lines as $line) {
                        PurchaseOrderLine::factory()->create($line + ['order_id' => $order->id]);
                    }
                    $this->audit('Purchasing', 'purchase_order', $order->id, $identity);
                    $created['purchase_orders']++;
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($files);
            throw $e;
        }

        $this->command?->info('Volume demo added: '.json_encode($created, JSON_UNESCAPED_UNICODE));
    }

    private function audit(string $owner, string $type, int $id, string $identity): void
    {
        app(RecordAudit::class)->execute(null, $type, $id, 'demo.created', null,
            ['dataset' => 'egypt-market-volume-v1', 'identity' => $identity, 'status' => 'draft'], 'succeeded', $owner);
    }
}

<?php

namespace Database\Seeders;

use App\Modules\Purchasing\Domain\PurchaseOrder;
use App\Modules\Purchasing\Domain\PurchaseOrderLine;
use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRequisitionLine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class PurchasingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Local/testing only.');
        }
        DB::transaction(function () {
            $orderKey = (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'DEMO-EG-M05-DIRECT-ORDER-01');
            if (! PurchaseOrder::where('creation_key', $orderKey)->exists()) {
                $order = PurchaseOrder::factory()->create(['creation_key' => $orderKey]);
                PurchaseOrderLine::factory()->create(['order_id' => $order->id]);
            }
            foreach (['تجهيز لوحة توزيع تجريبية', 'تجهيز موقع إنارة تجريبي', 'استكمال مستلزمات تركيب تجريبية'] as $i => $purpose) {
                $key = (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'DEMO-EG-M05-REQ-'.($i + 1));
                if (PurchaseRequisition::where('creation_key', $key)->exists()) {
                    continue;
                }
                $doc = PurchaseRequisition::factory()->create(['creation_key' => $key, 'purpose' => $purpose]);
                PurchaseRequisitionLine::factory()->create(['requisition_id' => $doc->id]);
            }
        });
    }
}

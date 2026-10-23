<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseRequisition;
use App\Modules\Purchasing\Domain\PurchaseRfq;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PurchaseRfqFactory extends Factory
{
    protected $model = PurchaseRfq::class;

    public function definition(): array
    {
        $source = PurchaseRequisition::where('status', 'approved')->where('notes', 'like', 'DEMO-EG%')->orderBy('id')->first();
        if (! $source) {
            throw new \RuntimeException('An actually approved synthetic requisition is required. Factory never fabricates approvals.');
        }

        return ['creation_key' => (string) Str::uuid(), 'requisition_id' => $source->id, 'branch_id' => $source->branch_id, 'created_by' => $source->created_by, 'status' => 'draft', 'subject' => 'طلب عرض تجريبي لمستلزمات تركيب كهربائية', 'request_date' => now('Africa/Cairo')->toDateString(), 'requirements' => 'DEMO-EG — مسودة طلب عرض تجريبي فقط', 'version' => 1];
    }
}

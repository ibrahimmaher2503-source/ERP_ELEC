<?php

namespace Database\Factories;

use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Domain\Tender;
use App\Modules\Projects\Domain\TenderTechnical;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Prepared study only. No fabricated reviewer, approval, submission or monetary posting. */
class TenderTechnicalFactory extends Factory
{
    protected $model = TenderTechnical::class;

    public function definition(): array
    {
        $snapshot = ['requirements' => 'مسودة دراسة فنية تجريبية تنتظر إرفاق كراسة الجهة قبل الاعتماد.', 'documents' => [], 'lines' => [['position' => 1, 'description' => 'لوحة توزيع تجريبية وفق وصف الجهة', 'quantity' => '2', 'unit' => 'لوحة', 'specification' => 'تحدد المواصفات التفصيلية من الكراسة الأصلية.', 'compliance' => 'partial', 'deviation' => 'يلزم استكمال كراسة الشروط.', 'alternative' => '', 'catalog_reference' => '', 'product_id' => null, 'product' => null]]];

        return ['tender_id' => TenderFactory::new(), 'revision' => 1, 'snapshot' => $snapshot, 'sha256' => TenderActions::hash($snapshot), 'prepared_by' => fn (array $a) => Tender::findOrFail($a['tender_id'])->responsible_id];
    }
}

<?php

namespace Database\Factories;

use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Domain\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenderFactory extends Factory
{
    protected $model = Tender::class;

    public function definition(): array
    {
        $tag = Str::upper(Str::random(12));
        $ref = 'FACTORY-TN-'.$tag;
        $deadlines = array_fill_keys(TenderActions::DATES, null);
        $deadlines['final_at'] = now()->addDays(30)->utc()->toIso8601String();
        $data = ['external_reference' => $ref, 'issuer' => 'جهة تجريبية للمناقصات', 'title' => 'توريد لوحات توزيع كهربائية تجريبية', 'description' => 'مسودة بيانات صناعية للتطوير، دون تقديم أو ترسية.', 'category' => 'supply', 'customer_id' => null, 'customer_name' => null, 'branch_id' => BranchFactory::new(), 'responsible_id' => UserFactory::new()->state(['active' => false, 'email_verified_at' => null]), 'published_on' => now('Africa/Cairo')->toDateString(), 'deadlines' => $deadlines, 'requirements' => 'دراسة فنية وأسعار معتمدة قبل المشاركة.'];

        return $data + ['number' => $ref, 'creation_key' => (string) Str::uuid(), 'creation_hash' => TenderActions::hash(['factory' => $tag]), 'identity_hash' => TenderActions::hash([$data['issuer'], $ref]), 'created_by' => fn (array $attributes) => $attributes['responsible_id'], 'status' => 'identified', 'version' => 1];
    }
}

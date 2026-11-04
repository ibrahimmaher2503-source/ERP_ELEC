<?php

namespace Database\Factories;

use App\Modules\WorkshopRepairs\Domain\Workshop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkshopFactory extends Factory
{
    protected $model = Workshop::class;

    public function definition(): array
    {
        $branchId = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->value('id');
        $supervisorId = DB::table('users')->where('active', true)->whereNotNull('email_verified_at')->value('id');
        $creatorId = DB::table('users')->where('active', false)->value('id');
        if (! $branchId || ! $supervisorId || ! $creatorId) {
            throw new \RuntimeException('WorkshopFactory requires a demo branch, an active verified supervisor and a disabled demo creator.');
        }

        return [
            'code' => 'DEMO-EG-WS-'.Str::upper(Str::random(10)),
            'name' => 'ورشة اختبار قواطع كهربائية',
            'specialty' => 'فحص وإصلاح القواطع منخفضة الجهد',
            'branch_id' => $branchId,
            'supervisor_id' => $supervisorId,
            'warehouse_id' => null,
            'capacity' => 4,
            'schedule' => [['day' => 0, 'start' => '08:00', 'end' => '16:00']],
            'active' => true,
            'version' => 1,
            'created_by' => $creatorId,
        ];
    }
}

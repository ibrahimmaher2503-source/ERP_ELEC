<?php

namespace Database\Seeders;

use App\Modules\Projects\Application\TenderActions;
use App\Modules\Projects\Domain\Tender;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class TendersDemoSeeder extends Seeder
{
    public function run(): void
    {
        $database = app()->environment('testing') ? 'erp_elec_new_test' : 'erp_elec_new';
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== $database) {
            throw new \RuntimeException('Tender demo records require explicit local/testing PostgreSQL.');
        }
        DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(70701)');
            $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->first();
            $user = DB::table('users')->where('active', false)->whereNull('email_verified_at')->where(fn ($q) => $q->where('email', 'like', 'demo.user%@example.test')->orWhere('email', 'like', 'staff.%@egypt-market.example.test'))->orderBy('id')->first();
            if (! $branch || ! $user) {
                throw new \RuntimeException('Egyptian demo branch and disabled synthetic user are prerequisites.');
            }
            foreach (['supply' => 'توريد لوحات كهربائية، مناقصة تجريبية', 'project' => 'تركيب شبكة إنارة، مناقصة تجريبية', 'service' => 'صيانة تجهيزات كهربائية، مناقصة تجريبية'] as $category => $title) {
                $ref = 'DEMO-EG-TN-'.strtoupper($category);
                if (Tender::where('number', $ref)->exists()) {
                    continue;
                }
                $key = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, $ref);
                $t = Tender::factory()->create(['number' => $ref, 'creation_key' => $key, 'creation_hash' => TenderActions::hash(['demo' => $ref]), 'identity_hash' => TenderActions::hash(['DEMO-EG', $ref]), 'external_reference' => $ref, 'issuer' => 'جهة مصرية تجريبية، ليست عطاءً فعلياً', 'title' => $title, 'category' => $category, 'branch_id' => $branch->id, 'responsible_id' => $user->id, 'created_by' => $user->id]);
                app(RecordAudit::class)->execute(null, 'tender_demo', $t->id, 'demo.created', null, ['identity' => $ref], 'succeeded', 'Projects');
            }
        });
    }
}

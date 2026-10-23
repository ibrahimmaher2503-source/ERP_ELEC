<?php

namespace Database\Seeders;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectDocument;
use App\Modules\Projects\Domain\ProjectDocumentRevision;
use App\Modules\Projects\Domain\ProjectPhase;
use App\Modules\Projects\Domain\ProjectRequirement;
use App\Modules\Projects\Domain\ProjectTask;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class ProjectsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Local/testing only.');
        }
        DB::transaction(function () {
            foreach (['تطوير إنارة مخزن تجريبي بالقاهرة', 'تجهيز لوحة توزيع تجريبية بالإسكندرية', 'تخطيط شبكة كهرباء تجريبية بالجيزة'] as $i => $name) {
                $code = 'DEMO-EG-PRJ-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
                $p = Project::where('code', $code)->first();
                $p ??= Project::factory()->create(['code' => $code, 'name' => $name, 'creation_key' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $code)]);
                $phase = ProjectPhase::where('project_id', $p->id)->first() ?? ProjectPhase::factory()->create(['project_id' => $p->id]);
                $doc = ProjectDocument::where('project_id', $p->id)->where('number', 'DEMO-EG-DOC-'.($i + 1))->first() ?? ProjectDocument::factory()->create(['project_id' => $p->id, 'number' => 'DEMO-EG-DOC-'.($i + 1)]);
                if (! ProjectDocumentRevision::where('document_id', $doc->id)->exists()) {
                    ProjectDocumentRevision::factory()->create(['document_id' => $doc->id]);
                }
                if (! ProjectRequirement::where('project_id', $p->id)->exists() && DB::table('products')->where('sku', 'like', 'DEMO-EG%')->where('active', true)->exists()) {
                    ProjectRequirement::factory()->create(['project_id' => $p->id]);
                }

                if (! ProjectTask::where('project_id', $p->id)->exists()) {
                    ProjectTask::create(['project_id' => $p->id, 'phase_id' => $phase->id, 'title' => 'مراجعة احتياجات الأعمال الكهربائية', 'assigned_to' => $p->manager_id, 'start_date' => $phase->start_date, 'end_date' => $phase->end_date, 'status' => 'pending', 'notes' => 'DEMO-EG — مهمة اصطناعية غير منفذة']);
                }
            }
        });
    }
}

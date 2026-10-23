<?php

namespace Database\Factories;

use App\Modules\Projects\Domain\Project;
use App\Modules\Projects\Domain\ProjectDocument;
use App\Modules\Projects\Domain\ProjectDocumentRevision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectDocumentRevisionFactory extends Factory
{
    protected $model = ProjectDocumentRevision::class;

    public function definition(): array
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6v9sAAAAASUVORK5CYII=');
        $path = 'projects/demo/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $bytes);

        return ['document_id' => ProjectDocument::factory(), 'revision' => 1, 'creation_key' => (string) Str::uuid(), 'status' => 'draft', 'path' => $path, 'original_name' => 'DEMO-EG-plan.png', 'mime' => 'image/png', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'uploaded_by' => fn (array $a) => Project::findOrFail(ProjectDocument::findOrFail($a['document_id'])->project_id)->created_by, 'uploaded_at' => now(), 'notes' => 'صورة اختبار اصطناعية، ليست مخططًا هندسيًا'];
    }
}

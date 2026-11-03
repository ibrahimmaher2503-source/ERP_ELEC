<?php

namespace App\Modules\Projects\Application;

use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TenderBoq
{
    public function columns(): array
    {
        $headers = ['description' => 'وصف البند الخارجي', 'quantity' => 'الكمية', 'unit' => 'الوحدة', 'specification' => 'المواصفة', 'compliance' => 'compliant / partial / noncompliant', 'deviation' => 'الانحراف الفني', 'alternative' => 'البديل المقترح', 'catalog_reference' => 'مرجع الكتالوج'];
        $out = [];
        foreach ($headers as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'required' => in_array($key, ['description', 'quantity', 'unit', 'specification', 'compliance']), 'type' => 'text', 'allowed' => [], 'example' => '', 'description' => 'معاينة قبل الحفظ. لا ينشئ أصنافاً أو أسعاراً.'];
        }

        return $out;
    }

    public function template(int $actor, int $id): string
    {
        $t = DB::table('project_tenders')->find($id);
        abort_unless($t, 404);
        app(TenderAccess::class)->authorize($actor, $t->branch_id, 'technical_manage');

        return app(WorkbookCodec::class)->template('tender-boq', 'M07-tender-boq-v1', $this->columns(), []);
    }

    public function preview(int $actor, int $id, UploadedFile $file): array
    {
        $t = DB::table('project_tenders')->find($id);
        abort_unless($t, 404);
        app(TenderAccess::class)->authorize($actor, $t->branch_id, 'technical_manage');
        Validator::make(compact('file'), ['file' => 'required|file|extensions:xlsx|max:10240'])->validate();
        $rows = app(WorkbookCodec::class)->read($file->getRealPath(), 'tender-boq', 'M07-tender-boq-v1', $this->columns());

        return array_map(fn ($r) => $r['data'] + ['product_id' => null], $rows);
    }
}

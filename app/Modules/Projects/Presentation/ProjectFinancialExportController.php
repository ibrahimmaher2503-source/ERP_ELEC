<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\ProjectAccess;
use App\Modules\Projects\Application\ProjectFinancials;
use App\Modules\Projects\Domain\Project;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class ProjectFinancialExportController
{
    public function __invoke(int $project, string $format)
    {
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $p = Project::findOrFail($project);
        app(ProjectAccess::class)->authorize(auth()->id(), $p, 'export');
        $data = app(ProjectFinancials::class)->summary(auth()->id(), $project);
        $rows = [['metric' => 'الميزانية المعتمدة', 'value' => $data['approved_budget'] ?? 'غير معتمدة'],
            ['metric' => 'التزامات الشراء المفتوحة', 'value' => $data['purchasing']['open_commitments']],
            ['metric' => 'الاستلامات GRNI', 'value' => $data['purchasing']['received_value']],
            ['metric' => 'تكلفة معترف بها', 'value' => $data['accounting']['recognized_cost']],
            ['metric' => 'إيراد معترف به', 'value' => $data['accounting']['recognized_revenue']],
            ['metric' => 'هامش معترف به', 'value' => $data['recognized_margin'] ?? 'غير متاح؛ راجع التخصيص والمصالحة'],
            ['metric' => 'مستحق للموردين', 'value' => $data['subledger']['ap_due']],
            ['metric' => 'مستحق على العملاء', 'value' => $data['subledger']['ar_due']],
            ['metric' => 'مدفوعات مخصصة', 'value' => $data['subledger']['paid']],
            ['metric' => 'متحصلات مخصصة', 'value' => $data['subledger']['collected']],
            ['metric' => 'فرق FIFO وGL', 'value' => $data['fifo_gl_difference']]];
        if ($format === 'xlsx') {
            $path = app(WorkbookCodec::class)->write('project-financial', ['metric' => 'البيان', 'value' => 'EGP'], $rows);

            return response()->download($path, $p->code.'-financial.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate()->deleteFileAfterSend();
        }
        File::ensureDirectoryExists(storage_path('app/private/mpdf'), 0700);
        $pdf = new Mpdf(['mode' => 'utf-8', 'tempDir' => storage_path('app/private/mpdf'), 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $html = '<h1>'.e($p->name).'</h1><p>'.e($p->code).' · EGP · '.e(now('Africa/Cairo')->toDateTimeString()).'</p><table width="100%" border="1" cellspacing="0" cellpadding="6">';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($row['metric']).'</td><td>'.e($row['value']).'</td></tr>';
        }
        $pdf->WriteHTML($html.'</table><p>'.e($data['estimate_unavailable_reason']).'</p>');

        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$p->code.'-financial.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

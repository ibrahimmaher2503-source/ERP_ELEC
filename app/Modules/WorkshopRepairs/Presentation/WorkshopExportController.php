<?php

namespace App\Modules\WorkshopRepairs\Presentation;

use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use App\Modules\WorkshopRepairs\Application\WorkshopAccess;
use App\Modules\WorkshopRepairs\Application\WorkshopFinance;
use App\Modules\WorkshopRepairs\Application\WorkshopReports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class WorkshopExportController
{
    public function __invoke(string $format)
    {
        abort_unless(in_array($format, ['xlsx', 'pdf']), 404);
        $actor = auth()->id();
        $access = app(WorkshopAccess::class);
        $branches = $access->branches($actor, 'reports_view');
        abort_unless($branches, 403);
        $jobs = app(WorkshopReports::class)->query($actor, request()->only(['search', 'workshop_id', 'technician_id', 'customer_id', 'project_id', 'status', 'billing_mode', 'overdue']))->whereIn('branch_id', array_column($branches, 'id'))->orderBy('id')->limit(1000)->get();
        $rows = [];
        foreach ($jobs as $job) {
            $intake = DB::table('workshop_intakes')->find($job->intake_id);
            $workshop = DB::table('workshops')->find($job->workshop_id);
            $cost = $access->allows($actor, $job->branch_id, 'cost_view') ? app(WorkshopFinance::class)->costs($actor, $job->id) : null;
            $execution = app(WorkshopReports::class)->execution($actor, $job->id);
            $rows[] = ['number' => $job->number, 'customer' => $job->customer_name, 'equipment' => $intake->equipment_description, 'serial' => $intake->serial_number ?? 'غير محدد', 'workshop' => $workshop->name, 'technician' => (string) ($job->technician_id ?? ''), 'status' => $job->status, 'billing' => $job->billing_mode, 'supported_cost' => $cost['supported_total'] ?? 'غير مصرح', 'labor' => $cost ? ($cost['labor'] ?? 'المعدل غير متاح') : 'غير مصرح', 'minutes' => (string) $execution['minutes'], 'parts' => $execution['parts'], 'repeat_intakes' => $execution['repeat_intakes'] === null ? 'هوية غير محددة' : (string) $execution['repeat_intakes'], 'project' => (string) ($job->project_id ?? ''), 'field_case' => (string) ($intake->maintenance_work_order_id ?? '')];
        }
        $headers = ['number' => 'أمر الإصلاح', 'customer' => 'العميل', 'equipment' => 'المعدة', 'serial' => 'المسلسل', 'workshop' => 'الورشة', 'technician' => 'الفني', 'status' => 'الحالة', 'billing' => 'التغطية التجارية', 'supported_cost' => 'تكلفة فعلية مدعومة EGP', 'labor' => 'تكلفة العمل EGP', 'minutes' => 'دقائق العمل الفعلية', 'parts' => 'استهلاك القطع حسب الصنف', 'repeat_intakes' => 'عدد مرات استلام نفس المعدة ضمن النطاق', 'project' => 'مرجع المشروع', 'field_case' => 'مرجع الصيانة الميدانية'];
        if ($format === 'xlsx') {
            $path = app(WorkbookCodec::class)->write('workshop-repairs', $headers, $rows);

            return response()->download($path, 'workshop-repairs.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate()->deleteFileAfterSend();
        }
        File::ensureDirectoryExists(storage_path('app/private/mpdf'), 0700);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => storage_path('app/private/mpdf'), 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $html = '<h1>تقرير أوامر إصلاح الورش</h1><p>'.e(now('Africa/Cairo')->toDateTimeString()).' — حد التصدير 1000 سجل</p><table width="100%" border="1" cellspacing="0" cellpadding="4"><tr>';
        foreach ($headers as $h) {
            $html .= '<th>'.e($h).'</th>';
        }$html .= '</tr>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.e($value).'</td>';
            }$html .= '</tr>';
        }
        $pdf->WriteHTML($html.'</table>');

        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="workshop-repairs.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

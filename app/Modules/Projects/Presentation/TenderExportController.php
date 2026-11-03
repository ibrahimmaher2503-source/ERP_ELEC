<?php

namespace App\Modules\Projects\Presentation;

use App\Modules\Projects\Application\TenderAccess;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class TenderExportController
{
    public function __invoke(string $format)
    {
        abort_unless(in_array($format, ['xlsx', 'pdf']), 404);
        $actor = auth()->id();
        $a = app(TenderAccess::class);
        abort_unless($a->branches($actor, 'export'), 403);
        $q = $a->query($actor, 'report')->whereIn('branch_id', array_column($a->branches($actor, 'export'), 'id'));
        foreach (['status', 'category'] as $key) {
            if (request($key)) {
                $q->where($key, request($key));
            }
        }
        if (request('search')) {
            $q->where(fn ($q) => $q->where('title', 'ilike', '%'.mb_substr(request('search'), 0, 150).'%')->orWhere('number', 'ilike', '%'.mb_substr(request('search'), 0, 150).'%')->orWhere('issuer', 'ilike', '%'.mb_substr(request('search'), 0, 150).'%')->orWhere('customer_name', 'ilike', '%'.mb_substr(request('search'), 0, 150).'%'));
        }
        if (request()->boolean('upcoming')) {
            $q->whereNotIn('status', ['awarded', 'lost', 'cancelled', 'no_bid', 'withdrawn'])->whereRaw("(deadlines->>'final_at')::timestamptz BETWEEN ? AND ?", [now(), now()->addDays(14)]);
        }
        $rows = [];
        foreach ($q->orderBy('id')->limit(1000)->get() as $t) {
            $can = $a->allows($actor, $t->branch_id, 'financial_view');
            $sub = $t->submitted_id ? DB::table('project_tender_submissions')->find($t->submitted_id) : null;
            $f = $can && $sub ? DB::table('project_tender_financial')->find($sub->financial_id) : null;
            $result = $can ? DB::table('project_tender_results')->where('tender_id', $t->id)->orderByDesc('id')->first() : null;
            $guarantees = $can ? DB::table('project_tender_guarantees as g')->where('tender_id', $t->id)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('project_tender_guarantee_events as e')->whereColumn('e.guarantee_id', 'g.id'))->orderBy('expires_on')->get() : collect();
            $rows[] = ['number' => $t->number, 'reference' => $t->external_reference, 'title' => $t->title, 'issuer' => $t->issuer, 'customer' => $t->customer_name ?? 'جهة محتملة', 'category' => $t->category, 'status' => $t->status, 'deadline' => CarbonImmutable::parse(json_decode($t->deadlines, true)['final_at'])->tz('Africa/Cairo')->format('Y-m-d H:i P'), 'submitted_value' => $f?->total ?? 'غير متاح', 'award' => $result?->award_amount ?? 'غير متاح', 'converted' => (string) DB::table('project_tender_handoffs')->where('tender_id', $t->id)->count(), 'guarantees' => $can ? $guarantees->map(fn ($g) => $g->reference.' '.$g->amount.' EGP '.$g->expires_on)->implode(' / ') : 'غير مصرح'];
        }
        $headers = ['number' => 'الرقم', 'reference' => 'مرجع الجهة', 'title' => 'العنوان', 'issuer' => 'الجهة', 'customer' => 'العميل', 'category' => 'الفئة', 'status' => 'الحالة', 'deadline' => 'الموعد بالقاهرة', 'submitted_value' => 'العطاء المقدم EGP', 'award' => 'الترسية EGP', 'converted' => 'تحويلات', 'guarantees' => 'ضمانات قائمة وانتهاؤها، تتبع تشغيلي'];
        if ($format === 'xlsx') {
            $path = app(WorkbookCodec::class)->write('tenders', $headers, $rows);

            return response()->download($path, 'tenders.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate()->deleteFileAfterSend();
        }
        File::ensureDirectoryExists(storage_path('app/private/mpdf'), 0700);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => storage_path('app/private/mpdf'), 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $html = '<h1>تقارير المناقصات</h1><p>EGP · حد التصدير 1000 سجل · '.e(now('Africa/Cairo')->toDateTimeString()).'</p><table border="1" cellpadding="4"><tr>';
        foreach ($headers as $label) {
            $html .= '<th>'.e($label).'</th>';
        }$html .= '</tr>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                $html .= '<td>'.e($value).'</td>';
            }$html .= '</tr>';
        }$pdf->WriteHTML($html.'</table>');

        return response($pdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="tenders.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

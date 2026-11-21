<?php

namespace App\Modules\Accounting\Presentation;

use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\FinancialReports;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinancialReportExportController
{
    private const REPORT_TITLES = [
        'ledger' => 'دفتر الأستاذ العام',
        'trial-balance' => 'ميزان المراجعة',
        'income-statement' => 'قائمة الدخل (الأرباح والخسائر)',
        'balance-sheet' => 'الميزانية العمومية (المركز المالي)',
        'grni' => 'مطابقة استلامات بانتظار الفوترة (GRNI)',
        'ppv' => 'تقرير فروق أسعار الشراء (PPV)',
        'reconciliation' => 'تقرير مطابقة الحسابات الرقابية مع الدفاتر الفرعية',
    ];

    public function __invoke(Request $request, string $report, string $format): StreamedResponse|Response
    {
        abort_unless(in_array($report, array_keys(self::REPORT_TITLES), true)
            && in_array($format, ['xlsx', 'pdf'], true), 404);
        $input = $request->validate([
            'branch' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'account' => 'nullable|integer|min:1',
            'kind' => 'nullable|string|in:asset,liability,equity,income,expense',
            'search' => 'nullable|string|max:120',
            'status' => 'nullable|string|in:matched,mismatch',
            'source' => 'nullable|string|in:manual,system',
        ]);
        $actor = (int) $request->user()->id;
        $branch = isset($input['branch']) ? (int) $input['branch'] : null;
        $accountId = isset($input['account']) ? (int) $input['account'] : null;
        $kind = $input['kind'] ?? null;
        $search = isset($input['search']) ? trim((string) $input['search']) : null;
        $status = $input['status'] ?? '';
        $source = $input['source'] ?? null;

        $service = app(FinancialReports::class);
        $data = match ($report) {
            'ledger' => $service->ledger($actor, $branch, $input['from'] ?? null, $input['to'] ?? null, $accountId, $search, $source),
            'trial-balance' => $service->trialBalance($actor, $branch, $input['from'] ?? null, $input['to'] ?? null, $kind, $search),
            'income-statement' => $service->statement($actor, 'income', $branch, $input['from'] ?? null, $input['to'] ?? null, $kind, $search),
            'balance-sheet' => $service->statement($actor, 'balance', $branch, null, $input['to'] ?? null, $kind, $search),
            'ppv' => ['rows' => $service->ppv($actor, $branch, $input['from'] ?? null, $input['to'] ?? null)],
            'grni' => ['rows' => collect($service->reconciliations($actor, $branch, $input['to'] ?? null))->where('key', 'grni')->values()->all()],
            'reconciliation' => ['rows' => collect($service->reconciliations($actor, $branch, $input['to'] ?? null))
                ->when($status === 'matched', fn ($c) => $c->where('ok', true))
                ->when($status === 'mismatch', fn ($c) => $c->where('ok', false))
                ->values()->all()],
        };

        $branches = app(AccountingAccess::class)->branches($actor, 'report_view');
        $branchLabel = $branch === null
            ? 'إجمالي الشركة (كل الفروع المصرح بها)'
            : (collect($branches)->firstWhere('id', $branch)['name'] ?? 'فرع #'.$branch);
        $periodLabel = in_array($report, ['balance-sheet', 'grni', 'reconciliation'], true)
            ? 'حتى تاريخ: '.($input['to'] ?? now('Africa/Cairo')->toDateString())
            : 'الفترة: من '.($input['from'] ?? 'البداية').' إلى '.($input['to'] ?? now('Africa/Cairo')->toDateString());
        $reportTitle = self::REPORT_TITLES[$report] ?? $report;

        [$headers, $rows, $footerRow] = $this->tabular($report, $data);
        $name = $report.'-'.now('Africa/Cairo')->format('Ymd-His');

        if ($format === 'xlsx') {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setRightToLeft(true);
            $sheet->setTitle(mb_substr($reportTitle, 0, 31));

            $sheet->setCellValue('A1', $reportTitle);
            $sheet->setCellValue('A2', 'النطاق: '.$branchLabel.' | '.$periodLabel.' | العملة: ج.م');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
            $sheet->getStyle('A2')->getFont()->setSize(10);

            $tableRows = [$headers, ...$rows];
            if ($footerRow !== null) {
                $tableRows[] = $footerRow;
            }
            $sheet->fromArray($tableRows, null, 'A4');

            $lastCol = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getStyle("A4:{$lastCol}4")->getFont()->setBold(true);
            $sheet->getStyle("A4:{$lastCol}4")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFEFF6FF');

            if ($footerRow !== null) {
                $footerRowNum = 4 + count($rows) + 1;
                $sheet->getStyle("A{$footerRowNum}:{$lastCol}{$footerRowNum}")->getFont()->setBold(true);
                $sheet->getStyle("A{$footerRowNum}:{$lastCol}{$footerRowNum}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF8FAFC');
            }

            foreach (range(1, count($headers)) as $colIdx) {
                $colLetter = Coordinate::stringFromColumnIndex($colIdx);
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            }
            $sheet->getStyle('A4:'.$lastCol.(4 + count($tableRows) - 1))
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->freezePane('A5');

            return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name.'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }

        $temp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($temp, 0700);
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $temp, 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->SetDirectionality('rtl');
        $escape = fn ($value) => e((string) $value);
        $generatedAt = now('Africa/Cairo')->format('Y-m-d H:i');

        $html = '<div style="font-family: dejavusans, sans-serif; direction: rtl; text-align: right;">'
            .'<div style="border-bottom: 2px solid #1e3a8a; padding-bottom: 8px; margin-bottom: 12px;">'
            .'<h1 style="margin: 0 0 4px 0; font-size: 18px; color: #1e3a8a;">'.$escape($reportTitle).'</h1>'
            .'<div style="font-size: 11px; color: #475569;">'
            .'<strong>النطاق:</strong> '.$escape($branchLabel)
            .' &nbsp;|&nbsp; <strong>'.$escape($periodLabel).'</strong>'
            .' &nbsp;|&nbsp; <strong>العملة:</strong> جنيه مصري (ج.م)'
            .' &nbsp;|&nbsp; <strong>تاريخ الاستخراج:</strong> '.$escape($generatedAt)
            .'</div></div>'
            .'<table width="100%" border="1" cellspacing="0" cellpadding="6" style="border-collapse: collapse; font-size: 11px; border-color: #cbd5e1;">'
            .'<thead><tr style="background-color: #eff6ff; color: #1e3a8a;">';
        foreach ($headers as $header) {
            $html .= '<th style="text-align: right; font-weight: bold; border: 1px solid #cbd5e1;">'.$escape($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $idx => $row) {
            $bg = $idx % 2 === 1 ? '#f8fafc' : '#ffffff';
            $html .= '<tr style="background-color: '.$bg.';">'
                .collect($row)->map(fn ($cell) => '<td style="border: 1px solid #e2e8f0;">'.$escape($cell).'</td>')->implode('')
                .'</tr>';
        }
        if ($footerRow !== null) {
            $html .= '<tr style="background-color: #f1f5f9; font-weight: bold;">'
                .collect($footerRow)->map(fn ($cell) => '<td style="border: 1px solid #cbd5e1; font-weight: bold;">'.$escape($cell).'</td>')->implode('')
                .'</tr>';
        }
        $html .= '</tbody></table></div>';
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }

    private function tabular(string $report, array $data): array
    {
        $rows = collect($data['rows'] ?? [])->map(fn ($row) => (array) $row);

        return match ($report) {
            'ledger' => [
                ['التاريخ', 'رقم القيد الرسمي', 'المصدر', 'كود الحساب', 'اسم الحساب', 'البيان', 'مدين (ج.م)', 'دائن (ج.م)', 'الرصيد التراكمي (ج.م)'],
                $rows->map(fn (array $r) => [
                    $r['journal_date'] ?? '',
                    $r['official_number'] ?? '',
                    $r['source_label'] ?? 'قيد مرحّل',
                    $r['account_code'] ?? '',
                    $r['account_name'] ?? '',
                    ($r['description'] ?? '') !== '' ? $r['description'] : ($r['journal_description'] ?? ''),
                    $r['debit'] ?? '0.00',
                    $r['credit'] ?? '0.00',
                    $r['running_balance'] ?? '0.00',
                ])->all(),
                ['الإجمالي', '', '', '', '', 'إجمالي الحركات المرحّلة ('.count($rows).')', $data['debit'] ?? '0.00', $data['credit'] ?? '0.00', $data['closing_balance'] ?? $data['net'] ?? '0.00'],
            ],
            'trial-balance' => [
                ['كود الحساب', 'اسم الحساب', 'التصنيف', 'افتتاحي مدين (ج.م)', 'افتتاحي دائن (ج.م)', 'حركة مدين (ج.م)', 'حركة دائن (ج.م)', 'ختامي مدين (ج.م)', 'ختامي دائن (ج.م)'],
                $rows->map(fn (array $r) => [
                    $r['account_code'] ?? '',
                    $r['account_name'] ?? '',
                    $r['kind_label'] ?? FinancialReports::kindLabel($r['kind'] ?? null),
                    $r['opening_debit'] ?? '0.00',
                    $r['opening_credit'] ?? '0.00',
                    $r['debit'] ?? '0.00',
                    $r['credit'] ?? '0.00',
                    $r['closing_debit'] ?? '0.00',
                    $r['closing_credit'] ?? '0.00',
                ])->all(),
                ['الإجمالي', 'إجمالي ميزان المراجعة', ($data['balanced'] ?? true) ? 'متوازن' : 'غير متوازن', $data['opening_debit'] ?? '0.00', $data['opening_credit'] ?? '0.00', $data['debit'] ?? '0.00', $data['credit'] ?? '0.00', $data['closing_debit'] ?? '0.00', $data['closing_credit'] ?? '0.00'],
            ],
            'income-statement', 'balance-sheet' => [
                ['كود الحساب', 'اسم الحساب', 'التصنيف', 'مدين (ج.م)', 'دائن (ج.م)', 'الرصيد الطبيعي (ج.م)', 'النسبة من المجموعة (%)'],
                $rows->map(fn (array $r) => [
                    $r['account_code'] ?? '',
                    $r['account_name'] ?? '',
                    $r['kind_label'] ?? FinancialReports::kindLabel($r['kind'] ?? null),
                    $r['debit'] ?? '0.00',
                    $r['credit'] ?? '0.00',
                    $r['natural_balance'] ?? $r['balance'] ?? '0.00',
                    ($r['share_percent'] ?? '0.0').'%',
                ])->all(),
                $report === 'income-statement'
                    ? ['الإجمالي', 'صافي قائمة الدخل (الإيرادات - المصروفات)', '', $data['debit'] ?? '0.00', $data['credit'] ?? '0.00', $data['summary']['net_profit'] ?? '0.00', isset($data['summary']['profit_margin']) && $data['summary']['profit_margin'] !== null ? 'هامش '.$data['summary']['profit_margin'].'%' : '']
                    : ['الإجمالي', 'إجمالي الالتزامات وحقوق الملكية (شاملاً أرباح الفترة)', '', $data['debit'] ?? '0.00', $data['credit'] ?? '0.00', $data['summary']['liabilities_plus_equity'] ?? '0.00', ($data['summary']['equation_balanced'] ?? true) ? 'متوازن' : 'فرق '.$data['summary']['equation_difference']],
            ],
            'ppv' => [
                ['رقم الفاتورة', 'المورد', 'تاريخ الفاتورة', 'استلامات بانتظار الفوترة (ج.م)', 'فروق سعر الشراء (ج.م)', 'إجمالي الفاتورة (ج.م)'],
                $rows->map(fn (array $r) => [
                    $r['official_number'] ?? '',
                    $r['supplier_name'] ?? '',
                    $r['invoice_date'] ?? '',
                    $r['grni_amount'] ?? '0.00',
                    $r['ppv_amount'] ?? '0.00',
                    $r['total_amount'] ?? '0.00',
                ])->all(),
                [
                    'الإجمالي',
                    'عدد الفواتير: '.count($rows),
                    '',
                    $rows->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['grni_amount'] ?? '0.00'), 2), '0.00'),
                    $rows->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['ppv_amount'] ?? '0.00'), 2), '0.00'),
                    $rows->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['total_amount'] ?? '0.00'), 2), '0.00'),
                ],
            ],
            'grni', 'reconciliation' => [
                ['بند المصالحة الرقابية', 'رصيد الأستاذ العام (ج.م)', 'رصيد السجل الفرعي المصدري (ج.م)', 'الفرق (ج.م)', 'حالة المطابقة'],
                $rows->map(fn (array $r) => [
                    $r['label'] ?? '',
                    $r['gl'] ?? '0.00',
                    $r['source'] ?? '0.00',
                    $r['difference'] ?? '0.00',
                    ! empty($r['ok']) ? 'متطابق' : 'فرق قائم',
                ])->all(),
                [
                    'الإجمالي',
                    $rows->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['gl'] ?? '0.00'), 2), '0.00'),
                    $rows->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['source'] ?? '0.00'), 2), '0.00'),
                    $rows->reduce(fn (string $s, array $r) => bcadd($s, (string) ($r['difference'] ?? '0.00'), 2), '0.00'),
                    $rows->every(fn (array $r) => ! empty($r['ok'])) ? 'جميع البنود متطابقة' : 'يوجد فروق تحتاج مراجعة',
                ],
            ],
        };
    }
}

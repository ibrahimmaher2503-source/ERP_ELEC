<?php

namespace App\Modules\Accounting\Presentation;

use App\Modules\Accounting\Application\FinancialReports;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinancialReportExportController
{
    public function __invoke(Request $request, string $report, string $format): StreamedResponse|Response
    {
        abort_unless(in_array($report, ['ledger', 'trial-balance', 'income-statement', 'balance-sheet', 'ppv', 'reconciliation'], true)
            && in_array($format, ['xlsx', 'pdf'], true), 404);
        $input = $request->validate(['branch' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $branch = isset($input['branch']) ? (int) $input['branch'] : null;
        $service = app(FinancialReports::class);
        $data = match ($report) {
            'ledger' => $service->ledger($request->user()->id, $branch, $input['from'] ?? null, $input['to'] ?? null),
            'trial-balance' => $service->trialBalance($request->user()->id, $branch, $input['from'] ?? null, $input['to'] ?? null),
            'income-statement' => $service->statement($request->user()->id, 'income', $branch, $input['from'] ?? null, $input['to'] ?? null),
            'balance-sheet' => $service->statement($request->user()->id, 'balance', $branch, null, $input['to'] ?? null),
            'ppv' => ['rows' => $service->ppv($request->user()->id, $branch, $input['from'] ?? null, $input['to'] ?? null)],
            'reconciliation' => ['rows' => $service->reconciliations($request->user()->id, $branch, $input['to'] ?? null)],
        };
        [$headers, $rows] = $this->tabular($report, $data['rows']);
        $name = $report.'-'.now('Africa/Cairo')->format('Ymd-His');
        if ($format === 'xlsx') {
            $book = new Spreadsheet;
            $sheet = $book->getActiveSheet();
            $sheet->setRightToLeft(true);
            $sheet->fromArray([$headers, ...$rows]);
            $sheet->freezePane('A2');

            return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name.'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        $temp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($temp, 0700);
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $temp, 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->SetDirectionality('rtl');
        $escape = fn ($value) => e((string) $value);
        $html = '<h1>'.$escape($report).'</h1><table width="100%" border="1" cellspacing="0" cellpadding="5"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>'.$escape($header).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>'.collect($row)->map(fn ($cell) => '<td>'.$escape($cell).'</td>')->implode('').'</tr>';
        }
        $html .= '</tbody></table>';
        $mpdf->WriteHTML($html);

        return response($mpdf->Output('', Destination::STRING_RETURN), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'.pdf"', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }

    private function tabular(string $report, iterable $source): array
    {
        $rows = collect($source)->map(fn ($row) => (array) $row);
        $fields = match ($report) {
            'ledger' => ['journal_date', 'official_number', 'account_code', 'account_name', 'description', 'debit', 'credit'],
            'trial-balance', 'income-statement', 'balance-sheet' => ['account_code', 'account_name', 'debit', 'credit', 'balance'],
            'ppv' => ['official_number', 'supplier_name', 'invoice_date', 'grni_amount', 'ppv_amount', 'total_amount'],
            'reconciliation' => ['label', 'gl', 'source', 'difference', 'ok'],
        };

        return [$fields, $rows->map(fn ($row) => array_map(fn ($field) => $row[$field] ?? '', $fields))->all()];
    }
}

<?php

namespace App\Modules\System\Application;

use App\Modules\Organization\Application\Contracts\ContractException;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class IssueOfficialDocument implements OfficialDocuments
{
    public function nextMaintenanceReference(string $type, string $date): string
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($type, ['maintenance_request', 'maintenance_work_order']), 500);
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        $year = (int) substr($date, 0, 4);
        $sequence = DB::selectOne('INSERT INTO system_official_sequences(company_id,document_type,year,last_number) VALUES(1,?,?,1) ON CONFLICT(company_id,document_type,year) DO UPDATE SET last_number=system_official_sequences.last_number+1 RETURNING last_number', [$type, $year]);

        return ($type === 'maintenance_request' ? 'MR' : 'WO').'-'.$year.'-'.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
    }

    public function nextOperationalReference(string $type, string $date): string
    {
        $prefix = ['workshop_intake' => 'WI', 'workshop_job' => 'WJ', 'service_contract' => 'SC', 'tender' => 'TN'][$type] ?? null;
        abort_unless(DB::transactionLevel() > 0 && $prefix, 500);
        Validator::make(compact('date'), ['date' => 'required|date_format:Y-m-d'])->validate();
        $year = (int) substr($date, 0, 4);
        $sequence = DB::selectOne('INSERT INTO system_official_sequences(company_id,document_type,year,last_number) VALUES(1,?,?,1) ON CONFLICT(company_id,document_type,year) DO UPDATE SET last_number=system_official_sequences.last_number+1 RETURNING last_number', [$type, $year]);

        return $prefix.'-'.$year.'-'.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
    }

    public const PREFIXES = ['journal' => 'JV', 'purchase_order' => 'PO', 'opening_balance' => 'OB', 'quotation' => 'QT', 'sales_order' => 'SO', 'sales_delivery' => 'DN', 'sales_return' => 'SR', 'purchase_return' => 'PR',
        'supplier_invoice' => 'SI', 'customer_invoice' => 'CI', 'supplier_credit_note' => 'SCN', 'customer_credit_note' => 'CCN',
        'supplier_payment' => 'PV', 'customer_collection' => 'RV', 'customer_advance_refund' => 'ARF', 'customer_credit_refund' => 'CRF', 'supplier_credit_refund' => 'SRF', 'treasury_transfer' => 'TR', 'treasury_correction' => 'TC', 'maintenance_report' => 'MSR', 'workshop_report' => 'WR', 'workshop_quote' => 'WQ', 'workshop_delivery' => 'WD', 'service_contract' => 'SC', 'tender_bid' => 'TB'];

    public function issue(int $actor, string $type, string $source, int $revision, string $date, array $content): array
    {
        abort_unless(DB::transactionLevel() > 0 && isset(self::PREFIXES[$type]), 500);
        Validator::make(compact('source', 'revision', 'date'), ['source' => 'required|string|max:100', 'revision' => 'required|integer|min:1', 'date' => 'required|date_format:Y-m-d'])->validate();
        // Serialize retries of the same immutable source before checking for evidence.
        // The sequence row separately serializes numbering across distinct sources.
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [json_encode(['official-document', 1, $type, $source, $revision], JSON_THROW_ON_ERROR)]);
        $existing = DB::table('system_official_documents')->where('company_id', 1)->where('document_type', $type)->where('source_id', $source)->where('revision', $revision)->first();
        if ($existing) {
            $saved = json_decode($existing->snapshot, true, 512, JSON_THROW_ON_ERROR);
            abort_unless($this->canonical($saved['content']) === $this->canonical($content) && $existing->document_date === $date, 409, 'إصدار المصدر الرسمي محفوظ بمحتوى مختلف بالفعل.');

            return ['id' => $existing->id, 'number' => $existing->official_number, 'sha256' => $existing->sha256];
        }
        try {
            $company = app(OrganizationApi::class)->documentCompany($actor);
        } catch (ContractException $e) {
            if ($e->getCode() === 403) {
                abort(403, $e->getMessage());
            }
            throw ValidationException::withMessages(['readiness' => $e->getMessage()]);
        }
        $year = (int) substr($date, 0, 4);
        $sequence = DB::selectOne('INSERT INTO system_official_sequences(company_id,document_type,year,last_number) VALUES(1,?,?,1) ON CONFLICT(company_id,document_type,year) DO UPDATE SET last_number=system_official_sequences.last_number+1 RETURNING last_number', [$type, $year]);
        $number = self::PREFIXES[$type].'-'.$year.'-'.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT);
        $snapshot = ['company' => $company, 'number' => $number, 'date' => $date, 'revision' => $revision, 'content' => $content];
        $temp = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($temp, 0700);
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => $temp, 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $pdf->SetDirectionality('rtl');
        $pdf->SetTitle($number);
        $pdf->SetAuthor($company['legal_name']);
        $pdf->WriteHTML(view('system.official-document', ['snapshot' => $snapshot])->render());
        $bytes = $pdf->Output('', Destination::STRING_RETURN);
        $id = (string) Str::uuid();
        $hash = hash('sha256', $bytes);
        // PDF and source snapshot commit/rollback together, without orphan public files.
        DB::table('system_official_documents')->insert(['id' => $id, 'company_id' => 1, 'document_type' => $type, 'source_id' => $source,
            'revision' => $revision, 'document_date' => $date, 'official_number' => $number, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'pdf_base64' => base64_encode($bytes), 'sha256' => $hash, 'issued_by' => $actor, 'issued_at' => now()]);

        return ['id' => $id, 'number' => $number, 'sha256' => $hash];
    }

    public function retrieve(string $id): array
    {
        $row = DB::table('system_official_documents')->find($id);
        abort_unless($row, 404);
        $bytes = base64_decode($row->pdf_base64, true);
        abort_unless(is_string($bytes) && hash_equals($row->sha256, hash('sha256', $bytes)), 500, 'Official document integrity failure.');

        return ['number' => $row->official_number, 'bytes' => $bytes, 'sha256' => $row->sha256];
    }

    private function canonical(array $value): string
    {
        $normalize = function (array $item) use (&$normalize): array {
            if (! array_is_list($item)) {
                ksort($item);
            }
            foreach ($item as &$child) {
                if (is_array($child)) {
                    $child = $normalize($child);
                }
            }

            return $item;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

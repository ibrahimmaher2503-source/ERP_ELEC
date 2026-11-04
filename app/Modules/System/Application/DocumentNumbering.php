<?php

namespace App\Modules\System\Application;

use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Starting numbers can be set only before a document type is first issued that year. */
final class DocumentNumbering
{
    public const LABELS = ['journal' => 'قيد يومية', 'purchase_order' => 'أمر شراء', 'quotation' => 'عرض سعر', 'sales_order' => 'أمر بيع', 'sales_delivery' => 'إذن تسليم',
        'sales_return' => 'مرتجع بيع', 'purchase_return' => 'مرتجع مشتريات', 'supplier_invoice' => 'فاتورة مورد', 'customer_invoice' => 'فاتورة عميل',
        'supplier_credit_note' => 'إشعار مورد', 'customer_credit_note' => 'إشعار عميل', 'supplier_payment' => 'سند صرف', 'customer_collection' => 'سند قبض'];

    public function list(int $actor, int $year): array
    {
        $this->authorize($actor, 'M01.company_view');
        $sequences = DB::table('system_official_sequences')->where('company_id', 1)->where('year', $year)->pluck('last_number', 'document_type');
        $issued = DB::table('system_official_documents')->where('company_id', 1)->whereRaw('extract(year from document_date)=?', [$year])
            ->groupBy('document_type')->selectRaw('document_type,count(*) total')->pluck('total', 'document_type');
        $rows = [];
        foreach (self::LABELS as $type => $label) {
            $next = (int) ($sequences[$type] ?? 0) + 1;
            $rows[] = ['type' => $type, 'label' => $label, 'prefix' => IssueOfficialDocument::PREFIXES[$type], 'next' => $next, 'locked' => (int) ($issued[$type] ?? 0) > 0,
                'example' => IssueOfficialDocument::PREFIXES[$type].'-'.$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT)];
        }

        return $rows;
    }

    public function setStart(int $actor, string $type, int $year, int $start): void
    {
        $this->authorize($actor, 'M01.company_update');
        Validator::make(compact('type', 'year', 'start'), ['type' => 'required|in:'.implode(',', array_keys(self::LABELS)), 'year' => 'required|integer|min:2000|max:2100', 'start' => 'required|integer|min:1|max:99999999'])->validate();
        DB::transaction(function () use ($actor, $type, $year, $start) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['document-numbering:'.$type.':'.$year]);
            $used = DB::table('system_official_documents')->where('company_id', 1)->where('document_type', $type)->whereRaw('extract(year from document_date)=?', [$year])->exists();
            if ($used) {
                throw ValidationException::withMessages(['start' => 'صدر مستند من هذا النوع في السنة نفسها، فلا يمكن تغيير الترقيم.']);
            }
            DB::table('system_official_sequences')->updateOrInsert(['company_id' => 1, 'document_type' => $type, 'year' => $year], ['last_number' => $start - 1]);
            app(RecordAudit::class)->execute($actor, 'document_numbering', 1, 'document_numbering.start_set', null, compact('type', 'year', 'start'), 'succeeded', 'System');
        }, 3);
    }

    private function authorize(int $actor, string $permission): void
    {
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $permission));
    }
}

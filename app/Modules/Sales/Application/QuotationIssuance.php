<?php

namespace App\Modules\Sales\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationChanged;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class QuotationIssuance
{
    private function access(int $actor, string $action): void
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, $action);
    }

    public function issue(int $actor, int $id, int $version): void
    {
        app(QuotationAccess::class)->authorizeRecord($actor, $id);
        $this->access($actor, 'issue');
        DB::transaction(function () use ($actor, $id, $version) {
            DB::select('SELECT pg_advisory_xact_lock(40411)');
            $r = Quotation::with('lines')->lockForUpdate()->findOrFail($id);
            if (DB::table('sales_quotation_issues')->where('quotation_id', $id)->exists()) {
                return;
            }
            app(QuotationActions::class)->version($r, $version);
            abort_unless($r->editable(), 409);
            app(QuotationCatalog::class)->customer($actor, $r->customer_id);
            $evaluation = app(QuotationCommercialPolicy::class)->evaluate($r, $actor);
            $blockers = $evaluation['blockers'];
            if ($evaluation['exceptions'] && ! app(QuotationApprovals::class)->hasCurrentApproval($r, $evaluation)) {
                $blockers[] = 'يلزم اعتماد الاستثناءات التجارية الحالية.';
            }
            if (! $r->valid_until || $r->valid_until->toDateString() < now('Africa/Cairo')->toDateString()) {
                $blockers[] = 'عرض السعر منتهي الصلاحية.';
            }
            if ($blockers) {
                throw ValidationException::withMessages(['readiness' => implode(' ', $blockers)]);
            }
            app(AccountingApi::class)->requirePostingDate($r->quotation_date->toDateString());
            $draft = app(DraftAmounts::class)->calculate($r->lines->toArray(), $r->discount_mode, $r->discount_value);
            abort_unless($draft['complete'], 422);
            $taxLines = [];
            $rows = [];
            foreach ($r->lines as $i => $line) {
                app(QuotationCatalog::class)->product($actor, $line->product_id, $line->unit_id, $line->quantity);
                $price = $line->requested_price ?? $line->suggested_price;
                $taxLines[] = ['product_id' => $line->product_id, 'quantity' => $line->quantity, 'unit_price' => $price,
                    'discount' => bcadd($draft['lines'][$i]['discount'], '0', 32)];
                $rows[] = [$line->product_snapshot['sku'], $line->description, $line->product_snapshot['unit_name'], $line->quantity, $price,
                    bcadd($draft['lines'][$i]['discount'], '0', 8)];
            }
            $tax = app(AccountingApi::class)->calculateTax($actor, 'sale', $r->quotation_date->toDateString(), 'exclusive',
                $taxLines, bcadd($draft['quotation_discount'], '0', 32));
            if (! $tax['production_validated']) {
                throw ValidationException::withMessages(['readiness' => 'يلزم التحقق من المعاملة الضريبية قبل الإصدار.']);
            }
            $snapshot = app(QuotationApprovals::class)->snapshot($r);
            $doc = app(OfficialDocuments::class)->issue($actor, 'quotation', (string) $id, $r->revision, $r->quotation_date->toDateString(), [
                'title' => 'عرض سعر', 'signature_area' => true, 'fields' => ['العميل' => $r->customer_snapshot['name'], 'صالح حتى' => $r->valid_until->toDateString(),
                    'المشروع' => $r->project_name ?? '', 'مرجع طلب السعر' => $r->rfq_reference ?? '',
                    'خصم العرض' => bcadd($draft['quotation_discount'], '0', 8),
                    'شروط السداد' => $r->payment_terms ?? '', 'شروط التسليم' => $r->delivery_terms ?? '', 'ملاحظات' => $r->notes ?? ''],
                'columns' => ['الكود', 'الصنف', 'الوحدة', 'الكمية', 'سعر الوحدة', 'خصم البند'], 'rows' => $rows, 'tax' => $tax,
                'totals' => ['الصافي' => $tax['net'], 'الضريبة' => $tax['tax'], 'الإجمالي' => $tax['total']],
            ]);
            $r->update(['calculation' => array_replace($draft, ['net' => $tax['net'], 'tax' => $tax['tax'], 'total' => $tax['total'], 'official' => true])]);
            DB::table('sales_quotation_issues')->insert(['quotation_id' => $id, 'source_version' => $version,
                'document_id' => $doc['id'], 'tax_snapshot' => json_encode($tax, JSON_THROW_ON_ERROR),
                'commercial_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'issued_by' => $actor, 'issued_at' => now()]);
            $r->update(['number' => $doc['number'], 'status' => 'issued', 'version' => $version + 1, 'updated_by' => $actor]);
            event(new QuotationChanged($actor, $id, 'issued', null, ['document_id' => $doc['id'], 'number' => $doc['number'], 'sha256' => $doc['sha256'], 'tax' => $tax]));
        });
    }

    public function accept(int $actor, int $id, int $version, array $input, ?UploadedFile $file): void
    {
        app(QuotationAccess::class)->authorizeRecord($actor, $id);
        $this->access($actor, 'accept');
        $v = Validator::make(array_replace($input, ['file' => $file]), ['accepted_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('Africa/Cairo')->toDateString(),
            'evidence_type' => 'required|in:purchase_order,email,signed_acceptance', 'reference' => 'required|string|min:3|max:500',
            'file' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg'])->validate();
        $bytes = $file->getContent();
        $hash = hash('sha256', $bytes);
        DB::transaction(function () use ($actor, $id, $version, $v, $file, $bytes, $hash) {
            $r = Quotation::lockForUpdate()->findOrFail($id);
            $existing = DB::table('sales_quotation_acceptances')->where('quotation_id', $id)->first();
            if ($existing) {
                abort_unless($existing->sha256 === $hash && $existing->reference === $v['reference']
                    && $existing->accepted_on === $v['accepted_on'] && $existing->evidence_type === $v['evidence_type'], 409);

                return;
            }
            app(QuotationActions::class)->version($r, $version);
            $issue = DB::table('sales_quotation_issues')->where('quotation_id', $id)->first();
            abort_unless($issue && in_array($r->status, ['issued', 'sent'], true), 409);
            if ($v['accepted_on'] < $r->quotation_date->toDateString() || $v['accepted_on'] > $r->valid_until->toDateString()) {
                throw ValidationException::withMessages(['acceptance.accepted_on' => 'تاريخ القبول خارج صلاحية الإصدار.']);
            }
            DB::table('sales_quotation_acceptances')->insert(['quotation_id' => $id, 'issue_id' => $issue->id,
                'accepted_on' => $v['accepted_on'], 'evidence_type' => $v['evidence_type'], 'reference' => $v['reference'],
                'evidence_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
                'evidence_mime' => $file->getMimeType(), 'evidence_base64' => base64_encode($bytes), 'sha256' => $hash,
                'recorded_by' => $actor, 'recorded_at' => now()]);
            $r->update(['status' => 'accepted', 'version' => $version + 1, 'updated_by' => $actor]);
            event(new QuotationChanged($actor, $id, 'accepted', null, ['issue_id' => $issue->id, 'reference' => $v['reference'], 'sha256' => $hash]));
        });
    }
}

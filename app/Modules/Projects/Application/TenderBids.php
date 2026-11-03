<?php

namespace App\Modules\Projects\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Products\Application\Contracts\ConvertQuantityRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TenderBids
{
    public function technical(int $actor, int $id, int $version, array $input): int
    {
        $d = Validator::make($input, [
            'requirements' => 'required|string|min:5|max:10000', 'documents' => 'required|array|min:1|max:100', 'documents.*' => 'integer|distinct',
            'lines' => 'required|array|min:1|max:500', 'lines.*.description' => 'required|string|max:4000', 'lines.*.quantity' => 'required|string',
            'lines.*.unit' => 'required|string|max:80', 'lines.*.specification' => 'required|string|max:5000', 'lines.*.compliance' => 'required|in:compliant,partial,noncompliant',
            'lines.*.deviation' => 'nullable|string|max:4000', 'lines.*.alternative' => 'nullable|string|max:4000', 'lines.*.product_id' => 'nullable|integer|min:1',
            'lines.*.catalog_reference' => 'nullable|string|max:1000',
            'lines.*.unit_id' => 'nullable|integer|min:1',
        ])->validate();

        return DB::transaction(function () use ($actor, $id, $version, $d) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, 'technical_manage');
            abort_unless(in_array($t->status, ['preparing_technical', 'preparing_financial', 'internal_approval']), 409);
            $docs = [];
            foreach ($d['documents'] as $doc) {
                $r = app(TenderDocuments::class)->evidence($id, $doc);
                abort_if(in_array($r->classification, TenderDocuments::FINANCIAL, true), 422, 'لا تدرج مستنداً مالياً في العرض الفني.');
                $docs[] = ['id' => $r->id, 'sha256' => $r->sha256];
            }
            $lines = [];
            foreach ($d['lines'] as $index => $line) {
                Decimal::value($line['quantity'], 8);
                abort_unless(bccomp($line['quantity'], '0', 8) > 0, 422);
                abort_if($line['compliance'] !== 'compliant' && empty(trim($line['deviation'] ?? '')), 422, 'يلزم توثيق الانحراف الفني.');
                $product = null;
                $conversion = null;
                if (! empty($line['product_id'])) {
                    $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $line['product_id']))->product;
                    abort_unless($product['active'], 422);
                    abort_unless(! empty($line['unit_id']), 422, 'يلزم تعيين وحدة M02 لكمية البند؛ لا نفترض وحدة الأساس.');
                    try {
                        $conversion = app(ProductsApi::class)->convertQuantity(new ConvertQuantityRequest($actor, (int) $line['product_id'], (int) $line['unit_id'], $line['quantity']));
                    } catch (ModelNotFoundException) {
                        abort(422, 'الوحدة غير متاحة للصنف المحدد.');
                    }
                }
                $lines[] = $line + ['position' => $index + 1, 'product' => $product ? array_intersect_key($product, array_flip(['id', 'sku', 'name', 'base_unit_id', 'kind'])) : null, 'unit_mapping' => $conversion ? ['unit_id' => (int) $line['unit_id'], 'base_unit_id' => $conversion->baseUnitId, 'base_quantity' => $conversion->baseQuantity] : null];
            }
            $snapshot = ['requirements' => $d['requirements'], 'documents' => $docs, 'lines' => $lines];
            $rev = (int) DB::table('project_tender_technical')->where('tender_id', $id)->max('revision') + 1;
            $tid = DB::table('project_tender_technical')->insertGetId(['tender_id' => $id, 'revision' => $rev, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'sha256' => TenderActions::hash($snapshot), 'prepared_by' => $actor]);
            $a->touch($actor, $t, 'technical.prepared', ['technical_id' => $tid, 'revision' => $rev], ['technical_id' => $tid, 'financial_id' => null, 'status' => 'preparing_financial']);

            return $tid;
        }, 5);
    }

    public function financial(int $actor, int $id, int $version, array $input): int
    {
        $d = Validator::make($input, ['date' => 'required|date_format:Y-m-d', 'mode' => 'required|in:exclusive,inclusive', 'terms' => 'required|string|min:5|max:10000', 'reference' => 'required|string|min:5|max:5000', 'lines' => 'required|array|min:1|max:500', 'lines.*.cost_reference' => 'required|string|min:5|max:5000'])->validate();
        // Financial estimate rows follow the immutable technical BOQ by position.
        foreach (['labor', 'transport', 'other', 'contingency', 'discount'] as $field) {
            $d[$field] = Decimal::value($input[$field] ?? null, 8);
        }
        foreach ($input['lines'] as $i => $row) {
            $d['lines'][$i]['unit_cost'] = Decimal::value($row['unit_cost'] ?? null, 8);
            $d['lines'][$i]['selling_price'] = Decimal::value($row['selling_price'] ?? null, 8);
            $d['lines'][$i]['price_reference'] = Validator::make($row, ['price_reference' => 'required|string|min:5|max:5000'])->validate()['price_reference'];
        }

        return DB::transaction(function () use ($actor, $id, $version, $d) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, 'financial_manage');
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
            abort_unless($t->technical_id && in_array($t->status, ['preparing_financial', 'internal_approval']), 409);
            $tech = DB::table('project_tender_technical')->find($t->technical_id);
            $technical = json_decode($tech->snapshot, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(count($technical['lines']) === count($d['lines']), 422, 'الأسعار يجب أن تطابق بنود النسخة الفنية.');
            $lines = [];
            $taxLines = [];
            $cost = '0.00000000';
            foreach ($technical['lines'] as $i => $line) {
                $price = $d['lines'][$i];
                $product = $line['product'];
                abort_if($product && empty($line['unit_id']), 409, 'أعد تعيين وحدة الصنف في نسخة فنية جديدة قبل التسعير.');
                if ($product) {
                    $current = app(ProductsApi::class)->convertQuantity(new ConvertQuantityRequest($actor, $product['id'], (int) $line['unit_id'], $line['quantity']));
                    abort_unless(isset($line['unit_mapping']) && $current->baseUnitId === $line['unit_mapping']['base_unit_id'] && bccomp($current->baseQuantity, $line['unit_mapping']['base_quantity'], 8) === 0, 409, 'تغير تحويل الوحدة؛ يلزم إصدار فني جديد.');
                }
                $approved = $product ? app(QuotationPricing::class)->suggested($actor, $product['id'], (int) $line['unit_id'], $d['date']) : null;
                $cost = bcadd($cost, bcmul($line['quantity'], $price['unit_cost'], 8), 8);
                $lines[] = $price + ['position' => $i + 1, 'quantity' => $line['quantity'], 'description' => $line['description'], 'product_id' => $product['id'] ?? null, 'unit_id' => $product ? (int) $line['unit_id'] : null, 'unit_mapping' => $line['unit_mapping'] ?? null, 'approved_price' => $approved, 'commercial_exception' => $approved === null || bccomp($approved['amount'], $price['selling_price'], 8) !== 0];
                $taxLines[] = ['product_id' => $product['id'] ?? null, 'quantity' => $line['quantity'], 'unit_price' => $price['selling_price'], 'discount' => '0'];
            }
            // Overheads are internal costs, not extra selling lines or posted expenses.
            foreach (['labor', 'transport', 'other', 'contingency'] as $field) {
                $cost = bcadd($cost, $d[$field], 8);
            }
            abort_if(bccomp($cost, '9999999999999999.99999999', 8) > 0, 422, 'تجاوزت التكلفة دقة EGP المدعومة.');
            $tax = app(AccountingApi::class)->calculateTenderTax($actor, $d['date'], $d['mode'], $taxLines, $d['discount']);
            $net = $tax['net'];
            $taxAmount = $tax['tax'];
            $total = $tax['total'];
            $snapshot = $d;
            $snapshot['lines'] = $lines;
            $snapshot['tax'] = $tax;
            $snapshot['currency'] = 'EGP';
            $snapshot['technical_sha256'] = $tech->sha256;
            $snapshot['cost'] = $cost;
            $snapshot['margin_amount'] = bcsub($net, $cost, 8);
            $snapshot['margin_percent'] = bccomp($net, '0', 8) > 0 ? bcdiv(bcmul(bcsub($net, $cost, 8), '100', 8), $net, 8) : null;
            $rev = (int) DB::table('project_tender_financial')->where('tender_id', $id)->max('revision') + 1;
            $fid = DB::table('project_tender_financial')->insertGetId(['tender_id' => $id, 'technical_id' => $tech->id, 'revision' => $rev, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'sha256' => TenderActions::hash($snapshot), 'cost' => $cost, 'net' => $net, 'tax' => $taxAmount, 'total' => $total, 'prepared_by' => $actor]);
            $a->touch($actor, $t, 'financial.prepared', ['financial_id' => $fid, 'revision' => $rev], ['financial_id' => $fid, 'status' => 'internal_approval']);

            return $fid;
        }, 5);
    }

    public function approve(int $actor, int $id, int $version, string $kind, string $reference): void
    {
        Validator::make(compact('kind', 'reference'), ['kind' => 'required|in:technical,financial', 'reference' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $kind, $reference) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, $kind.'_approve');
            if ($kind === 'financial') {
                app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
            }
            abort_unless(in_array($t->status, ['preparing_financial', 'internal_approval']), 409);
            $revision = $t->{$kind.'_id'};
            abort_unless($revision, 409);
            $r = DB::table('project_tender_'.$kind)->where('tender_id', $id)->find($revision);
            abort_unless($r, 409);
            abort_if($r->prepared_by === $actor, 403, 'الاعتماد يتطلب شخصاً مستقلاً عن المُعد.');
            if ($kind === 'financial') {
                abort_unless($r->technical_id === $t->technical_id, 409);
            }
            abort_if(DB::table('project_tender_approvals')->where('kind', $kind)->where($kind.'_id', $revision)->exists(), 409, 'النسخة معتمدة بالفعل.');
            DB::table('project_tender_approvals')->insert(['tender_id' => $id, 'kind' => $kind, $kind.'_id' => $revision, 'approved_by' => $actor, 'reference' => $reference]);
            $a->touch($actor, $t, $kind.'.approved', [$kind.'_id' => $revision, 'reference' => $reference]);
        }, 5);
    }

    public function approved(object $t): array
    {
        abort_unless($t->technical_id && $t->financial_id, 409, 'يلزم عرض فني ومالي حالي.');
        $f = DB::table('project_tender_financial')->where('tender_id', $t->id)->find($t->financial_id);
        $technical = DB::table('project_tender_technical')->where('tender_id', $t->id)->find($t->technical_id);
        abort_unless($f && $technical && $f->technical_id === $technical->id, 409);
        foreach (['technical' => $t->technical_id, 'financial' => $t->financial_id] as $kind => $revision) {
            abort_unless(DB::table('project_tender_approvals')->where('tender_id', $t->id)->where('kind', $kind)->where($kind.'_id', $revision)->exists(), 409, 'يلزم اعتماد النسختين الحاليتين.');
        }
        foreach (json_decode($technical->snapshot, true)['documents'] as $doc) {
            $r = app(TenderDocuments::class)->evidence($t->id, $doc['id']);
            abort_unless(hash_equals($r->sha256, $doc['sha256']), 409);
        }

        return [$technical, $f];
    }

    public function issue(int $actor, int $id, int $version): array
    {
        return DB::transaction(function () use ($actor, $id, $version) {
            $a = app(TenderActions::class);
            $t = $a->work($actor, $id, $version, 'submit');
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
            abort_unless($t->status === 'internal_approval', 409);
            [$technical,$f] = $this->approved($t);
            $s = json_decode($f->snapshot, true);
            $technicalSnapshot = json_decode($technical->snapshot, true);
            $rows = [];
            foreach ($s['lines'] as $i => $line) {
                $r = $technicalSnapshot['lines'][$i];
                $rows[] = [(string) ($i + 1), $line['description'], $r['specification'], $r['compliance'], $r['deviation'] ?? '', $r['alternative'] ?? '', $line['quantity'], $r['unit'], $line['selling_price']];
            }
            $content = ['title' => 'عرض عطاء مناقصة', 'fields' => ['المرجع' => $t->number, 'مرجع الجهة' => $t->external_reference, 'الجهة' => $t->issuer, 'المناقصة' => $t->title, 'المراجعة الفنية' => (string) $technical->revision, 'بصمة النسخة الفنية' => $technical->sha256, 'المراجعة المالية' => (string) $f->revision, 'بصمة النسخة المالية' => $f->sha256, 'المتطلبات' => $technicalSnapshot['requirements'], 'الشروط' => $s['terms'], 'ملاحظة' => 'عرض معتمد داخلياً. لا يثبت تقديمه للجهة أو توقيعه قانونياً.'], 'columns' => ['البند', 'الوصف', 'المواصفة', 'المطابقة', 'الانحراف', 'البديل المعتمد', 'الكمية', 'الوحدة', 'سعر البيع'], 'rows' => $rows, 'totals' => ['الصافي' => $f->net, 'الضريبة' => $f->tax, 'الإجمالي' => $f->total], 'tax' => $s['tax']];
            $doc = app(OfficialDocuments::class)->issue($actor, 'tender_bid', (string) $id, $f->revision, $s['date'], $content);
            if (! DB::table('project_tender_bid_documents')->where('financial_id', $f->id)->exists()) {
                DB::table('project_tender_bid_documents')->insert(['financial_id' => $f->id, 'document_id' => $doc['id'], 'issued_by' => $actor]);
                $a->touch($actor, $t, 'bid.issued', ['financial_id' => $f->id, 'document_id' => $doc['id'], 'sha256' => $doc['sha256']]);
            }

            return $doc;
        }, 5);
    }

    public function submit(int $actor, int $id, int $version, string $key, array $input): int
    {
        $d = Validator::make($input, ['method' => 'required|in:physical,email,portal', 'reference' => 'required|string|min:5|max:5000', 'submitted_at' => 'required|date', 'evidence_id' => 'required|integer|min:1'])->validate();
        Validator::make(compact('key'), ['key' => 'required|uuid'])->validate();
        abort_unless(preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $d['submitted_at']), 422);
        $d['submitted_at'] = CarbonImmutable::parse($d['submitted_at'])->utc()->toIso8601String();

        return DB::transaction(function () use ($actor, $id, $version, $key, $d) {
            $a = app(TenderActions::class);
            $a->lock();
            $t = DB::table('project_tenders')->lockForUpdate()->find($id);
            abort_unless($t, 404);
            app(TenderAccess::class)->authorize($actor, $t->branch_id);
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'submit');
            app(TenderAccess::class)->authorize($actor, $t->branch_id, 'financial_view');
            $hash = TenderActions::hash($d);
            if ($old = DB::table('project_tender_submissions')->where('creation_key', $key)->first()) {
                abort_unless($old->tender_id === $id && $old->submitted_by === $actor && hash_equals($old->payload_hash, $hash), 409);

                return $old->id;
            }
            abort_unless($t->version === $version && $t->status === 'internal_approval', 409);
            [$technical,$f] = $this->approved($t);
            $deadlines = json_decode($t->deadlines, true);
            $at = CarbonImmutable::parse($d['submitted_at']);
            abort_if($at->isFuture() || $at->lt($t->published_on.'T00:00:00Z'), 422, 'توقيت التقديم غير صالح.');
            foreach (['technical_at', 'financial_at', 'final_at'] as $date) {
                abort_if($deadlines[$date] && ($at->gt($deadlines[$date]) || now()->gt($deadlines[$date])), 409, 'انتهى موعد التقديم. يلزم تمديد موثق.');
            }
            $evidence = app(TenderDocuments::class)->evidence($id, (int) $d['evidence_id']);
            abort_unless($evidence->classification === 'submission', 422, 'يلزم إيصال تقديم مصنف.');
            $bid = DB::table('project_tender_bid_documents')->where('financial_id', $f->id)->first();
            abort_unless($bid, 409, 'أصدر مستند النسخة المعتمدة أولاً.');
            $pdf = app(OfficialDocuments::class)->retrieve($bid->document_id);
            $snapshot = ['technical_id' => $technical->id, 'technical_sha256' => $technical->sha256, 'financial_id' => $f->id, 'financial_sha256' => $f->sha256, 'documents' => json_decode($technical->snapshot, true)['documents'], 'bid_sha256' => $pdf['sha256'], 'evidence_sha256' => $evidence->sha256, 'deadlines' => $deadlines];
            $sid = DB::table('project_tender_submissions')->insertGetId($d + ['tender_id' => $id, 'creation_key' => $key, 'financial_id' => $f->id, 'technical_id' => $technical->id, 'document_id' => $bid->document_id, 'submitted_by' => $actor, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'payload_hash' => $hash]);
            $a->touch($actor, $t, 'bid.submitted', ['submission_id' => $sid, 'method' => $d['method'], 'reference' => $d['reference']], ['status' => 'submitted', 'submitted_id' => $sid]);

            return $sid;
        }, 5);
    }
}

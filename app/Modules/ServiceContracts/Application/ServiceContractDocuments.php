<?php

namespace App\Modules\ServiceContracts\Application;

use App\Modules\System\Application\Contracts\OfficialDocuments;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ServiceContractDocuments
{
    public function issue(int $actor, int $contract): array
    {
        return DB::transaction(function () use ($actor, $contract) {
            $a = app(ServiceContractActions::class);
            $c = $a->contract($actor, $contract, 'document_issue');
            $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
            abort_unless(in_array($r->status, ['approved', 'signed', 'active']), 409, 'إصدار الوثيقة الرسمية من مراجعة معتمدة فقط.');
            $issued = DB::table('service_contract_official_documents')->where('revision_id', $r->id)->first();
            if ($issued) {
                $document = app(OfficialDocuments::class)->retrieve($issued->document_id);

                return ['id' => $issued->document_id, 'number' => $document['number'], 'sha256' => $document['sha256']];
            }
            $d = json_decode($r->data, true, 512, JSON_THROW_ON_ERROR);
            $date = CarbonImmutable::parse($r->created_at)->setTimezone('Africa/Cairo')->toDateString();
            $schedule = $d['billing_mode'] === 'installments' ? array_map(fn ($line) => $line['code'].' | '.$line['due_date'].' | '.$line['amount'].' EGP | '.$line['description'], $d['schedule'])
                : array_map(fn ($line) => $line['code'].' | '.$line['description'].' | صنف #'.$line['product_id'].' | '.$line['quantity'].' × '.$line['rate'].' EGP', $d['boq']);
            $doc = app(OfficialDocuments::class)->issue($actor, 'service_contract', (string) $contract, (int) $r->revision, $date,
                ['title' => 'عقد خدمة وصيانة', 'fields' => ['العقد' => (string) $contract, 'العنوان' => $d['title'], 'العميل' => $d['customer_name'], 'المسؤول' => $d['responsible_employee_name'], 'بداية التغطية' => $d['starts_on'], 'نهاية التغطية' => $d['ends_on'], 'شروط الخدمة' => $d['terms'], 'مهلة الاستجابة بالدقائق' => (string) $d['sla_response_minutes'], 'مهلة المعالجة بالدقائق' => (string) $d['sla_resolution_minutes'],
                    'وقت العمل المغطى بالدقائق' => (string) $d['covered_labor_minutes'], 'طريقة الفوترة' => $d['billing_mode'] === 'installments' ? 'أقساط' : 'أعمال مقاسة',
                    'أساس الأسعار' => match ($d['pricing_tax_mode'] ?? null) {
                        'inclusive' => 'شامل الضريبة', 'exclusive' => 'غير شامل الضريبة', default => 'غير محدد؛ الفوترة ممنوعة'
                    },
                    'جدول الفوترة الموقع' => implode("\n", $schedule),
                    'قطع الغيار المغطاة' => implode("\n", array_map(fn ($p) => 'صنف #'.$p['product_id'].' | '.$p['quantity'], $d['parts'])),
                    'الأصناف المستثناة' => implode(', ', $d['excluded_parts']),
                    'جدول الخدمة الوقائية' => implode("\n", array_map(fn ($v) => $v['code'].' | '.$v['equipment_ref'].' | موقع '.($v['site_id'] ?? 'غير محدد').' | '.$v['due_date'].' | '.$v['description'], $d['preventive_schedule'] ?? []))],
                    'columns' => ['المعدة', 'الموقع', 'زيارات وقائية', 'زيارات إصلاح'],
                    'rows' => array_map(fn ($i) => [$i['equipment_ref'], (string) ($i['site_id'] ?? ''), (string) $i['preventive_visits'], (string) $i['corrective_visits']], $d['coverage']),
                    'totals' => ['قيمة العقد EGP' => $d['value']]]);
            $old = DB::table('service_contract_official_documents')->where('revision_id', $r->id)->first();
            if (! $old) {
                DB::table('service_contract_official_documents')->insert(['revision_id' => $r->id, 'document_id' => $doc['id'], 'issued_by' => $actor]);
                $a->audit($actor, $c, 'contract.official_issued', ['document_id' => $doc['id'], 'sha256' => $doc['sha256']]);
            }

            return $doc;
        }, 3);
    }

    public function store(int $actor, int $contract, UploadedFile $file, string $classification, string $reference): int
    {
        Validator::make(compact('file', 'classification', 'reference'), [
            'file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
            'classification' => 'required|in:signature,agreement,supporting',
            'reference' => 'required|string|max:160|not_regex:/\x00/',
        ])->validate();
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $contract, $file, $classification, $reference, &$path) {
                $a = app(ServiceContractActions::class);
                $c = $a->contract($actor, $contract, 'document_manage');
                $r = DB::table('service_contract_revisions')->find($c->current_revision_id);
                abort_unless(in_array($r->status, ['draft', 'submitted', 'approved']) && ! in_array($c->status, ['terminated', 'completed']), 409, 'التوقيع والشروط المثبتة لا تستبدل.');
                $path = $file->store('service-contracts/'.$contract.'/revisions/'.$r->id, 'local');
                abort_unless($path, 500);
                $id = DB::table('service_contract_documents')->insertGetId([
                    'contract_id' => $contract, 'revision_id' => $r->id, 'classification' => $classification, 'reference' => $reference,
                    'path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
                    'mime' => $file->getMimeType(), 'sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'terms_sha256' => hash('sha256', $r->data), 'uploaded_by' => $actor,
                ]);
                $a->audit($actor, $c, 'document.stored', ['document_id' => $id, 'classification' => $classification, 'sha256' => hash_file('sha256', Storage::disk('local')->path($path))]);

                return $id;
            }, 1);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    public function verify(object $document): string
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->path) && hash_equals($document->sha256, hash_file('sha256', $disk->path($document->path))), 409, 'دليل العقد مفقود أو تغير محتواه.');

        return $disk->path($document->path);
    }

    public function download(int $actor, int $document): BinaryFileResponse
    {
        $d = DB::table('service_contract_documents')->find($document);
        abort_unless($d, 404);
        $c = DB::table('service_contracts')->find($d->contract_id);
        app(ServiceContractAccess::class)->authorize($actor, (int) $c->branch_id, 'document_view');

        return response()->download($this->verify($d), $d->original_name, ['Content-Type' => $d->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}

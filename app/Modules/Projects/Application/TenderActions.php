<?php

namespace App\Modules\Projects\Application;

use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\GetCustomerRequest;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\System\Application\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class TenderActions
{
    public const DATES = ['site_visit_at', 'clarification_at', 'technical_at', 'financial_at', 'final_at', 'opening_at'];

    public const TERMINAL = ['no_bid', 'withdrawn', 'lost', 'cancelled', 'awarded'];

    public function lock(): void
    {
        DB::select('select pg_advisory_xact_lock(10401)');
        DB::select('select pg_advisory_xact_lock(70701)');
    }

    public static function hash(array $data): string
    {
        $sort = function (array $v) use (&$sort): array {
            if (! array_is_list($v)) {
                ksort($v);
            }
            foreach ($v as &$value) {
                if (is_array($value)) {
                    $value = $sort($value);
                }
            }

            return $v;
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function work(int $actor, int $id, int $version, string $permission = 'manage'): object
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        $this->lock();
        $t = DB::table('project_tenders')->lockForUpdate()->find($id);
        abort_unless($t, 404);
        app(TenderAccess::class)->authorize($actor, $t->branch_id);
        app(TenderAccess::class)->authorize($actor, $t->branch_id, $permission);
        abort_unless($t->version === $version, 409, 'تغيرت المناقصة؛ أعد فتح الصفحة.');

        return $t;
    }

    public function touch(int $actor, object $t, string $action, array $snapshot, array $changes = []): void
    {
        DB::table('project_tenders')->where('id', $t->id)->update($changes + ['version' => $t->version + 1, 'updated_at' => now()]);
        DB::table('project_tender_events')->insert(['tender_id' => $t->id, 'actor_id' => $actor, 'action' => $action, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        app(RecordAudit::class)->execute($actor, 'tender', $t->id, $action, ['version' => $t->version], ['version' => $t->version + 1] + $snapshot, 'succeeded', 'Projects');
    }

    public function dates(array $input): array
    {
        $rules = [];
        foreach (self::DATES as $key) {
            $rules[$key] = $key === 'final_at' ? 'required|date' : 'nullable|date';
        }
        $data = Validator::make($input, $rules)->validate();
        foreach (self::DATES as $key) {
            $value = $data[$key] ?? null;
            // API callers must supply an offset. UI converts Cairo local input explicitly.
            abort_if($value && ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value), 422, 'يلزم توقيت واضح مع فرق UTC.');
            $data[$key] = $value ? CarbonImmutable::parse($value)->utc()->toIso8601String() : null;
        }
        foreach (['technical_at', 'financial_at', 'clarification_at', 'site_visit_at'] as $key) {
            abort_if($data[$key] && $data[$key] > $data['final_at'], 422, 'الموعد يتجاوز الموعد النهائي.');
        }
        abort_if($data['opening_at'] && $data['opening_at'] < $data['final_at'], 422, 'فتح المظاريف قبل نهاية التقديم.');

        return $data;
    }

    public function save(int $actor, ?int $id, int $version, string $key, array $input): int
    {
        $d = Validator::make($input, ['external_reference' => 'required|string|max:150', 'title' => 'required|string|max:250', 'description' => 'nullable|string|max:10000', 'issuer' => 'required|string|max:250', 'customer_id' => 'nullable|integer|min:1', 'category' => 'required|in:supply,project,service,mixed', 'branch_id' => 'required|integer|min:1', 'responsible_id' => 'required|integer|min:1', 'published_on' => 'required|date_format:Y-m-d', 'requirements' => 'nullable|string|max:10000', 'deadlines' => 'required|array'])->validate();
        Validator::make(compact('key'), ['key' => 'required|uuid'])->validate();
        foreach (['description', 'requirements'] as $field) {
            $d[$field] ??= '';
        }
        $d['customer_id'] = empty($d['customer_id']) ? null : (int) $d['customer_id'];
        $d['branch_id'] = (int) $d['branch_id'];
        $d['responsible_id'] = (int) $d['responsible_id'];
        $d['external_reference'] = trim($d['external_reference']);
        $d['issuer'] = trim($d['issuer']);
        $d['deadlines'] = $this->dates($d['deadlines']);
        abort_if(substr($d['deadlines']['final_at'], 0, 10) < $d['published_on'], 422);

        return DB::transaction(function () use ($actor, $id, $version, $key, $d) {
            $this->lock();
            app(TenderAccess::class)->authorize($actor, $d['branch_id'], 'manage');
            app(TenderAccess::class)->authorize($actor, $d['branch_id']);
            $hash = self::hash($d);
            if (! $id && ($old = DB::table('project_tenders')->where('creation_key', $key)->first())) {
                abort_unless($old->created_by === $actor && hash_equals($old->creation_hash, $hash), 409, 'إعادة إرسال مختلفة.');

                return $old->id;
            }
            $users = app(OrganizationApi::class)->projectAssignees($actor, $d['branch_id']);
            abort_unless(in_array($d['responsible_id'], array_column($users, 'id'), true), 422, 'المسؤول غير نشط أو خارج الفرع.');
            $customer = $d['customer_id'] ? app(CustomersApi::class)->getCustomer(new GetCustomerRequest($actor, $d['customer_id'])) : null;
            abort_if($customer && $customer->status !== 'active', 422);
            $data = $d;
            $data['deadlines'] = json_encode($d['deadlines'], JSON_THROW_ON_ERROR);
            $data['customer_name'] = $customer?->name;
            if ($id) {
                $t = $this->work($actor, $id, $version);
                abort_unless(in_array($t->status, ['identified', 'under_review']) && ! $t->technical_id, 409, 'تعديل البيانات قبل إعداد العطاء فقط؛ استخدم تمديد الموعد أو إعادة التقييم.');
                foreach (['branch_id', 'customer_id', 'external_reference', 'issuer'] as $field) {
                    abort_unless($data[$field] === $t->$field, 409, 'هوية المصدر ثابتة.');
                }
                $this->touch($actor, $t, 'tender.updated', ['title' => $d['title']], $data);

                return $id;
            }
            $identity = self::hash([mb_strtolower(preg_replace('/\s+/u', ' ', $d['issuer'])), mb_strtolower(preg_replace('/\s+/u', ' ', $d['external_reference']))]);
            abort_if(DB::table('project_tenders')->where('identity_hash', $identity)->exists(), 409, 'المناقصة مسجلة بالفعل لنفس الجهة والمرجع.');
            $number = app(OfficialDocuments::class)->nextOperationalReference('tender', now('Africa/Cairo')->toDateString());
            $id = DB::table('project_tenders')->insertGetId($data + ['number' => $number, 'creation_key' => $key, 'creation_hash' => $hash, 'identity_hash' => $identity, 'created_by' => $actor]);
            DB::table('project_tender_events')->insert(['tender_id' => $id, 'actor_id' => $actor, 'action' => 'tender.created', 'snapshot' => json_encode(['number' => $number])]);
            app(RecordAudit::class)->execute($actor, 'tender', $id, 'tender.created', null, ['number' => $number], 'succeeded', 'Projects');

            return $id;
        }, 5);
    }

    public function transition(int $actor, int $id, int $version, string $state, string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $state, $reason) {
            $t = $this->work($actor, $id, $version);
            $allowed = ['identified' => ['under_review', 'cancelled'], 'under_review' => ['preparing_technical', 'no_bid', 'cancelled'], 'preparing_technical' => ['withdrawn', 'cancelled'], 'preparing_financial' => ['withdrawn', 'cancelled'], 'internal_approval' => ['withdrawn', 'cancelled'], 'submitted' => ['awaiting_result', 'withdrawn'], 'awaiting_result' => ['withdrawn']];
            abort_unless(in_array($state, $allowed[$t->status] ?? [], true), 409, 'انتقال غير مسموح.');
            $this->touch($actor, $t, 'tender.'.$state, ['from' => $t->status, 'reason' => $reason], ['status' => $state]);
        }, 5);
    }

    public function event(int $actor, int $id, int $version, string $kind, string $reference, ?int $document = null, ?array $deadlines = null): void
    {
        Validator::make(compact('kind', 'reference'), ['kind' => 'required|in:clarification,addendum,deadline_extension,reopen', 'reference' => 'required|string|min:5|max:5000'])->validate();
        DB::transaction(function () use ($actor, $id, $version, $kind, $reference, $document, $deadlines) {
            $t = $this->work($actor, $id, $version);
            $changes = [];
            if ($document) {
                app(TenderDocuments::class)->evidence($t->id, $document);
            }
            if ($kind === 'deadline_extension') {
                abort_unless($document && ! in_array($t->status, self::TERMINAL), 409, 'يلزم مستند التمديد وحالة مفتوحة.');
                $new = $this->dates($deadlines ?? []);
                $old = json_decode($t->deadlines, true);
                abort_unless($new['final_at'] > $old['final_at'], 422, 'التمديد يجب أن يزيد الموعد النهائي.');
                foreach (['technical_at', 'financial_at'] as $key) {
                    abort_if($old[$key] && (! $new[$key] || $new[$key] < $old[$key]), 422, 'لا يمكن تقليل موعد قائم.');
                }
                $changes['deadlines'] = json_encode($new, JSON_THROW_ON_ERROR);
            } elseif ($kind === 'reopen') {
                app(TenderAccess::class)->authorize($actor, $t->branch_id, 'award');
                abort_unless($document && $t->status !== 'identified' && ! DB::table('project_tender_handoffs')->where('tender_id', $id)->exists(), 409, 'يلزم دليل إعادة التقييم ولا يمكن تجاوز تحويل قائم.');
                $changes = ['status' => 'preparing_technical', 'technical_id' => null, 'financial_id' => null];
            } elseif ($kind === 'addendum') {
                abort_unless($document && ! in_array($t->status, self::TERMINAL), 409, 'يلزم مستند ملحق وحالة مفتوحة.');
                if ($t->submitted_id) {
                    app(TenderAccess::class)->authorize($actor, $t->branch_id, 'award');
                }
                $changes = ['status' => 'preparing_technical', 'technical_id' => null, 'financial_id' => null];
            } else {
                abort_if(in_array($t->status, self::TERMINAL), 409);
            }
            $this->touch($actor, $t, 'tender.'.$kind, ['reference' => $reference, 'document' => $document, 'deadlines' => $changes['deadlines'] ?? null], $changes);
        }, 5);
    }
}

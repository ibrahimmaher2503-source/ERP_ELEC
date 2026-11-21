<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\System\Application\RecordAudit;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PartnerAccounts
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['partner' => $message]);
    }

    public function savePartner(int $actor, ?int $id, int $version, array $input): int
    {
        $access = app(AccountingAccess::class);
        $access->authorize($actor, 'partner_view');
        if ($access->branches($actor, 'partner_manage') === [] && ! $access->allows($actor, 'account_manage')) {
            abort(403, 'صلاحية إدارة الشركاء غير متاحة.');
        }

        $d = Validator::make($input, [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'name' => ['required', 'string', 'min:2', 'max:200', 'not_regex:/\x00/'],
            'ownership_percent' => ['nullable', 'string'],
            'current_account_id' => ['required', 'integer', 'min:1'],
            'active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000', 'not_regex:/\x00/'],
        ])->validate();

        $pct = null;
        if (($d['ownership_percent'] ?? '') !== '' && $d['ownership_percent'] !== null) {
            $pct = bcadd(Decimal::value((string) $d['ownership_percent'], 2), '0', 2);
            if (bccomp($pct, '0.00', 2) < 0 || bccomp($pct, '100.00', 2) > 0) {
                $this->fail('نسبة الشراكة يجب أن تكون بين 0 و 100.');
            }
        }

        return DB::transaction(function () use ($actor, $id, $version, $d, $pct): int {
            app(AccountingActions::class)->lock();
            $old = $id ? DB::table('accounting_partners')->lockForUpdate()->find($id) : null;
            if ($id && ! $old) {
                abort(404);
            }
            if ($old && (int) $old->version !== $version) {
                $this->fail('تغير سجل الشريك من جلسة أخرى. أعد تحميل الصفحة.');
            }
            if (DB::table('accounting_partners')->where('code', $d['code'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $this->fail('كود الشريك مستخدم بالفعل.');
            }
            $accountId = (int) $d['current_account_id'];
            if (in_array($accountId, app(FinancialControlAccounts::class)->ids(), true)) {
                $this->fail('لا يمكن ربط شريك بحساب رقابي.');
            }
            $account = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->find($accountId);
            if (! $account || $account->kind !== 'equity') {
                $this->fail('حساب جاري الشريك يجب أن يكون حساب حقوق ملكية نشطاً وقابلاً للترحيل.');
            }
            if (DB::table('accounting_partners')->where('current_account_id', $accountId)->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                $this->fail('الحساب المحاسبي المختار مربوط بشريك آخر؛ يلزم حساب جاري مستقل لكل شريك.');
            }
            if ($old && (int) $old->current_account_id !== $accountId && DB::table('accounting_partner_movements')->where('partner_id', $id)->exists()) {
                $this->fail('لا يجوز تغيير حساب جاري الشريك بعد تسجيل حركات مالية عليه.');
            }

            $values = [
                'code' => trim($d['code']),
                'name' => trim($d['name']),
                'ownership_percent' => $pct,
                'current_account_id' => $accountId,
                'active' => (bool) $d['active'],
                'notes' => ($d['notes'] ?? '') !== '' ? trim((string) $d['notes']) : null,
                'version' => $old ? $version + 1 : 1,
                'updated_at' => now(),
            ];
            if ($old) {
                DB::table('accounting_partners')->where('id', $id)->update($values);
            } else {
                $id = DB::table('accounting_partners')->insertGetId($values + ['created_by' => $actor, 'created_at' => now()]);
            }

            app(RecordAudit::class)->execute($actor, 'accounting_partner', $id, 'partner.saved', $old ? (array) $old : null, $values, 'succeeded', 'Accounting');

            return $id;
        }, 5);
    }

    public function createPartnerEquityAccount(int $actor, string $partnerName): int
    {
        $name = trim($partnerName);
        if (mb_strlen($name) < 2) {
            $this->fail('أدخل اسم الشريك أولاً لإنشاء حساب جاري باسمه.');
        }
        $actions = app(AccountingActions::class);
        app(AccountingAccess::class)->authorize($actor, 'account_manage');

        return DB::transaction(function () use ($actor, $name, $actions): int {
            $actions->lock();
            $parentId = DB::table('accounting_accounts')
                ->where('kind', 'equity')
                ->where('postable', false)
                ->where('active', true)
                ->where(fn ($q) => $q->where('code', '3200')->orWhere('name', 'like', '%جاري الشركاء%'))
                ->orderBy('code')
                ->value('id');

            if (! $parentId && ! DB::table('accounting_accounts')->where('code', '3200')->exists()) {
                $parentId = $actions->account($actor, null, 0, [
                    'code' => '3200',
                    'name' => 'جاري الشركاء',
                    'kind' => 'equity',
                    'parent_id' => null,
                    'postable' => false,
                    'active' => true,
                ]);
            }

            $usedCodes = DB::table('accounting_accounts')->pluck('code')->all();
            $num = 3201;
            while (in_array((string) $num, $usedCodes, true)) {
                $num++;
            }

            return $actions->account($actor, null, 0, [
                'code' => (string) $num,
                'name' => mb_substr('جاري الشريك — '.$name, 0, 200),
                'kind' => 'equity',
                'parent_id' => $parentId ? (int) $parentId : null,
                'postable' => true,
                'active' => true,
            ]);
        }, 5);
    }

    public function recordMovement(int $actor, array $input): array
    {
        $d = Validator::make($input, [
            'creation_key' => ['required', 'uuid'],
            'partner_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:contribution,drawing,expense_paid_by_partner'],
            'movement_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string'],
            'reference' => ['required', 'string', 'min:3', 'max:300', 'not_regex:/\x00/'],
            'notes' => ['nullable', 'string', 'max:2000', 'not_regex:/\x00/'],
            'treasury_account_id' => ['nullable', 'integer', 'min:1'],
            'expense_account_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $amount = bcadd(Decimal::value($d['amount'], 2), '0', 2);
        if (bccomp($amount, '0', 2) <= 0) {
            $this->fail('مبلغ الحركة يجب أن يكون أكبر من صفر.');
        }

        return DB::transaction(function () use ($actor, $d, $amount): array {
            app(AccountingActions::class)->lock();
            app(AccountingAccess::class)->authorize($actor, 'partner_manage', (int) $d['branch_id']);

            $existing = DB::table('accounting_partner_movements')->where('creation_key', $d['creation_key'])->first();
            if ($existing) {
                abort_unless(
                    (int) $existing->partner_id === (int) $d['partner_id']
                    && (int) $existing->branch_id === (int) $d['branch_id']
                    && $existing->kind === $d['kind']
                    && bccomp($existing->amount, $amount, 2) === 0
                    && $existing->created_by === $actor,
                    409,
                    'مفتاح العملية مستخدم لحركة مختلفة.'
                );

                return [
                    'id' => (int) $existing->id,
                    'journal_id' => (int) $existing->journal_id,
                    'treasury_movement_id' => $existing->treasury_movement_id === null ? null : (int) $existing->treasury_movement_id,
                ];
            }

            $partner = DB::table('accounting_partners')->lockForUpdate()->find((int) $d['partner_id']);
            if (! $partner || ! $partner->active) {
                $this->fail('اختر شريكاً نشطاً.');
            }
            $partnerAccount = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->find((int) $partner->current_account_id);
            if (! $partnerAccount || $partnerAccount->kind !== 'equity' || in_array((int) $partnerAccount->id, app(FinancialControlAccounts::class)->ids(), true)) {
                $this->fail('حساب جاري الشريك غير متاح للترحيل.');
            }

            $treasuryMovementId = null;
            $treasuryAccountId = null;
            $expenseAccountId = null;
            $officialDocId = null;

            if (in_array($d['kind'], ['contribution', 'drawing'], true)) {
                if (empty($d['treasury_account_id'])) {
                    $this->fail('اختر حساب البنك أو الخزينة لهذه الحركة.');
                }
                $treasuryAccountId = (int) $d['treasury_account_id'];
                $tre = app(TreasuryApi::class)->recordPartnerMovement($actor, [
                    'idempotency_key' => $d['creation_key'],
                    'branch_id' => (int) $d['branch_id'],
                    'account_id' => $treasuryAccountId,
                    'partner_gl_account_id' => (int) $partner->current_account_id,
                    'partner_name' => $partner->name,
                    'kind' => 'partner_'.$d['kind'],
                    'date' => $d['movement_date'],
                    'amount' => $amount,
                    'reference' => trim($d['reference']),
                ]);
                $journalId = (int) $tre['journal_id'];
                $treasuryMovementId = (int) $tre['movement_id'];
                $officialDocId = $tre['official_document_id'] ?? null;
            } else {
                if (empty($d['expense_account_id'])) {
                    $this->fail('اختر حساب المصروف التشغيلي الذي دفعه الشريك.');
                }
                $expenseAccountId = (int) $d['expense_account_id'];
                if (in_array($expenseAccountId, app(FinancialControlAccounts::class)->ids(), true)) {
                    $this->fail('لا يجوز تحميل مصروف شريك على حساب رقابي.');
                }
                $expenseAccount = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->find($expenseAccountId);
                if (! $expenseAccount || $expenseAccount->kind !== 'expense') {
                    $this->fail('حساب المصروف يجب أن يكون حساب مصروفات نشطاً وقابلاً للترحيل.');
                }
                $journalId = app(AccountingApi::class)->postAccountJournal(
                    $actor,
                    $actor,
                    (int) $d['branch_id'],
                    $d['movement_date'],
                    'partner-expense:'.$d['creation_key'],
                    'مصروف مدفوع من الشريك '.$partner->name.' — '.trim($d['reference']),
                    [
                        ['account_id' => $expenseAccountId, 'debit' => $amount, 'credit' => '0.00'],
                        ['account_id' => (int) $partner->current_account_id, 'debit' => '0.00', 'credit' => $amount],
                    ]
                );
                $officialDocId = DB::table('accounting_journals')->where('id', $journalId)->value('official_document_id');
            }

            $id = DB::table('accounting_partner_movements')->insertGetId([
                'creation_key' => $d['creation_key'],
                'partner_id' => (int) $partner->id,
                'branch_id' => (int) $d['branch_id'],
                'kind' => $d['kind'],
                'movement_date' => $d['movement_date'],
                'amount' => $amount,
                'reference' => trim($d['reference']),
                'notes' => ($d['notes'] ?? '') !== '' ? trim((string) $d['notes']) : null,
                'expense_account_id' => $expenseAccountId,
                'treasury_account_id' => $treasuryAccountId,
                'treasury_movement_id' => $treasuryMovementId,
                'journal_id' => $journalId,
                'official_document_id' => $officialDocId,
                'reversed_movement_id' => null,
                'created_by' => $actor,
                'created_at' => now(),
            ]);

            app(RecordAudit::class)->execute($actor, 'accounting_partner_movement', $id, 'partner_movement.recorded', null, [
                'partner_id' => (int) $partner->id,
                'kind' => $d['kind'],
                'amount' => $amount,
                'journal_id' => $journalId,
                'treasury_movement_id' => $treasuryMovementId,
            ], 'succeeded', 'Accounting');

            return ['id' => $id, 'journal_id' => $journalId, 'treasury_movement_id' => $treasuryMovementId];
        }, 5);
    }

    public function reverseMovement(int $actor, int $movementId, string $date, string $creationKey, string $reason): array
    {
        $d = Validator::make(compact('date', 'creationKey', 'reason'), [
            'date' => ['required', 'date_format:Y-m-d'],
            'creationKey' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:5', 'max:2000', 'not_regex:/\x00/'],
        ])->validate();

        return DB::transaction(function () use ($actor, $movementId, $d): array {
            app(AccountingActions::class)->lock();
            $existing = DB::table('accounting_partner_movements')->where('creation_key', $d['creationKey'])->first();
            if ($existing) {
                abort_unless((int) $existing->reversed_movement_id === $movementId && (int) $existing->created_by === $actor, 409);

                return [
                    'id' => (int) $existing->id,
                    'journal_id' => (int) $existing->journal_id,
                    'treasury_movement_id' => $existing->treasury_movement_id === null ? null : (int) $existing->treasury_movement_id,
                ];
            }

            $orig = DB::table('accounting_partner_movements')->lockForUpdate()->find($movementId);
            abort_unless($orig && $orig->kind !== 'reversal', 404);
            app(AccountingAccess::class)->authorize($actor, 'partner_manage', (int) $orig->branch_id);

            if (DB::table('accounting_partner_movements')->where('reversed_movement_id', $orig->id)->exists()) {
                $this->fail('حركة الشريك معكوسة بالفعل بسجل عكسي مرتبط.');
            }
            if ($d['date'] < $orig->movement_date) {
                $this->fail('تاريخ العكس لا يسبق تاريخ الحركة الأصلية.');
            }

            $partner = DB::table('accounting_partners')->lockForUpdate()->find((int) $orig->partner_id);
            abort_unless($partner, 404);

            $treasuryMovementId = null;
            $treasuryAccountId = null;
            $expenseAccountId = null;

            if ($orig->treasury_movement_id !== null) {
                $corr = app(TreasuryApi::class)->correctMovement($actor, (int) $orig->treasury_movement_id, $d['date'], $d['creationKey'], trim($d['reason']));
                $journalId = (int) $corr['journal_id'];
                $treasuryMovementId = (int) $corr['movement_id'];
                $treasuryAccountId = (int) $orig->treasury_account_id;
                $officialDocId = DB::table('treasury_movements')->where('id', $treasuryMovementId)->value('official_document_id');
            } else {
                $selfApproved = (int) $orig->created_by === $actor;
                if ($selfApproved && ! app(OrganizationApi::class)->allowsSelfApproval($actor, 'accounting')) {
                    $this->fail('عكس مصروف الشريك يتطلب مراجعاً مستقلاً أو تفعيل سياسة الاعتماد الذاتي.');
                }
                $expenseAccountId = (int) $orig->expense_account_id;
                $journalId = app(AccountingApi::class)->postAccountJournal(
                    $actor,
                    $actor,
                    (int) $orig->branch_id,
                    $d['date'],
                    'partner-expense-reversal:'.$orig->id,
                    'عكس مصروف مدفوع من الشريك '.$partner->name.': '.trim($d['reason']),
                    [
                        ['account_id' => (int) $partner->current_account_id, 'debit' => $orig->amount, 'credit' => '0.00'],
                        ['account_id' => $expenseAccountId, 'debit' => '0.00', 'credit' => $orig->amount],
                    ]
                );
                $officialDocId = DB::table('accounting_journals')->where('id', $journalId)->value('official_document_id');
            }

            $id = DB::table('accounting_partner_movements')->insertGetId([
                'creation_key' => $d['creationKey'],
                'partner_id' => (int) $orig->partner_id,
                'branch_id' => (int) $orig->branch_id,
                'kind' => 'reversal',
                'movement_date' => $d['date'],
                'amount' => $orig->amount,
                'reference' => 'عكس #'.$orig->id.': '.trim($d['reason']),
                'notes' => null,
                'expense_account_id' => $expenseAccountId,
                'treasury_account_id' => $treasuryAccountId,
                'treasury_movement_id' => $treasuryMovementId,
                'journal_id' => $journalId,
                'official_document_id' => $officialDocId,
                'reversed_movement_id' => (int) $orig->id,
                'created_by' => $actor,
                'created_at' => now(),
            ]);

            app(RecordAudit::class)->execute($actor, 'accounting_partner_movement', $id, 'partner_movement.reversed', null, [
                'reversed_movement_id' => (int) $orig->id,
                'partner_id' => (int) $orig->partner_id,
                'amount' => $orig->amount,
                'journal_id' => $journalId,
            ], 'succeeded', 'Accounting');

            return ['id' => $id, 'journal_id' => $journalId, 'treasury_movement_id' => $treasuryMovementId];
        }, 5);
    }

    public function summary(int $actor, ?int $branchId = null, ?int $partnerId = null, ?string $from = null, ?string $to = null): array
    {
        $access = app(AccountingAccess::class);
        $access->authorize($actor, 'partner_view');

        $allowedBranchIds = array_unique(array_merge(
            array_column($access->branches($actor, 'report_view'), 'id'),
            array_column($access->branches($actor, 'journal_view'), 'id'),
            array_column($access->branches($actor, 'partner_manage'), 'id')
        ));
        if ($branchId !== null && ! in_array($branchId, $allowedBranchIds, true)) {
            abort(403, 'نطاق الفرع غير متاح.');
        }
        $filterBranches = $branchId !== null ? [$branchId] : $allowedBranchIds;

        $partners = DB::table('accounting_partners as p')
            ->join('accounting_accounts as a', 'a.id', '=', 'p.current_account_id')
            ->orderBy('p.code')
            ->get(['p.*', 'a.code as account_code', 'a.name as account_name']);

        $allMovements = $filterBranches === [] ? collect() : DB::table('accounting_partner_movements as m')
            ->leftJoin('accounting_partners as p', 'p.id', '=', 'm.partner_id')
            ->leftJoin('accounting_accounts as ea', 'ea.id', '=', 'm.expense_account_id')
            ->leftJoin('treasury_accounts as ta', 'ta.id', '=', 'm.treasury_account_id')
            ->whereIn('m.branch_id', $filterBranches)
            ->when($partnerId !== null, fn ($q) => $q->where('m.partner_id', $partnerId))
            ->when($from, fn ($q) => $q->where('m.movement_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('m.movement_date', '<=', $to))
            ->orderByDesc('m.movement_date')
            ->orderByDesc('m.id')
            ->get([
                'm.*',
                'p.name as partner_name',
                'p.code as partner_code',
                'ea.code as expense_code',
                'ea.name as expense_name',
                'ta.name as treasury_name',
            ]);

        $reversedKinds = DB::table('accounting_partner_movements')
            ->whereIn('id', $allMovements->pluck('reversed_movement_id')->filter()->all())
            ->pluck('kind', 'id');
        $alreadyReversed = DB::table('accounting_partner_movements')
            ->whereNotNull('reversed_movement_id')
            ->pluck('reversed_movement_id')
            ->flip()
            ->all();

        $perPartner = [];
        foreach ($partners as $p) {
            $perPartner[(int) $p->id] = [
                'partner' => $p,
                'contributions' => '0.00',
                'expenses_paid' => '0.00',
                'drawings' => '0.00',
                'net_balance' => '0.00',
            ];
        }

        foreach ($allMovements as $m) {
            $pid = (int) $m->partner_id;
            if (! isset($perPartner[$pid])) {
                continue;
            }
            $effectiveKind = $m->kind === 'reversal' ? ($reversedKinds[$m->reversed_movement_id] ?? null) : $m->kind;
            $sign = $m->kind === 'reversal' ? -1 : 1;
            $delta = $sign === 1 ? $m->amount : bcmul($m->amount, '-1', 2);

            if ($effectiveKind === 'contribution') {
                $perPartner[$pid]['contributions'] = bcadd($perPartner[$pid]['contributions'], $delta, 2);
            } elseif ($effectiveKind === 'expense_paid_by_partner') {
                $perPartner[$pid]['expenses_paid'] = bcadd($perPartner[$pid]['expenses_paid'], $delta, 2);
            } elseif ($effectiveKind === 'drawing') {
                $perPartner[$pid]['drawings'] = bcadd($perPartner[$pid]['drawings'], $delta, 2);
            }
        }

        $totals = ['contributions' => '0.00', 'expenses_paid' => '0.00', 'drawings' => '0.00', 'net_balance' => '0.00'];
        foreach ($perPartner as &$row) {
            $row['net_balance'] = bcsub(bcadd($row['contributions'], $row['expenses_paid'], 2), $row['drawings'], 2);
            $totals['contributions'] = bcadd($totals['contributions'], $row['contributions'], 2);
            $totals['expenses_paid'] = bcadd($totals['expenses_paid'], $row['expenses_paid'], 2);
            $totals['drawings'] = bcadd($totals['drawings'], $row['drawings'], 2);
            $totals['net_balance'] = bcadd($totals['net_balance'], $row['net_balance'], 2);
        }
        unset($row);

        return [
            'partners' => $partners,
            'per_partner' => array_values($perPartner),
            'totals' => $totals,
            'movements' => $allMovements->map(function ($m) use ($alreadyReversed) {
                $m->is_reversed = isset($alreadyReversed[(int) $m->id]);

                return $m;
            }),
        ];
    }
}

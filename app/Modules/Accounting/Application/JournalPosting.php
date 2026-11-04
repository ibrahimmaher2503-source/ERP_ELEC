<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\System\Application\Contracts\OfficialDocuments;
use App\Modules\Treasury\Application\Contracts\TreasuryApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class JournalPosting
{
    public function reverseTreasury(int $actor, int $movement, string $date, string $key, string $reason): int
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        Validator::make(compact('date', 'key', 'reason'), ['date' => 'required|date_format:Y-m-d', 'key' => 'required|uuid', 'reason' => 'required|string|min:5|max:2000'])->validate();
        app(AccountingActions::class)->lock();
        $source = app(TreasuryApi::class)->cashCorrectionSource($actor, $movement, $date);
        app(AccountingAccess::class)->authorize($actor, 'source_post', $source['branch_id']);
        app(FinancialActivation::class)->requirePostingDate($date);
        $original = DB::table('accounting_journals')->lockForUpdate()->find($source['journal_id']);
        abort_unless($original && $original->status === 'posted' && (int) $original->branch_id === $source['branch_id'] && $original->source_key === $source['source_key'], 409);
        $reversalKey = 'treasury-correction:'.$source['canonical_movement_id'];
        $existing = DB::table('accounting_journals')->where('source_key', $reversalKey)->first();
        if ($existing) {
            abort_unless($existing->status === 'posted' && $existing->journal_date === $date && (int) $existing->created_by === $actor, 409);

            return (int) $existing->id;
        }
        $lines = DB::table('accounting_journal_lines')->where('journal_id', $original->id)->orderBy('id')->get()
            ->map(fn ($line) => ['account_id' => (int) $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit, 'reference' => $reason])->all();

        return $this->source($actor, $actor, $actor, $source['branch_id'], $date, $reversalKey, 'تصحيح حركة خزينة #'.$source['canonical_movement_id'].': '.$reason, $lines, null, false);
    }

    public function manual(int $actor, object $journal): void
    {
        app(AccountingAccess::class)->authorize($actor, 'journal_post', $journal->branch_id);
        $project = DB::table('accounting_project_dimensions')->where('journal_id', $journal->id)->value('project_id');
        if ($project !== null) {
            app(ProjectsApi::class)->authorizeFinancialSource($actor, (int) $project, (int) $journal->branch_id);
        }
        app(FinancialActivation::class)->requirePostingDate($journal->journal_date);
        if ($journal->status !== 'approved') {
            FinancialActivation::fail('يلزم اعتماد القيد أولًا.');
        }
        app(FinancialControlAccounts::class)->assertJournal((int) $journal->id);
        $this->finish($actor, $journal->id);
    }

    public function opening(int $actor, array $batch, int $branch, string $date, array $lines): int
    {
        return $this->source($actor, $batch['created_by'], $batch['approved_by'], $branch, $date,
            'opening:'.$batch['id'].':'.$branch, 'أرصدة افتتاحية معتمدة #'.$batch['id'], $lines, $batch['id']);
    }

    public function receipt(int $actor, array $source): ?int
    {
        app(AccountingAccess::class)->authorize($actor, 'receipt_post', $source['receipt']['branch_id']);
        app(FinancialActivation::class)->requirePostingDate($source['receipt']['receipt_date']);
        $maps = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose');
        $amount = $source['total'];
        if (bccomp($amount, '0', 2) === 0) {
            return null;
        }

        return $this->source($actor, $source['receipt']['created_by'], $actor, $source['receipt']['branch_id'],
            $source['receipt']['receipt_date'], 'purchase-receipt:'.$source['receipt']['id'], 'استلام مخزون وGRNI #'.$source['receipt']['id'],
            [['account_id' => $maps['inventory'], 'debit' => $amount, 'credit' => '0.00'], ['account_id' => $maps['grni'], 'debit' => '0.00', 'credit' => $amount]]);
    }

    public function inventoryExpense(int $actor, array $source): ?int
    {
        app(AccountingAccess::class)->authorize($actor, 'journal_post', $source['branch_id']);
        app(FinancialActivation::class)->requirePostingDate($source['date']);
        if (bccomp($source['amount'], '0', 2) === 0) {
            return null;
        }
        $maps = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose');

        return $this->source($actor, $source['created_by'], $source['approved_by'], $source['branch_id'], $source['date'],
            'inventory-movement:'.$source['movement_id'], 'صرف مخزون بتكلفة FIFO #'.$source['movement_id'],
            [['account_id' => $maps[$source['debit_purpose']], 'debit' => $source['amount'], 'credit' => '0.00'],
                ['account_id' => $maps[$source['credit_purpose']], 'debit' => '0.00', 'credit' => $source['amount']]], null, ! (($source['project_material'] ?? false) || ($source['maintenance_material'] ?? false) || ($source['workshop_material'] ?? false)), $source['project_id'] ?? null);
    }

    public function sourceDocument(int $actor, int $creator, int $branch, string $date, string $key, string $description, array $lines, ?int $project = null): int
    {
        abort_unless(DB::transactionLevel() > 0 && $lines !== [], 422);
        app(AccountingAccess::class)->authorize($actor, 'source_post', $branch);
        app(FinancialActivation::class)->requirePostingDate($date);
        $maps = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose');
        $resolved = [];
        foreach ($lines as $line) {
            $purpose = (string) ($line['purpose'] ?? '');
            if (! isset($maps[$purpose])) {
                FinancialActivation::fail('يلزم ربط الحساب التشغيلي: '.$purpose);
            }
            $debit = Decimal::round($line['debit'] ?? '0');
            $credit = Decimal::round($line['credit'] ?? '0');
            if (($debit === '0.00') === ($credit === '0.00')) {
                FinancialActivation::fail('كل سطر مصدر يجب أن يكون مديناً أو دائناً فقط.');
            }
            $resolved[] = ['account_id' => $maps[$purpose], 'debit' => $debit, 'credit' => $credit, 'reference' => $line['reference'] ?? $description];
        }

        return $this->source($actor, $creator, $actor, $branch, $date, $key, $description, $resolved, null, false, $project);
    }

    public function reverse(int $actor, int $journal, string $date, string $reason): int
    {
        Validator::make(compact('date', 'reason'), [
            'date' => 'required|date_format:Y-m-d',
            'reason' => 'required|string|min:5|max:2000|not_regex:/\x00/',
        ])->validate();

        return DB::transaction(function () use ($actor, $journal, $date, $reason) {
            app(AccountingActions::class)->lock();
            $original = DB::table('accounting_journals')->lockForUpdate()->find($journal);
            abort_unless($original && $original->status === 'posted', 404);
            abort_if(str_starts_with((string) $original->source_key, 'journal-reversal:'), 409, 'لا يمكن عكس قيد عكسي.');
            if ($original->source_key !== null) {
                FinancialActivation::fail('قيود المصدر تعكس من إجراء المالك المرتبط حتى تبقى الذمم والمخزون والخزينة متطابقة.');
            }
            app(AccountingAccess::class)->authorize($actor, 'journal_post', (int) $original->branch_id);
            app(FinancialControlAccounts::class)->assertJournal($journal);
            app(FinancialActivation::class)->requirePostingDate($date);
            $key = 'journal-reversal:'.$original->id;
            $existing = DB::table('accounting_journals')->where('source_key', $key)->first();
            if ($existing) {
                return (int) $existing->id;
            }
            $lines = DB::table('accounting_journal_lines')->where('journal_id', $original->id)->orderBy('id')->get()
                ->map(fn ($line) => ['account_id' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit,
                    'reference' => 'Reversal of '.$original->official_number.': '.$reason])->all();
            $id = $this->source($actor, $actor, $actor, (int) $original->branch_id, $date, $key,
                'Reversal of '.$original->official_number.': '.$reason, $lines, null, false, DB::table('accounting_project_dimensions')->where('journal_id', $original->id)->value('project_id'));
            app(FinancialActivation::class)->audit($actor, $id, 'journal.reversed', (array) $original,
                ['original_journal_id' => $original->id, 'reversal_journal_id' => $id, 'reason' => $reason]);

            return $id;
        }, 5);
    }

    private function source(int $actor, int $creator, int $approver, int $branch, string $date, string $key, string $description, array $lines, ?int $opening = null, bool $independentApproval = true, ?int $project = null): int
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        if ($independentApproval && $creator === $approver) {
            FinancialActivation::fail('الاعتماد الذاتي غير مسموح للترحيل من المصدر.');
        }
        $existing = DB::table('accounting_journals')->where('source_key', $key)->first();
        if ($existing) {
            abort_unless($existing->status === 'posted', 409);

            return $existing->id;
        }
        $id = DB::table('accounting_journals')->insertGetId(['creation_key' => (string) Str::uuid(), 'branch_id' => $branch, 'journal_date' => $date,
            'description' => $description, 'created_by' => $creator, 'source_key' => $key, 'opening_batch_id' => $opening, 'created_at' => now(), 'updated_at' => now()]);
        if ($project !== null) {
            app(ProjectsApi::class)->authorizeFinancialSource($actor, $project, $branch);
            DB::table('accounting_project_dimensions')->insert(['journal_id' => $id, 'project_id' => $project]);
        }
        foreach ($lines as $line) {
            $account = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->find($line['account_id']);
            if (! $account) {
                FinancialActivation::fail('الحساب المرتبط غير متاح للترحيل.');
            }
            DB::table('accounting_journal_lines')->insert(['journal_id' => $id, 'account_id' => $account->id, 'account_code' => $account->code,
                'account_name' => $account->name, 'debit' => Decimal::round($line['debit']), 'credit' => Decimal::round($line['credit']), 'description' => $line['reference'] ?? $description]);
        }
        DB::table('accounting_journals')->where('id', $id)->update(['status' => 'submitted', 'version' => 2]);
        DB::table('accounting_journals')->where('id', $id)->update(['status' => 'approved', 'version' => 3, 'approved_by' => $approver, 'approved_at' => now()]);
        $this->finish($actor, $id);

        return $id;
    }

    private function finish(int $actor, int $id): void
    {
        app(AccountingActions::class)->lock();
        $journal = DB::table('accounting_journals')->lockForUpdate()->find($id);
        $period = DB::table('accounting_periods')->where('status', 'open')->where('starts_on', '<=', $journal->journal_date)->where('ends_on', '>=', $journal->journal_date)->lockForUpdate()->first();
        if (! $period) {
            FinancialActivation::fail('تاريخ القيد خارج الفترات المفتوحة.');
        }
        $lines = DB::table('accounting_journal_lines')->where('journal_id', $id)->orderBy('id')->get();
        $dr = '0.00';
        $cr = '0.00';
        $rows = [];
        foreach ($lines as $line) {
            $account = DB::table('accounting_accounts')->where('active', true)->where('postable', true)->find($line->account_id);
            if (! $account) {
                FinancialActivation::fail('حساب غير نشط أو غير قابل للترحيل.');
            }
            $dr = bcadd($dr, $line->debit, 2);
            $cr = bcadd($cr, $line->credit, 2);
            $rows[] = [$line->account_code, $line->account_name, $line->description, $line->debit, $line->credit];
        }
        if ($lines->count() < 2 || bccomp($dr, '0', 2) <= 0 || $dr !== $cr) {
            FinancialActivation::fail('القيد غير متوازن إلى القرش، لا توجد تسوية تلقائية لقيد غير متوازن.');
        }
        $document = app(OfficialDocuments::class)->issue($actor, 'journal', (string) $id, 1, $journal->journal_date,
            ['title' => 'قيد يومية', 'fields' => ['البيان' => $journal->description, 'الفرع' => (string) $journal->branch_id],
                'columns' => ['الكود', 'الحساب', 'البيان', 'مدين', 'دائن'], 'rows' => $rows, 'totals' => ['المدين' => $dr, 'الدائن' => $cr]]);
        DB::table('accounting_journals')->where('id', $id)->update(['status' => 'posted', 'period_id' => $period->id, 'official_number' => $document['number'],
            'official_document_id' => $document['id'], 'posted_by' => $actor, 'posted_at' => now(), 'version' => $journal->version + 1, 'updated_at' => now()]);
        app(FinancialActivation::class)->audit($actor, $id, 'journal.posted', null, ['journal_id' => $id, 'source_key' => $journal->source_key, 'number' => $document['number'], 'sha256' => $document['sha256'], 'debit' => $dr, 'credit' => $cr]);
    }
}

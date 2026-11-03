<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Projects\Application\Contracts\ProjectsApi;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use Illuminate\Support\Facades\DB;

final class AccountingService implements AccountingApi
{
    public function executiveSummary(int $actor, ?int $branch, string $from, string $to): array
    {
        $rows = app(FinancialReports::class)->statement($actor, 'income', $branch, $from, $to)['rows'];
        $revenue = $expenses = '0.00';
        foreach ($rows as $row) {
            if ($row->kind === 'income') {
                $revenue = bcsub($revenue, $row->balance, 2);
            } else {
                $expenses = bcadd($expenses, $row->balance, 2);
            }
        }

        return ['scope' => 'authorized_branches', 'metrics' => ['revenue' => $revenue, 'expenses' => $expenses, 'net' => bcsub($revenue, $expenses, 2)], 'basis' => 'EGP من قيود الأستاذ المرحلة بتاريخ القيد في الفترة؛ لا تجمع معها الفواتير أو التحصيلات.'];
    }

    public function lockPosting(): void
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(AccountingActions::class)->lock();
    }

    public function claimServiceExpense(int $actor, int $journal, int $branch, ?int $project, string $owner, int $source): array
    {
        abort_unless(DB::transactionLevel() > 0 && in_array($owner, ['FieldMaintenance', 'WorkshopRepairs']), 500);
        $result = $this->maintenanceExpense($actor, $journal, $branch, $project);
        $key = $owner === 'FieldMaintenance' ? 'M08.rate_approve' : 'M09.rate_approve';
        app(OrganizationApi::class)->authorize(new AuthorizeRequest($actor, $key, $branch));
        $old = DB::table('accounting_service_expense_attributions')->where('journal_id', $journal)->first();
        abort_if($old && ($old->owner !== $owner || $old->source_id !== $source), 409, 'المصروف مخصص بالفعل لخدمة أخرى.');
        if (! $old) {
            DB::table('accounting_service_expense_attributions')->insert(['journal_id' => $journal, 'owner' => $owner, 'source_id' => $source, 'created_by' => $actor]);
        }

        return $result;
    }

    public function inventorySourceProject(int $actor, int $movement, int $branch): ?int
    {
        abort_unless(DB::transactionLevel() > 0, 500);
        app(AccountingAccess::class)->authorize($actor, 'journal_post', $branch);
        $journal = DB::table('accounting_journals')->where('source_key', 'inventory-movement:'.$movement)
            ->where('branch_id', $branch)->where('status', 'posted')->first();
        abort_unless($journal, 409, 'قيد مصدر المخزون المرحل غير متاح.');
        $project = DB::table('accounting_project_dimensions')->where('journal_id', $journal->id)->value('project_id');

        return $project === null ? null : (int) $project;
    }

    public function maintenanceExpense(int $actor, int $journal, int $branch, ?int $project): array
    {
        app(AccountingAccess::class)->authorize($actor, 'journal_view', $branch);
        $j = DB::table('accounting_journals')->lockForUpdate()->find($journal);
        abort_unless($j && $j->branch_id === $branch && $j->status === 'posted' && $j->source_key === null, 422, 'اختر مصروفًا من قيد يدوي مرحل ومعتمد.');
        $dimension = DB::table('accounting_project_dimensions')->where('journal_id', $journal)->value('project_id');
        abort_unless($dimension === $project, 422, 'تخصيص المشروع لا يطابق مصدر المصروف.');
        $protected = array_merge(app(FinancialControlAccounts::class)->ids(), DB::table('accounting_account_mappings')->whereIn('purpose', ['inventory_issue', 'cogs', 'maintenance_labor'])->pluck('account_id')->all());
        abort_if(DB::table('accounting_journal_lines')->where('journal_id', $journal)->whereIn('account_id', $protected)->exists(), 422, 'مصدر رقابي أو مخزون أو أجور لا يقبل الإضافة كمصروف آخر.');
        $amount = '0.00';
        foreach (DB::table('accounting_journal_lines as l')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_id', $journal)->where('a.kind', 'expense')->get(['l.debit', 'l.credit']) as $l) {
            $amount = bcadd($amount, bcsub($l->debit, $l->credit, 2), 2);
        }
        abort_unless(bccomp($amount, '0', 2) > 0, 422);

        return ['journal_id' => $journal, 'amount' => $amount, 'number' => $j->official_number];
    }

    public function calculateTax(int $actor, string $transaction, string $date, string $mode, array $lines, string $documentDiscount = '0'): array
    {
        return app(TaxCalculator::class)->calculate($actor, $transaction, $date, $mode, $lines, $documentDiscount);
    }

    public function requirePostingDate(string $date): void
    {
        app(FinancialActivation::class)->requirePostingDate($date);
    }

    public function calculateTenderTax(int $actor, string $date, string $mode, array $lines, string $documentDiscount = '0'): array
    {
        return app(TaxCalculator::class)->tender($actor, $date, $mode, $lines, $documentDiscount);
    }

    public function postReceipt(int $actor, int $receipt): array
    {
        $source = app(PurchasingApi::class)->prepareReceiptPosting($actor, $receipt);
        abort_if($source['already_posted'] ?? false, 409, 'Receipt already posted.');
        $id = app(JournalPosting::class)->receipt($actor, $source);

        return ['journal_id' => $id, 'amount' => $source['total']];
    }

    public function postInventoryMovement(int $actor, int $movement): ?int
    {
        $source = app(InventoryApi::class)->costedMovement($actor, $movement);

        return app(JournalPosting::class)->inventoryExpense($actor, $source);
    }

    public function postSourceDocument(int $actor, int $creator, int $branch, string $date, string $sourceKey, string $description, array $lines, ?int $project = null): int
    {
        return app(JournalPosting::class)->sourceDocument($actor, $creator, $branch, $date, $sourceKey, $description, $lines, $project);
    }

    public function projectSummary(int $actor, int $project, int $branch): array
    {
        app(ProjectsApi::class)->authorizeFinancialView($actor, $project, $branch);
        app(AccountingAccess::class)->authorize($actor, 'journal_view', $branch);
        $rows = DB::table('accounting_project_dimensions as d')->join('accounting_journals as j', 'j.id', '=', 'd.journal_id')->join('accounting_journal_lines as l', 'l.journal_id', '=', 'j.id')->join('accounting_accounts as a', 'a.id', '=', 'l.account_id')->where('d.project_id', $project)->where('j.branch_id', $branch)->where('j.status', 'posted')->orderBy('j.id')->orderBy('l.id')->get(['j.id as journal_id', 'j.source_key', 'j.official_number', 'a.kind', 'l.account_id', 'l.debit', 'l.credit'])->map(fn ($r) => (array) $r)->all();
        $maps = DB::table('accounting_account_mappings')->pluck('account_id', 'purpose');
        $revenue = '0.00';
        $cost = '0.00';
        $material = '0.00';
        $ppv = '0.00';
        foreach ($rows as $r) {
            $amount = bcsub($r['debit'], $r['credit'], 2);
            if ($r['kind'] === 'income') {
                $revenue = bcsub($revenue, $amount, 2);
            }
            if ($r['kind'] === 'expense') {
                $cost = bcadd($cost, $amount, 2);
                if (in_array((int) $r['account_id'], [(int) ($maps['inventory_issue'] ?? 0), (int) ($maps['cogs'] ?? 0)], true)) {
                    $material = bcadd($material, $amount, 2);
                }
                if ((int) $r['account_id'] === (int) ($maps['ppv'] ?? 0)) {
                    $ppv = bcadd($ppv, $amount, 2);
                }
            }
        }

        $pending = DB::table('accounting_project_dimensions as d')->join('accounting_journals as j', 'j.id', '=', 'd.journal_id')->where('d.project_id', $project)->where('j.branch_id', $branch)->where('j.status', '<>', 'posted')->count();

        return ['recognized_revenue' => $revenue, 'recognized_cost' => $cost, 'material_cost' => $material, 'ppv' => $ppv, 'margin' => bcsub($revenue, $cost, 2), 'pending_journals' => $pending, 'rows' => $rows];
    }

    public function reversePostedJournal(int $actor, int $journal, string $date, string $reason): int
    {
        return app(JournalPosting::class)->reverse($actor, $journal, $date, $reason);
    }

    public function reverseTreasurySource(int $actor, int $movement, string $date, string $key, string $reason): int
    {
        return app(JournalPosting::class)->reverseTreasury($actor, $movement, $date, $key, $reason);
    }
}

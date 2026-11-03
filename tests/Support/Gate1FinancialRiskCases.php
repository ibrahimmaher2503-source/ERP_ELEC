<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Accounting\Application\AccountingActions;
use App\Modules\Accounting\Application\FinancialReports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Rollback-only FIN-P0 regression assertions against the source fixtures that
 * check-phase1-financial-journeys.php creates through owning actions.
 */
final class Gate1FinancialRiskCases
{
    /** @return array{result:string,assertions:int,findings:list<array<string,mixed>>} */
    public static function run(int $makerId, int $branchId, int $periodId, int $advanceAccountId, string $date): array
    {
        $findings = [];
        $assertions = 0;

        $period = DB::table('accounting_periods')->where('id', $periodId)->first();
        if (! $period || $period->status !== 'open') {
            return ['result' => 'INCOMPLETE', 'assertions' => $assertions, 'findings' => [[
                'id' => 'FIN-P0-01', 'test_result' => 'INCOMPLETE', 'reason' => 'The action-created test period is not open.',
            ]]];
        }

        // A real test actor may close periods, but has no branch report authority.
        $before = self::integrityCounts($periodId);
        DB::beginTransaction();
        try {
            $blindCloser = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
            DB::table('access_grants')->insert(['user_id' => $blindCloser->id, 'permission_code' => 'M11.period_close',
                'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            $denial = null;
            try {
                app(FinancialReports::class)->closePeriod((int) $blindCloser->id, $periodId);
            } catch (Throwable $error) {
                $denial = $error;
            }
            $after = self::integrityCounts($periodId);
            $forbidden = $denial instanceof HttpExceptionInterface && $denial->getStatusCode() === 403;
            $unchanged = $before === $after && DB::table('accounting_periods')->where('id', $periodId)->value('status') === 'open';
            $pass = $forbidden && $unchanged;
            $assertions++;
            $findings[] = ['id' => 'FIN-P0-01', 'test_result' => $pass ? 'PASS' : 'FAIL',
                'expectation' => 'Period close requires report authority for every company branch and denial leaves accounting state unchanged.',
                'denial_class' => $denial?->getMessage() ? $denial::class : null,
                'http_status' => $denial instanceof HttpExceptionInterface ? $denial->getStatusCode() : null,
                'state_unchanged' => $unchanged, 'before' => $before, 'after' => $after];
        } catch (Throwable $error) {
            $assertions++;
            $findings[] = ['id' => 'FIN-P0-01', 'test_result' => 'INCOMPLETE', 'exception_class' => $error::class,
                'error' => mb_substr($error->getMessage(), 0, 300)];
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        // Existing action-created inventory is dated after this cutoff.  Both
        // the GL and immutable FIFO projection must therefore be zero then.
        try {
            $activityAfterCutoff = DB::table('inventory_movements as m')->join('inventory_warehouses as w', 'w.id', '=', 'm.warehouse_id')
                ->where('w.branch_id', $branchId)->whereDate('m.posted_at', '>', '2000-12-31')->exists();
            $row = collect(app(FinancialReports::class)->reconciliations($makerId, $branchId, '2000-12-31'))->firstWhere('key', 'inventory');
            $pass = $activityAfterCutoff && $row !== null && bccomp((string) $row['gl'], '0', 2) === 0
                && bccomp((string) $row['source'], '0', 2) === 0 && bccomp((string) $row['difference'], '0', 2) === 0;
            $assertions++;
            $findings[] = ['id' => 'FIN-P0-02', 'test_result' => $pass ? 'PASS' : 'FAIL',
                'expectation' => 'Historical inventory GL and FIFO source values share the same inclusive cutoff.',
                'cutoff' => '2000-12-31', 'post_cutoff_source_activity_exists' => $activityAfterCutoff, 'inventory_reconciliation' => $row];
        } catch (Throwable $error) {
            $assertions++;
            $findings[] = ['id' => 'FIN-P0-02', 'test_result' => 'INCOMPLETE', 'exception_class' => $error::class,
                'error' => mb_substr($error->getMessage(), 0, 300)];
        }

        // Control-account journal entry is rejected at creation through the
        // public action; no journal, source advance, or denial audit is added.
        DB::beginTransaction();
        try {
            DB::table('access_grants')->insertOrIgnore(['user_id' => $makerId, 'permission_code' => 'M11.journal_create',
                'branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now()]);
            $income = app(AccountingActions::class)->account($makerId, null, 0, ['code' => 'G1-RISK-'.strtoupper(Str::random(8)),
                'name' => 'Synthetic FIN-P0 test expense', 'kind' => 'expense', 'parent_id' => null, 'postable' => true, 'active' => true]);
            $before = self::postingCounts();
            $rejection = null;
            try {
                app(AccountingActions::class)->journal($makerId, null, 0, (string) Str::uuid(), [
                    'branch_id' => $branchId, 'journal_date' => $date, 'description' => 'Synthetic protected customer advance control check',
                    'lines' => [
                        ['account_id' => $income, 'debit' => '125.00', 'credit' => '0.00', 'description' => 'Synthetic expense debit'],
                        ['account_id' => $advanceAccountId, 'debit' => '0.00', 'credit' => '125.00', 'description' => 'Synthetic customer advance control credit'],
                    ],
                ]);
            } catch (Throwable $error) {
                $rejection = $error;
            }
            $after = self::postingCounts();
            $controlDenied = $rejection instanceof ValidationException
                && collect($rejection->errors())->flatten()->contains(fn (string $message): bool => str_contains($message, 'الحسابات الرقابية'));
            $unchanged = $before === $after;
            $pass = $controlDenied && $unchanged;
            $assertions++;
            $findings[] = ['id' => 'FIN-P0-03', 'test_result' => $pass ? 'PASS' : 'FAIL',
                'expectation' => 'Manual journal creation rejects a mapped customer-advance control account before posting or source changes.',
                'rejected_at_creation' => $controlDenied, 'rejection_class' => $rejection ? $rejection::class : null,
                'rejection_message' => $rejection ? mb_substr($rejection->getMessage(), 0, 300) : null,
                'validation_messages' => $rejection instanceof ValidationException ? array_values(collect($rejection->errors())->flatten()->all()) : [],
                'no_posting_or_source_effects' => $unchanged, 'before' => $before, 'after' => $after];
        } catch (Throwable $error) {
            $assertions++;
            $findings[] = ['id' => 'FIN-P0-03', 'test_result' => 'INCOMPLETE', 'exception_class' => $error::class,
                'error' => mb_substr($error->getMessage(), 0, 300)];
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $statuses = array_column($findings, 'test_result');
        $result = count($findings) !== 3 || in_array('INCOMPLETE', $statuses, true) ? 'INCOMPLETE'
            : (in_array('FAIL', $statuses, true) ? 'FAIL' : 'PASS');

        return ['result' => $result, 'assertions' => $assertions, 'findings' => $findings];
    }

    /** @return array<string,int|string|null> */
    private static function integrityCounts(int $periodId): array
    {
        return ['period_status' => (string) DB::table('accounting_periods')->where('id', $periodId)->value('status'),
            'closures' => (int) DB::table('accounting_period_closures')->where('period_id', $periodId)->count(),
            ...self::postingCounts()];
    }

    /** @return array<string,int> */
    private static function postingCounts(): array
    {
        return ['journals' => (int) DB::table('accounting_journals')->count(),
            'journal_lines' => (int) DB::table('accounting_journal_lines')->count(),
            'customer_advances' => (int) DB::table('rp_customer_advances')->count(),
            'audit_entries' => (int) DB::table('audit_entries')->count()];
    }
}

<?php

namespace App\Modules\Treasury\Application;

use App\Modules\Accounting\Application\Contracts\AccountingApi;
use App\Modules\Accounting\Domain\FinancialDecimal as Decimal;
use App\Modules\System\Application\RecordAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class TreasuryStatementActions
{
    private function statement(int $actor, int $id, string $permission, ?int $version = null): object
    {
        $query = DB::table('treasury_statements');
        if (DB::transactionLevel() > 0) {
            app(AccountingApi::class)->lockPosting();
            $query->lockForUpdate();
        }
        $s = $query->find($id);
        abort_unless($s, 404);
        app(TreasuryAccess::class)->authorize($actor, $permission, (int) $s->branch_id);
        abort_unless($version === null || $version === (int) $s->version, 409, 'تغير كشف البنك؛ أعد تحميله.');

        return $s;
    }

    private function reason(string $reason): void
    {
        Validator::make(compact('reason'), ['reason' => 'required|string|min:5|max:2000|not_regex:/\x00/'])->validate();
    }

    private function audit(int $actor, object $statement, string $action, array $data): void
    {
        app(RecordAudit::class)->execute($actor, 'bank_statement', (int) $statement->id, $action, null,
            $data + ['branch_id' => $statement->branch_id, 'account_id' => $statement->account_id], 'succeeded', 'Treasury');
    }

    private function csv(UploadedFile $file, array $input): array
    {
        $stream = fopen($file->getRealPath(), 'rb');
        abort_unless($stream, 422);
        try {
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (is_array($header)) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            abort_unless($header === ['external_id', 'date', 'amount', 'reference'], 422, 'قالب البنك: external_id,date,amount,reference بالترتيب.');
            $rows = [];
            $seen = [];
            $net = '0.00';
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                abort_unless(count($row) === 4 && count($rows) < 1000, 422, 'حد الملف ألف حركة بأربعة أعمدة.');
                $d = Validator::make(array_combine($header, $row), ['external_id' => 'required|string|max:160|not_regex:/\x00/', 'date' => 'required|date_format:Y-m-d',
                    'amount' => 'required|string', 'reference' => 'required|string|max:500|not_regex:/\x00/'])->validate();
                Decimal::value($d['amount'], 2, true);
                abort_unless(bccomp($d['amount'], '0', 2) !== 0 && $d['date'] >= $input['starts_on'] && $d['date'] <= $input['ends_on'] && ! isset($seen[$d['external_id']]), 422, 'الحركة صفرية أو مكررة أو خارج فترة الكشف.');
                $seen[$d['external_id']] = true;
                $d['amount'] = bcadd($d['amount'], '0', 2);
                $net = bcadd($net, $d['amount'], 2);
                $rows[] = $d;
            }
            abort_unless($rows !== [] && bccomp(bcadd($input['opening_balance'], $net, 2), $input['closing_balance'], 2) === 0, 422, 'الرصيد الختامي الخارجي لا يساوي الافتتاح مضافًا إليه حركات الملف.');

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function import(int $actor, string $creationKey, UploadedFile $file, array $data): int
    {
        Validator::make(compact('creationKey', 'file'), ['creationKey' => 'required|uuid', 'file' => 'required|file|max:2048|mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel'])->validate();
        $d = Validator::make($data, ['branch_id' => 'required|integer|min:1', 'account_id' => 'required|integer|min:1', 'external_reference' => 'required|string|max:160|not_regex:/\x00/',
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'opening_balance' => 'required|string', 'closing_balance' => 'required|string'])->validate();
        app(TreasuryAccess::class)->authorize($actor, 'statement_manage', (int) $d['branch_id']);
        foreach (['opening_balance', 'closing_balance'] as $field) {
            $d[$field] = bcadd(Decimal::value($d[$field], 2, true), '0', 2);
        }
        $rows = $this->csv($file, $d);
        $sha = hash_file('sha256', $file->getRealPath());
        $hash = hash('sha256', json_encode($d + ['sha256' => $sha], JSON_THROW_ON_ERROR));
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $creationKey, $file, $d, $rows, $sha, $hash, &$path) {
                app(AccountingApi::class)->lockPosting();
                abort_unless(DB::table('treasury_accounts')->where('kind', 'bank')->where('id', $d['account_id'])->exists(), 422, 'يلزم حساب بنك فعلي.');
                $old = DB::table('treasury_statements')->where('creation_key', $creationKey)->orWhere(fn ($q) => $q->where('account_id', $d['account_id'])->where('branch_id', $d['branch_id'])
                    ->where(fn ($q) => $q->where('external_reference', $d['external_reference'])->orWhere('file_sha256', $sha)))->first();
                if ($old) {
                    abort_unless($old->creation_key === $creationKey && (int) $old->created_by === $actor && hash_equals($old->payload_hash, $hash), 409, 'مرجع الكشف أو مفتاحه مستخدم لمحتوى مختلف.');

                    return (int) $old->id;
                }
                $path = $file->store('treasury-statements', 'local');
                abort_unless($path, 500);
                $name = preg_replace('/[\x00-\x1f\x7f]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));
                $id = DB::table('treasury_statements')->insertGetId($d + ['creation_key' => $creationKey, 'file_path' => $path, 'file_sha256' => $sha,
                    'original_name' => mb_substr($name ?: 'statement.csv', 0, 200), 'payload_hash' => $hash, 'created_by' => $actor]);
                foreach ($rows as $row) {
                    DB::table('treasury_statement_lines')->insert(['statement_id' => $id, 'external_id' => $row['external_id'], 'transaction_date' => $row['date'], 'amount' => $row['amount'], 'reference' => $row['reference']]);
                }
                $this->audit($actor, DB::table('treasury_statements')->find($id), 'statement.imported', ['rows' => count($rows), 'file_sha256' => $sha]);

                return $id;
            });
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    private function movements(object $s)
    {
        return DB::table('treasury_movements')->where('branch_id', $s->branch_id)->where('account_id', $s->account_id)->whereBetween('movement_date', [$s->starts_on, $s->ends_on])->orderBy('id')->get();
    }

    private function signed(object $movement): string
    {
        return match ($movement->kind) {
            'customer_collection', 'transfer_in', 'supplier_credit_refund', 'cash_correction_in' => $movement->amount,
            'supplier_payment', 'transfer_out', 'customer_advance_refund', 'customer_credit_refund', 'cash_correction_out' => bcsub('0', $movement->amount, 2),
            default => abort(409, 'نوع حركة خزينة غير مدعوم للمطابقة.'),
        };
    }

    private function reconciliation(object $s, $lines, $matches, $movements): array
    {
        $external = $lines->reduce(fn ($sum, $line) => bcadd($sum, $line->amount, 2), '0.00');
        $cash = $movements->reduce(fn ($sum, $m) => bcadd($sum, $this->signed($m), 2), '0.00');

        return ['scope' => 'branch_cash_flow_only', 'external_net' => $external, 'treasury_net' => $cash, 'difference' => bcsub($external, $cash, 2),
            'unmatched_lines' => $lines->filter(fn ($line) => bccomp($this->matched($matches, 'line_id', (int) $line->id), $this->absolute($line->amount), 2) !== 0)->count(),
            'unmatched_movements' => $movements->filter(fn ($m) => bccomp($this->matched($matches, 'movement_id', (int) $m->id), $m->amount, 2) !== 0)->count()];
    }

    private function absolute(string $amount): string
    {
        return bccomp($amount, '0', 2) < 0 ? bcsub('0', $amount, 2) : bcadd($amount, '0', 2);
    }

    private function matches(int $statement)
    {
        return DB::table('treasury_statement_matches as m')->join('treasury_statement_lines as l', 'l.id', '=', 'm.line_id')
            ->where('m.statement_id', $statement)->where('m.active', true)->orderBy('m.id')
            ->selectRaw('m.*,coalesce(m.amount,abs(l.amount))::numeric(20,2) as amount')->get();
    }

    private function matched($matches, string $field, int $id): string
    {
        return $matches->where($field, $id)->reduce(fn ($sum, $match) => bcadd($sum, $match->amount, 2), '0.00');
    }

    private function allocated(string $field, int $id): string
    {
        return (string) DB::table('treasury_statement_matches as m')->join('treasury_statement_lines as l', 'l.id', '=', 'm.line_id')
            ->where('m.'.$field, $id)->where('m.active', true)->selectRaw('coalesce(sum(coalesce(m.amount,abs(l.amount))),0)::numeric(20,2) as amount')->value('amount');
    }

    public function match(int $actor, int $statement, int $line, int $movement, int $version, string $reason, ?string $amount = null): int
    {
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $statement, $line, $movement, $version, $reason, $amount) {
            $s = $this->statement($actor, $statement, 'statement_manage', $version);
            abort_unless($s->status === 'imported', 409, 'أعد فتح المراجعة قبل تغيير المطابقة.');
            $bank = DB::table('treasury_statement_lines')->where('statement_id', $statement)->find($line);
            $cash = $this->movements($s)->firstWhere('id', $movement);
            abort_unless($bank && $cash && (bccomp($bank->amount, '0', 2) > 0) === (bccomp($this->signed($cash), '0', 2) > 0), 422, 'هوية أو اتجاه الحركة لا يطابقان حساب وفترة وفرع الكشف.');
            abort_if(DB::table('treasury_statement_matches')->where('active', true)->where('line_id', $line)->where('movement_id', $movement)->exists(), 409, 'ارفع المطابقة الحالية قبل تغيير تخصيص الطرفين نفسيهما.');
            $bankRemaining = bcsub($this->absolute($bank->amount), $this->allocated('line_id', $line), 2);
            $cashRemaining = bcsub($cash->amount, $this->allocated('movement_id', $movement), 2);
            $amount = $amount === null ? (bccomp($bankRemaining, $cashRemaining, 2) <= 0 ? $bankRemaining : $cashRemaining) : bcadd(Decimal::value($amount, 2), '0', 2);
            abort_unless(bccomp($amount, '0', 2) > 0 && bccomp($amount, $bankRemaining, 2) <= 0 && bccomp($amount, $cashRemaining, 2) <= 0, 422, 'المطابقة تتجاوز المتبقي من سطر البنك أو حركة الخزينة.');
            $id = DB::table('treasury_statement_matches')->insertGetId(['statement_id' => $statement, 'line_id' => $line, 'movement_id' => $movement, 'amount' => $amount, 'reason' => $reason, 'created_by' => $actor]);
            DB::table('treasury_statements')->where('id', $statement)->update(['version' => $version + 1]);
            $this->audit($actor, $s, 'statement.matched', ['match_id' => $id, 'line_id' => $line, 'movement_id' => $movement, 'amount' => $amount, 'reason' => $reason]);

            return $id;
        }, 3);
    }

    public function unmatch(int $actor, int $statement, int $match, int $version, string $reason): void
    {
        $this->reason($reason);
        DB::transaction(function () use ($actor, $statement, $match, $version, $reason) {
            $s = $this->statement($actor, $statement, 'statement_manage', $version);
            abort_unless($s->status === 'imported', 409);
            $row = DB::table('treasury_statement_matches')->where('statement_id', $statement)->where('active', true)->find($match);
            abort_unless($row, 409);
            DB::table('treasury_statement_matches')->where('id', $match)->update(['active' => false, 'cleared_by' => $actor, 'cleared_at' => now(), 'cleared_reason' => $reason]);
            DB::table('treasury_statements')->where('id', $statement)->update(['version' => $version + 1]);
            $this->audit($actor, $s, 'statement.unmatched', ['match_id' => $match, 'reason' => $reason]);
        }, 3);
    }

    public function review(int $actor, int $statement, int $version, string $reason): int
    {
        $this->reason($reason);

        return DB::transaction(function () use ($actor, $statement, $version, $reason) {
            $s = $this->statement($actor, $statement, 'statement_review', $version);
            abort_unless($s->status === 'imported' && (int) $s->created_by !== $actor, 403, 'مراجعة الكشف مستقلة عن مستورده.');
            $lines = DB::table('treasury_statement_lines')->where('statement_id', $statement)->orderBy('id')->get();
            $matches = $this->matches($statement);
            $result = $this->reconciliation($s, $lines, $matches, $this->movements($s));
            $snapshot = json_encode(['statement_id' => $statement, 'version' => $version, 'file_sha256' => $s->file_sha256, 'lines' => $lines->all(), 'matches' => $matches->all(), 'reconciliation' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $id = DB::table('treasury_statement_reviews')->insertGetId(['statement_id' => $statement, 'version' => $version, 'reviewer_id' => $actor, 'reason' => $reason,
                'snapshot' => $snapshot, 'snapshot_sha256' => hash('sha256', $snapshot)]);
            $complete = $result['unmatched_lines'] === 0 && $result['unmatched_movements'] === 0 && bccomp($result['difference'], '0', 2) === 0;
            DB::table('treasury_statements')->where('id', $statement)->update(['status' => $complete ? 'reconciled' : 'reviewed_with_differences', 'last_review_id' => $id, 'version' => $version + 1]);
            $this->audit($actor, $s, 'statement.reviewed', ['review_id' => $id, 'snapshot_sha256' => hash('sha256', $snapshot), 'reconciliation' => $result]);

            return $id;
        }, 3);
    }

    public function reopen(int $actor, int $statement, int $version, string $reason): void
    {
        $this->reason($reason);
        DB::transaction(function () use ($actor, $statement, $version, $reason) {
            $s = $this->statement($actor, $statement, 'statement_review', $version);
            abort_unless(in_array($s->status, ['reconciled', 'reviewed_with_differences']), 409);
            DB::table('treasury_statements')->where('id', $statement)->update(['status' => 'imported', 'version' => $version + 1]);
            $this->audit($actor, $s, 'statement.reopened', ['previous_review_id' => $s->last_review_id, 'reason' => $reason]);
        }, 3);
    }

    public function view(int $actor, int $statement): array
    {
        $s = $this->statement($actor, $statement, 'statement_view');
        $lines = DB::table('treasury_statement_lines')->where('statement_id', $statement)->orderBy('id')->get();
        $matches = $this->matches($statement);
        $reviews = DB::table('treasury_statement_reviews')->where('statement_id', $statement)->orderBy('id')->get();
        foreach ($reviews as $review) {
            abort_unless(hash_equals($review->snapshot_sha256, hash('sha256', $review->snapshot)), 409, 'تغير دليل مراجعة البنك.');
        }
        $movements = $this->movements($s);
        $header = (array) $s;
        unset($header['file_path'], $header['payload_hash']);
        $result = $this->reconciliation($s, $lines, $matches, $movements);
        $lastReview = $reviews->firstWhere('id', $s->last_review_id);
        $reviewCurrent = $lastReview && json_decode($lastReview->snapshot, true, 512, JSON_THROW_ON_ERROR)['reconciliation'] === $result;

        return ['statement' => $header, 'lines' => $lines->map(fn ($r) => (array) $r + ['matched_amount' => $this->matched($matches, 'line_id', (int) $r->id), 'remaining_amount' => bcsub($this->absolute($r->amount), $this->matched($matches, 'line_id', (int) $r->id), 2)])->all(),
            'matches' => $matches->map(fn ($r) => (array) $r)->all(), 'reviews' => $reviews->map(fn ($r) => (array) $r)->all(), 'last_review_is_current' => (bool) $reviewCurrent,
            'movements' => $movements->map(fn ($r) => ['id' => $r->id, 'date' => $r->movement_date, 'amount' => $this->signed($r), 'reference' => $r->reference,
                'matched_amount' => $this->matched($matches, 'movement_id', (int) $r->id), 'remaining_amount' => bcsub($r->amount, $this->matched($matches, 'movement_id', (int) $r->id), 2)])->all(), 'reconciliation' => $result];
    }

    public function download(int $actor, int $statement): BinaryFileResponse
    {
        $s = $this->statement($actor, $statement, 'statement_view');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($s->file_path) && hash_equals($s->file_sha256, hash_file('sha256', $disk->path($s->file_path))), 409, 'ملف كشف البنك مفقود أو تغير.');

        return response()->download($disk->path($s->file_path), $s->original_name, ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}

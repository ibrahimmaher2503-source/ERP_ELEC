<?php

namespace App\Modules\System\Application\DataOperations;

use App\Modules\System\Domain\DataOperationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $runId)
    {
        $this->onQueue('data-operations');
    }

    public function handle(OperationService $service): void
    {
        $service->process($this->runId);
        $run = DataOperationRun::find($this->runId);
        if ($run?->status === 'processing') {
            self::dispatch($this->runId)->delay(1);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        DB::transaction(function () {
            $run = DataOperationRun::lockForUpdate()->find($this->runId);
            if (! $run || $run->status !== 'processing') {
                return;
            }
            $run->items()->where('status', 'pending')->update(['status' => 'failed', 'error' => 'تعذرت معالجة الدفعة؛ لم يتم تنفيذ هذا الصف.']);
            $run->update(['status' => 'completed', 'completed_at' => now()]);
            if ($run->source_path && Storage::disk('local')->delete($run->source_path)) {
                $run->update(['source_path' => null]);
            }
            app(OperationService::class)->summarize($run);
        });
    }
}

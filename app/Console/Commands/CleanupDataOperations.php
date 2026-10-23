<?php

namespace App\Console\Commands;

use App\Modules\System\Domain\DataOperationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupDataOperations extends Command
{
    protected $signature = 'erp:cleanup-data-operations';

    protected $description = 'Delete original files for expired previews and completed data operations; retain receipts';

    public function handle(): int
    {
        DataOperationRun::where(function ($query) {
            $query->where('status', 'completed')->orWhere(fn ($q) => $q->where('status', 'expired')->whereNotNull('source_path'))->orWhere(fn ($q) => $q->where('status', 'preview')->where('expires_at', '<', now()));
        })->orderBy('id')->chunk(100, function ($runs) {
            foreach ($runs as $run) {
                DB::transaction(function () use ($run) {
                    $locked = DataOperationRun::lockForUpdate()->find($run->id);
                    if (! $locked || (! in_array($locked->status, ['completed', 'expired'], true) && ($locked->status !== 'preview' || ! $locked->expires_at->isPast()))) {
                        return;
                    }
                    if ($locked->source_path && Storage::disk('local')->delete($locked->source_path)) {
                        $locked->source_path = null;
                    }
                    if ($locked->status === 'preview') {
                        $locked->status = 'expired';
                    }
                    $locked->save();
                });
            }
        });
        foreach (Storage::disk('local')->files('data-operations/tmp') as $file) {
            if (Storage::disk('local')->lastModified($file) < now()->subHour()->timestamp) {
                Storage::disk('local')->delete($file);
            }
        }
        $this->info('Expired original files removed; receipts preserved.');

        return self::SUCCESS;
    }
}

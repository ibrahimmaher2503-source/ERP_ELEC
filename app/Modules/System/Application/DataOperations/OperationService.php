<?php

namespace App\Modules\System\Application\DataOperations;

use App\Modules\System\Domain\DataOperationItem;
use App\Modules\System\Domain\DataOperationRun;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OperationService
{
    public function __construct(private EntityRegistry $registry, private WorkbookCodec $codec) {}

    public function ids(array $ids): array
    {
        Validator::make(['ids' => $ids], ['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']])->validate();

        return array_values(array_map('intval', $ids));
    }

    public function template(int $actorId, string $entity): string
    {
        $definition = $this->registry->get($entity);
        $definition->authorize($actorId, 'template');

        return $this->codec->template($entity, $definition->version(), $definition->columns(), $definition->lookups($actorId));
    }

    private function createRun(int $actorId, EntityDefinition $definition, string $kind, string $mode, ?string $filename = null, ?string $path = null): DataOperationRun
    {
        $run = new DataOperationRun;
        $run->id = (string) Str::uuid();
        $run->fill(['actor_id' => $actorId, 'entity' => $definition->id(), 'kind' => $kind, 'mode' => $mode, 'template_version' => $definition->version(), 'filename' => $filename, 'source_path' => $path, 'status' => 'preview', 'expires_at' => now()->addHour()]);
        $run->save();

        return $run;
    }

    private function error(\Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return mb_substr(implode(' ', $exception->validator->errors()->all()), 0, 2000);
        }
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403) {
            return 'تم رفض الصلاحية الحالية لهذه العملية.';
        }
        if ($exception instanceof ModelNotFoundException) {
            return 'السجل أو المرجع لم يعد متاحًا.';
        }
        if ($exception instanceof QueryException && $exception->getCode() === '23505') {
            return 'تعارض مع معرّف فريد حالي؛ لم يتم حفظ الصف.';
        }
        report($exception);

        return 'تعذر تنفيذ الصف؛ لم يتم حفظ أي تغيير عليه.';
    }

    private function addItem(DataOperationRun $run, int $number, string $identifier, callable $preview): void
    {
        try {
            $plan = $preview();
            $run->items()->create(['row_number' => $number, 'identifier' => mb_substr($plan['identifier'] ?? $identifier, 0, 200), 'status' => ($plan['skip'] ?? false) ? 'skipped' : 'valid', 'plan' => $plan, 'before_values' => $plan['before'] ?? [], 'after_values' => $plan['after'] ?? []]);
        } catch (\Throwable $exception) {
            $run->items()->create(['row_number' => $number, 'identifier' => mb_substr($identifier, 0, 200), 'status' => 'invalid', 'error' => $this->error($exception), 'result' => ['duplicate' => $exception instanceof ValidationException && array_key_exists('duplicate', $exception->errors())]]);
        }
    }

    public function previewBulk(int $actorId, string $entity, array $ids, array $changes): DataOperationRun
    {
        $definition = $this->registry->get($entity);
        $definition->authorize($actorId, 'bulk');
        $ids = $this->ids($ids);

        return DB::transaction(function () use ($actorId, $definition, $ids, $changes) {
            $run = $this->createRun($actorId, $definition, 'bulk', 'update');
            foreach ($ids as $index => $id) {
                $this->addItem($run, $index + 1, (string) $id, fn () => $definition->previewBulk($actorId, $id, $changes));
            }

            return $this->summarize($run);
        });
    }

    public function previewImport(int $actorId, string $entity, UploadedFile $file, string $mode): DataOperationRun
    {
        $definition = $this->registry->get($entity);
        $definition->authorize($actorId, 'import');
        abort_unless(in_array($mode, ['create', 'update'], true), 422);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip']])->validate();
        $path = $file->store('data-operations/sources', 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'تعذر حفظ الملف الخاص.']);
        }
        try {
            $rows = $this->codec->read(Storage::disk('local')->path($path), $entity, $definition->version(), $definition->columns());
            abort_unless(count($rows) > 0, 422, 'الملف لا يحتوي على صفوف بيانات.');

            return DB::transaction(function () use ($actorId, $definition, $rows, $path, $file, $mode) {
                $run = $this->createRun($actorId, $definition, 'import', $mode, mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), $path);
                $duplicates = [];
                foreach ($rows as $row) {
                    foreach ($definition->duplicateKeys($row['data']) as $field => $value) {
                        $duplicates[$field][$value] = ($duplicates[$field][$value] ?? 0) + 1;
                    }
                }
                foreach ($rows as $row) {
                    $keys = $definition->duplicateKeys($row['data']);
                    $identifier = (string) (reset($keys) ?: $row['row_number']);
                    $this->addItem($run, $row['row_number'], $identifier, function () use ($definition, $actorId, $row, $mode, $keys, $duplicates) {
                        foreach ($keys as $field => $value) {
                            if (($duplicates[$field][$value] ?? 0) > 1) {
                                throw ValidationException::withMessages(['duplicate' => 'معرّف فريد مكرر داخل الملف: '.$field]);
                            }
                        }

                        return $definition->previewImport($actorId, $row['data'], $mode);
                    });
                }

                return $this->summarize($run);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            if ($exception instanceof \UnexpectedValueException || $exception instanceof \LengthException) {
                throw ValidationException::withMessages(['file' => 'ملف Excel غير متوافق: '.$exception->getMessage()]);
            }
            throw $exception;
        }
    }

    public function owned(int $actorId, string $id): DataOperationRun
    {
        $run = DataOperationRun::where('actor_id', $actorId)->findOrFail($id);
        $this->registry->get($run->entity)->authorize($actorId, $run->kind);

        return $run;
    }

    public function confirm(int $actorId, string $id, bool $dispatch = true): DataOperationRun
    {
        $run = $this->owned($actorId, $id);
        DB::transaction(function () use ($run) {
            $locked = DataOperationRun::lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'preview') {
                return;
            }
            abort_if($locked->expires_at->isPast(), 422, 'انتهت صلاحية المعاينة؛ ارفع الملف أو أعد المعاينة.');
            abort_unless($locked->items()->where('status', 'valid')->exists(), 422, 'لا توجد صفوف صالحة للتنفيذ.');
            $locked->update(['status' => 'processing', 'started_at' => now()]);
            $locked->items()->where('status', 'valid')->update(['status' => 'pending']);
        });
        if ($dispatch && DataOperationRun::findOrFail($id)->status === 'processing') {
            ProcessOperation::dispatch($id)->afterCommit();
        }

        return $run->refresh();
    }

    public function process(string $id, int $limit = 50): void
    {
        $run = DataOperationRun::findOrFail($id);
        if ($run->status !== 'processing') {
            return;
        }
        $definition = $this->registry->get($run->entity);
        $itemIds = $run->items()->where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($itemIds as $itemId) {
            try {
                DB::transaction(function () use ($run, $definition, $itemId) {
                    // Serialize receipt claims before invoking owner Actions; mutation + success marker commit together.
                    $lockedRun = DataOperationRun::lockForUpdate()->findOrFail($run->id);
                    if ($lockedRun->status !== 'processing') {
                        return;
                    }
                    $item = DataOperationItem::lockForUpdate()->findOrFail($itemId);
                    if ($item->status !== 'pending') {
                        return;
                    }
                    $definition->authorize($run->actor_id, $run->kind);
                    abort_if($definition->version() !== $run->template_version, 422, 'تغير إصدار التعريف. أعد المعاينة.');
                    $result = $definition->execute($run->actor_id, $item->plan);
                    $item->update(['status' => 'succeeded', 'result' => $result]);
                });
            } catch (\Throwable $exception) {
                DataOperationItem::whereKey($itemId)->where('status', 'pending')->update(['status' => 'failed', 'error' => $this->error($exception)]);
            }
        }
        DB::transaction(function () use ($run) {
            $locked = DataOperationRun::lockForUpdate()->findOrFail($run->id);
            if (! $locked->items()->where('status', 'pending')->exists()) {
                $locked->update(['status' => 'completed', 'completed_at' => now()]);
                if ($locked->source_path && Storage::disk('local')->delete($locked->source_path)) {
                    $locked->update(['source_path' => null]);
                }
            }
            $this->summarize($locked);
        });
    }

    public function summarize(DataOperationRun $run): DataOperationRun
    {
        $counts = $run->items()->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total', 'status');
        $created = $run->items()->where('status', $run->status === 'preview' ? 'valid' : 'succeeded')->where($run->status === 'preview' ? 'plan->operation' : 'result->operation', 'create')->count();
        $succeeded = (int) ($counts['succeeded'] ?? 0);
        $duplicates = $run->items()->where('result->duplicate', true)->count();
        $run->update(['summary' => ['duplicates' => $duplicates, 'total' => $counts->sum(), 'valid' => (int) ($counts['valid'] ?? 0), 'invalid' => (int) ($counts['invalid'] ?? 0), 'pending' => (int) ($counts['pending'] ?? 0), 'succeeded' => $succeeded, 'created' => $created, 'updated' => ($run->status === 'preview' ? (int) ($counts['valid'] ?? 0) : $succeeded) - $created, 'failed' => (int) ($counts['failed'] ?? 0) + (int) ($counts['invalid'] ?? 0), 'skipped' => (int) ($counts['skipped'] ?? 0)]]);

        return $run->refresh();
    }

    public function export(int $actorId, string $entity, string $mode, array $filters = [], array $ids = []): string
    {
        $definition = $this->registry->get($entity);
        $definition->authorize($actorId, 'export');
        if ($mode === 'selected') {
            $ids = $this->ids($ids);
        }

        try {
            return $this->codec->write($entity.'-export', array_column($definition->columns(), 'label', 'key'), $definition->exportRows($actorId, $mode, $filters, $ids));
        } catch (\LengthException $exception) {
            throw ValidationException::withMessages(['export' => 'التصدير يدعم حتى 10,000 سجل؛ ضيّق الفلاتر وأعد المحاولة.']);
        }
    }

    public function report(int $actorId, string $id, bool $errorsOnly = false): string
    {
        $run = $this->owned($actorId, $id);
        $rows = $run->items()->when($errorsOnly, fn ($query) => $query->whereIn('status', ['failed', 'invalid']))->orderBy('row_number')->cursor()->map(fn ($item) => [$item->row_number, $item->identifier, $item->status, $item->error ?? '']);

        return $this->codec->write('operation-results', ['الصف', 'المعرّف', 'النتيجة', 'السبب'], $rows);
    }
}

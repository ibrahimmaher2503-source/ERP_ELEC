<?php

namespace App\Modules\System\Presentation;

use App\Modules\System\Application\DataOperations\EntityRegistry;
use App\Modules\System\Application\DataOperations\OperationService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DataOperationPage extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $entity = 'products';

    #[Locked]
    public ?string $runId = null;

    #[Locked]
    public bool $templateDownloaded = false;

    public $upload;

    public string $mode = 'create';

    public function mount(?string $entity = null, ?string $runId = null): void
    {
        $this->runId = $runId;
        // Receipt routes only bind runId. Livewire can explicitly supply null
        // for the absent entity, so resolve it from the authorized receipt.
        $this->entity = $runId !== null
            ? app(OperationService::class)->owned(auth()->id(), $runId)->entity
            : ($entity ?? 'products');
    }

    private function definition()
    {
        return app(EntityRegistry::class)->get($this->entity);
    }

    public function downloadTemplate()
    {
        $path = app(OperationService::class)->template(auth()->id(), $this->entity);
        $this->templateDownloaded = true;

        return response()->download($path, $this->entity.'-template.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
    }

    public function previewImport(): void
    {
        abort_unless($this->templateDownloaded, 422, 'حمّل القالب الرسمي أولًا.');
        $this->validate(['upload' => ['required', 'file', 'max:10240']]);
        try {
            $run = app(OperationService::class)->previewImport(auth()->id(), $this->entity, $this->upload, $this->mode);
        } finally {
            $this->upload?->delete();
            $this->upload = null;
        }
        $this->runId = $run->id;
    }

    public function execute(): void
    {
        abort_unless($this->runId, 422);
        app(OperationService::class)->confirm(auth()->id(), $this->runId);
    }

    public function downloadErrors()
    {
        return $this->download(true);
    }

    public function downloadResult()
    {
        return $this->download(false);
    }

    private function download(bool $errors)
    {
        abort_unless($this->runId, 422);

        return response()->download(app(OperationService::class)->report(auth()->id(), $this->runId, $errors), 'operation-results.xlsx', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
    }

    public function render()
    {
        $definition = $this->definition();
        $run = $this->runId ? app(OperationService::class)->owned(auth()->id(), $this->runId)->load(['items' => fn ($query) => $query->orderBy('row_number')]) : null;
        if (! $run) {
            $definition->authorize(auth()->id(), 'import');
        }
        $can = function ($capability) use ($definition) {
            try {
                $definition->authorize(auth()->id(), $capability);

                return true;
            } catch (HttpException) {
                return false;
            }
        };

        $columns = $run?->kind === 'bulk' ? collect($definition->bulkFields())->map(fn ($field, $key) => ['key' => $key, 'label' => $field['label']])->values()->all() : $definition->columns();

        return view('data-operations.workflow', ['definition' => ['id' => $definition->id(), 'label' => $definition->label(), 'version' => $definition->version(), 'columns' => $columns], 'run' => $run, 'canTemplate' => $can('template'), 'canImport' => $can('import')])->layout('layouts::app', ['title' => 'عمليات البيانات · '.$definition->label()]);
    }
}

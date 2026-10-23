<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class InventoryLineImport
{
    public function columns(): array
    {
        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label, 'required' => true, 'type' => 'text', 'allowed' => [], 'example' => '', 'description' => 'بالوحدة الأساسية فقط. الاستيراد معاينة لمسودة ولا يرحل المخزون.'], ['sku', 'quantity'], ['SKU', 'الكمية بالوحدة الأساسية']);
    }

    public function authorize(int $actor, string $kind, int $warehouse): void
    {
        app(InventoryAccess::class)->authorize($actor, app(InventoryDocuments::class)->permission($kind, 'create'), $warehouse);
    }

    public function template(int $actor, string $kind, int $warehouse): string
    {
        $this->authorize($actor, $kind, $warehouse);

        return app(WorkbookCodec::class)->template('inventory-'.$kind.'-lines', 'M06-v1', $this->columns(), []);
    }

    public function preview(int $actor, string $kind, int $warehouse, UploadedFile $file): array
    {
        $this->authorize($actor, $kind, $warehouse);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip']])->validate();
        try {
            $rows = app(WorkbookCodec::class)->read($file->getRealPath(), 'inventory-'.$kind.'-lines', 'M06-v1', $this->columns());
        } catch (\UnexpectedValueException|\LengthException $e) {
            throw ValidationException::withMessages(['importFile' => 'قالب غير صالح: '.$e->getMessage()]);
        }
        if (count($rows) < 1 || count($rows) > 100) {
            InventoryActions::fail('القالب يحتاج من ١ إلى ١٠٠ بند.');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            try {
                $d = Validator::make($row['data'], ['sku' => ['required', 'string', 'max:100', 'not_regex:/\x00/'], 'quantity' => ['required', 'string']])->validate();
                $p = app(ProductsApi::class)->inventoryProductBySku($actor, $d['sku']);
                if (! $p['active'] || $p['kind'] === 'service' || $p['tracking'] !== 'quantity' || isset($seen[$p['id']])) {
                    InventoryActions::fail('صنف غير مؤهل أو مكرر في القالب.');
                }
                $q = app(InventoryLedger::class)->quantity($d['quantity'], $kind === 'adjustment', $kind === 'count');
                $seen[$p['id']] = true;
                $out[] = ['row' => $row['row_number'], 'sku' => $p['sku'], 'name' => $p['name'], 'product_id' => $p['id'], 'quantity' => $q, 'error' => null];
            } catch (ValidationException|ModelNotFoundException $e) {
                $out[] = ['row' => $row['row_number'], 'sku' => $row['data']['sku'] ?? '', 'error' => 'الصنف أو الكمية غير صالحين أو الصنف مكرر.'];
            }
        }

        return $out;
    }
}

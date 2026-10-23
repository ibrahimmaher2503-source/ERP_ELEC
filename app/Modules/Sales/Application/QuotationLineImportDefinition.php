<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Shared workbook codec, ephemeral preview only: never submits a persisted master-data operation. */
final class QuotationLineImportDefinition
{
    public const ENTITY = 'quotation-lines';

    public const VERSION = 'M04-quotation-lines-v1';

    public function authorize(int $actor): void
    {
        app(QuotationAccess::class)->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, 'import');
    }

    public function columns(): array
    {
        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label, 'required' => true, 'type' => 'text', 'allowed' => [], 'example' => '', 'description' => 'لا تستورد الأسعار أو الخصومات. معاينة ثم إضافة للمسودة غير المحفوظة.'], ['sku', 'quantity', 'unit'], ['كود الصنف', 'الكمية', 'كود وحدة القياس']);
    }

    public function template(int $actor): string
    {
        $this->authorize($actor);
        app(QuotationAccess::class)->authorize($actor, 'template');
        $units = array_map(fn ($u) => ['code' => $u['code'], 'name' => $u['name']], app(QuotationCatalog::class)->units($actor));

        return app(WorkbookCodec::class)->template(self::ENTITY, self::VERSION, $this->columns(), ['unit' => $units]);
    }

    public function resolve(int $actor, array $row, string $date): array
    {
        $this->authorize($actor);
        Validator::make($row, ['sku' => ['required', 'string', 'max:100'], 'quantity' => ['required'], 'unit' => ['required', 'string', 'max:100']])->validate();
        if (! is_string($row['quantity']) && ! is_int($row['quantity'])) {
            throw ValidationException::withMessages(['quantity' => 'نسّق الكمية كنص عشري للحفاظ على الدقة.']);
        }
        $quantity = (string) $row['quantity'];
        DraftAmounts::decimal($quantity, 'quantity', true);
        $c = app(QuotationCatalog::class);
        $product = $c->bySku($actor, $row['sku']);
        $unit = collect($c->units($actor))->first(fn ($u) => mb_strtoupper($u['code']) === mb_strtoupper(trim($row['unit'])));
        if (! $unit) {
            throw ValidationException::withMessages(['unit' => 'كود وحدة القياس غير معروف أو غير نشط.']);
        }
        $snapshot = $c->product($actor, $product['id'], $unit['id'], $quantity);
        $price = app(QuotationPricing::class)->suggested($actor, $product['id'], $unit['id'], $date);

        return ['product_id' => $product['id'], 'unit_id' => $unit['id'], 'quantity' => $quantity, 'description' => $snapshot['name'], 'sku' => $snapshot['sku'], 'unit_code' => $snapshot['unit_code'], 'suggested_price' => $price['amount'] ?? null, 'requested_price' => null, 'discount_mode' => 'percent', 'discount_value' => '0', 'override_reason' => ''];
    }

    public function preview(int $actor, UploadedFile $file, string $date): array
    {
        $this->authorize($actor);
        Validator::make(['file' => $file, 'date' => $date], ['file' => ['required', 'file', 'max:10240', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip'], 'date' => ['required', 'date_format:Y-m-d']])->validate();
        try {
            $rows = app(WorkbookCodec::class)->read($file->getRealPath(), self::ENTITY, self::VERSION, $this->columns());
        } catch (\UnexpectedValueException|\LengthException $e) {
            throw ValidationException::withMessages(['importFile' => 'ملف غير متوافق: '.$e->getMessage()]);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['importFile' => 'القالب لا يحتوي على بنود.']);
        }
        $out = [];
        foreach ($rows as $row) {
            try {
                $line = $this->resolve($actor, $row['data'], $date);
                $out[] = ['row' => $row['row_number'], 'input' => $row['data'], 'line' => $line, 'error' => null];
            } catch (ModelNotFoundException $e) {
                $out[] = ['row' => $row['row_number'], 'input' => $row['data'], 'line' => null, 'error' => 'الصنف أو وحدة القياس غير متاحين أو لا توجد علاقة تحويل معتمدة.'];
            } catch (ValidationException $e) {
                $out[] = ['row' => $row['row_number'], 'input' => $row['data'], 'line' => null, 'error' => implode(' ', $e->validator->errors()->all())];
            }
        }

        return $out;
    }
}

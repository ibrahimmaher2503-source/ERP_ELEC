<?php

namespace App\Modules\Purchasing\Application;

use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OrderLineImport
{
    public function columns(): array
    {
        $labels = ['product_id' => 'المعرف الداخلي للصنف', 'quantity' => 'الكمية بالوحدة الأساسية', 'unit_price' => 'سعر الوحدة', 'discount_amount' => 'قيمة خصم البند'];
        $columns = [];
        foreach ($labels as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'required' => true, 'type' => 'text', 'allowed' => [], 'example' => '', 'description' => 'مسودة شراء مباشر فقط. لا اعتماد أو إصدار أو حساب ضريبة. المعرف الداخلي يظهر بجوار الصنف في المحرر.'];
        }

        return $columns;
    }

    public function authorize(int $actor, int $branch, ?int $order): void
    {
        app(PurchasingAccess::class)->authorize($actor, $order ? 'order_update' : 'order_create', $branch);
        if ($order) {
            $doc = app(PurchaseOrders::class)->record($actor, $order, 'order_update');
            abort_unless($doc->created_by === $actor && $doc->status === 'draft' && $doc->award_id === null && $doc->branch_id === $branch, 403, 'الاستيراد لمسودتك المباشرة فقط.');
        }
    }

    public function preview(int $actor, int $branch, ?int $order, UploadedFile $file): array
    {
        $this->authorize($actor, $branch, $order);
        Validator::make(['file' => $file], ['file' => 'required|file|max:10240|extensions:xlsx|mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip'])->validate();
        try {
            $rows = app(WorkbookCodec::class)->read($file->getRealPath(), 'purchasing-direct-order-lines', 'M05-v1', $this->columns());
        } catch (\UnexpectedValueException|\LengthException $e) {
            throw ValidationException::withMessages(['lineFile' => 'ملف غير مطابق للقالب الرسمي.']);
        }
        if (count($rows) < 1 || count($rows) > 100) {
            app(SupplierOffers::class)->fail('القالب يحتاج من 1 إلى 100 بند.');
        }
        $result = [];
        $seen = [];
        foreach ($rows as $row) {
            try {
                $v = Validator::make($row['data'], ['product_id' => 'required|integer|min:1', 'quantity' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'], 'unit_price' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D'], 'discount_amount' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,15})(\.[0-9]{1,8})?$/D']])->validate();
                $p = app(ProductsApi::class)->getProduct(new GetProductRequest($actor, (int) $v['product_id']))->product;
                if (! $p['active'] || isset($seen[$p['id']]) || bccomp($v['quantity'], '0', 8) <= 0) {
                    app(SupplierOffers::class)->fail('صنف غير متاح أو مكرر أو كمية غير صالحة.');
                }
                $raw = bcmul($v['quantity'], $v['unit_price'], 16);
                if (bccomp($raw, bcadd($raw, '0', 8), 16) !== 0 || bccomp($v['discount_amount'], $raw, 16) > 0) {
                    app(SupplierOffers::class)->fail('السعر أو الخصم أو دقة حاصل الضرب غير صالحة.');
                }
                $seen[$p['id']] = true;
                $result[] = ['row' => $row['row_number'], 'product_id' => $p['id'], 'name' => $p['name'], 'quantity' => $v['quantity'], 'unit_price' => $v['unit_price'], 'discount_amount' => $v['discount_amount'], 'error' => null];
            } catch (ValidationException|ModelNotFoundException) {
                $result[] = ['row' => $row['row_number'], 'error' => 'الصنف أو القيم المالية غير صالحة أو الصنف مكرر.'];
            }
        }

        return $result;
    }
}

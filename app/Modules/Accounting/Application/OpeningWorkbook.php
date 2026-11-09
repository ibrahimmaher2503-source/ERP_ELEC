<?php

namespace App\Modules\Accounting\Application;

use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;

final class OpeningWorkbook
{
    public function columns(): array
    {
        $labels = ['kind' => 'النوع: gl/ap/ar/cash/bank/inventory', 'reference' => 'مرجع فريد لكل نوع', 'branch_id' => 'رقم الفرع',
            'account_id' => 'حساب الأستاذ العام', 'debit' => 'مدين الأستاذ العام', 'credit' => 'دائن الأستاذ العام', 'supplier_id' => 'رقم المورد AP', 'customer_id' => 'رقم العميل AR',
            'warehouse_id' => 'رقم المستودع', 'product_id' => 'رقم الصنف', 'quantity' => 'كمية الصرف من الأقدم أولًا', 'unit_cost' => 'تكلفة الصرف من الأقدم أولًا', 'amount' => 'القيمة التفصيلية EGP', 'serials' => 'للصنف المسلسل: رقم لكل سطر'];
        $columns = [];
        foreach ($labels as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'required' => in_array($key, ['kind', 'reference', 'branch_id'], true),
                'type' => 'string', 'allowed' => $key === 'kind' ? ['gl', 'ap', 'ar', 'cash', 'bank', 'inventory'] : [], 'example' => null,
                'description' => 'أدخل الأرقام المالية كنص عشري، دون صيغ أو فواصل آلاف. الأستاذ العام هو القيد، وبقية الأنواع تفاصيل للمصالحة لا قيود إضافية.'];
        }

        return $columns;
    }

    public function template(int $actor): string
    {
        app(AccountingAccess::class)->authorize($actor, 'opening_manage');

        return app(WorkbookCodec::class)->template('financial-openings', '2', $this->columns(), []);
    }

    public function read(int $actor, string $path): array
    {
        app(AccountingAccess::class)->authorize($actor, 'opening_manage');
        try {
            $rows = app(WorkbookCodec::class)->read($path, 'financial-openings', '2', $this->columns());
        } catch (\UnexpectedValueException $e) {
            try {
                $rows = app(WorkbookCodec::class)->read($path, 'financial-openings', '1', array_slice($this->columns(), 0, -1));
            } catch (\UnexpectedValueException) {
                throw $e;
            }
        }
        $lines = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            $data['serials'] = preg_split('/\R/u', (string) ($data['serials'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach (['debit', 'credit', 'quantity', 'unit_cost', 'amount'] as $key) {
                if ($data[$key] !== null && $data[$key] !== '' && ! is_string($data[$key])) {
                    FinancialActivation::fail('السطر '.$row['row_number'].': يجب تنسيق الحقول المالية كنص عشري قبل الاستيراد.');
                }
            }
            foreach (['account_id', 'supplier_id', 'customer_id', 'warehouse_id', 'product_id'] as $key) {
                $data[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
            }
            foreach (['debit', 'credit'] as $key) {
                $data[$key] = ($data[$key] ?? '') === '' ? '0.00' : $data[$key];
            }
            $lines[] = $data;
        }

        return app(OpeningBalances::class)->normalize($actor, $lines);
    }
}

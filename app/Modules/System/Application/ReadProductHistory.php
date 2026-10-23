<?php

namespace App\Modules\System\Application;

use App\Modules\Products\Domain\ProductHistoryReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReadProductHistory implements ProductHistoryReader
{
    public function forProduct(int $productId): Collection
    {
        return DB::table('audit_entries')->where('source_module', 'Products')->where('source_type', 'product')->where('source_id', $productId)->orderByDesc('id')->limit(30)->get()->map(function ($entry) use ($productId) {
            $labels = ['created' => 'إنشاء الصنف', 'updated' => 'تعديل بيانات الصنف', 'status_changed' => 'تغيير الحالة', 'media_added' => 'إضافة مرفق', 'media_removed' => 'إزالة مرفق'];
            $fields = ['sku' => 'SKU', 'name' => 'الاسم', 'active' => 'نشط', 'version' => 'الإصدار', 'media_id' => 'معرّف المرفق', 'mime' => 'نوع الملف', 'removed' => 'تمت الإزالة'];
            $safe = function ($snapshot) use ($fields) {
                $data = json_decode($snapshot ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                $result = [];
                foreach ($fields as $key => $label) {
                    if (array_key_exists($key, $data) && (is_scalar($data[$key]) || $data[$key] === null)) {
                        $result[$label] = is_bool($data[$key]) ? ($data[$key] ? 'نعم' : 'لا') : $data[$key];
                    }
                }

                return $result;
            };

            return ['id' => $entry->id, 'label' => $labels[$entry->action] ?? 'تحديث الصنف', 'actor' => 'مستخدم #'.$entry->actor_id, 'occurred_at' => $entry->occurred_at, 'outcome' => $entry->outcome, 'scope' => 'الصنف #'.$productId, 'before' => $safe($entry->safe_before), 'after' => $safe($entry->safe_after)];
        });
    }
}

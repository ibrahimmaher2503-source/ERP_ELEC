<?php

namespace App\Modules\System\Application;

use App\Modules\Parties\Domain\CustomerHistoryReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReadCustomerHistory implements CustomerHistoryReader
{
    public function forCustomer(int $customerId): Collection
    {
        return DB::table('audit_entries')->where('source_module', 'Parties')->where('source_type', 'customer')->where('source_id', $customerId)->orderByDesc('id')->limit(30)->get()->map(function ($entry) use ($customerId) {
            $labels = ['created' => 'إنشاء العميل', 'updated' => 'تعديل بيانات العميل', 'status_changed' => 'تغيير الحالة', 'contact_saved' => 'حفظ جهة اتصال', 'address_saved' => 'حفظ عنوان', 'document_added' => 'إضافة مستند', 'note_added' => 'إضافة ملاحظة'];
            $fields = ['code' => 'كود العميل', 'name' => 'الاسم', 'status' => 'الحالة', 'version' => 'الإصدار', 'contact_id' => 'جهة الاتصال', 'active' => 'نشط', 'is_primary' => 'أساسي', 'address_id' => 'العنوان', 'purpose' => 'نوع العنوان', 'document_id' => 'المستند', 'note_id' => 'الملاحظة', 'mime' => 'نوع الملف'];
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

            return ['id' => $entry->id, 'label' => $labels[$entry->action] ?? 'تحديث العميل', 'actor' => $entry->actor_id === null ? 'غير منسوب إلى حساب' : 'مستخدم #'.$entry->actor_id, 'occurred_at' => $entry->occurred_at, 'outcome' => $entry->outcome, 'scope' => 'العميل #'.$customerId, 'before' => $safe($entry->safe_before), 'after' => $safe($entry->safe_after)];
        });
    }
}

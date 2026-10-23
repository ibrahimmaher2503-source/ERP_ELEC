<?php

namespace App\Modules\System\Application;

use App\Modules\Organization\Domain\UserActivityReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReadUserActivity implements UserActivityReader
{
    public function forUser(int $user): Collection
    {
        $labels = ['created' => 'إنشاء المستخدم', 'updated' => 'تحديث بيانات المستخدم', 'activated' => 'تفعيل الحساب', 'deactivated' => 'تعطيل الحساب', 'password_reset' => 'إتمام إعادة تعيين كلمة المرور إداريًا', 'self_password_reset' => 'إتمام استعادة كلمة المرور', 'self_password_changed' => 'تغيير كلمة المرور شخصيًا', 'self_profile_updated' => 'تحديث الملف الشخصي', 'login_succeeded' => 'تسجيل دخول ناجح', 'login_failed' => 'محاولة دخول فاشلة', 'password_reset_link_sent' => 'إرسال رابط استعادة كلمة المرور'];
        $fields = ['name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'active' => 'نشط', 'branch_id' => 'الفرع الرئيسي (المعرّف)', 'gender' => 'النوع', 'sessions_revoked' => 'إبطال الجلسات'];
        $labels['own_gender_updated'] = 'تحديث النوع في الملف الشخصي';
        $safe = function ($json) use ($fields) {
            $data = json_decode($json ?? '{}', true) ?: [];
            $result = [];
            foreach ($fields as $key => $label) {
                if (array_key_exists($key, $data)) {
                    $result[$label] = is_bool($data[$key]) ? ($data[$key] ? 'نعم' : 'لا') : (string) ($data[$key] ?? 'غير محدد');
                    if ($key === 'gender') {
                        $result[$label] = ['male' => 'ذكر', 'female' => 'أنثى'][$data[$key] ?? ''] ?? 'غير محدد';
                    }
                }
            }

            return $result;
        };

        return DB::table('audit_entries')->where('source_module', 'Organization')->where('source_type', 'user')->where('source_id', $user)->orderByDesc('id')->limit(50)->get()->map(fn ($event) => [
            'id' => 'system:'.$event->id, 'label' => $labels[$event->action] ?? 'نشاط حساب مسجل', 'actor_id' => $event->actor_id, 'occurred_at' => $event->occurred_at,
            'scope' => 'حساب المستخدم #'.$user, 'outcome' => $event->outcome, 'before' => $safe($event->safe_before), 'after' => $safe($event->safe_after),
        ]);
    }
}

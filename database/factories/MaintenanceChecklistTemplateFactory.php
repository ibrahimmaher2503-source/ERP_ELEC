<?php

namespace Database\Factories;

use App\Modules\FieldMaintenance\Domain\MaintenanceChecklistTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MaintenanceChecklistTemplateFactory extends Factory
{
    protected $model = MaintenanceChecklistTemplate::class;

    public function definition(): array
    {
        $source = (new MaintenanceRequestFactory)->definition();

        return ['code' => 'DEMO-EG-CHK-'.Str::upper(Str::random(10)), 'branch_id' => $source['branch_id'], 'title' => 'فحص لوحة إنارة تجريبية', 'items' => json_encode([['key' => 'safety', 'label' => 'التحقق من تجهيزات الأمان وتصريح العمل', 'required' => true], ['key' => 'visual', 'label' => 'الفحص الظاهري للتوصيلات', 'required' => true], ['key' => 'test', 'label' => 'الاختبار التشغيلي النهائي طبقًا للإجراء المعتمد', 'required' => true]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_by' => $source['created_by']];
    }
}

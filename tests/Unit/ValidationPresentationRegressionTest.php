<?php

namespace Tests\Unit;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class ValidationPresentationRegressionTest extends TestCase
{
    public function test_filters_have_one_shared_reset_control_and_explicit_defaults(): void
    {
        $html = Blade::render('<x-ui.filters id="review-filters" target="search,status,overdue" :reset="[\'search\' => \'\', \'status\' => \'\', \'overdue\' => false]"><input aria-label="الحالة" /></x-ui.filters>');
        $this->assertStringContainsString('data-erp-filters', $html);
        $this->assertStringContainsString('data-erp-filter-reset', $html);
        $this->assertStringContainsString('مسح الفلاتر', $html);
        $this->assertStringContainsString('review-filters-body', $html);
        $this->assertStringContainsString('تُحدَّث النتائج تلقائيًا', $html);
        $this->assertStringNotContainsString(' reset=', $html);
    }

    public function test_real_flux_select_renders_the_shared_wrapper_and_preserves_its_binding(): void
    {
        view()->share('errors', new ViewErrorBag);
        $html = Blade::render('<flux:select wire:model.live="form.city" label="المدينة" required><option value="">اختر</option><option value="1">القاهرة</option></flux:select>');
        $this->assertStringContainsString('<erp-select>', $html);
        $this->assertStringContainsString('data-erp-select-ui', $html);
        $this->assertStringContainsString('wire:model.live="form.city"', $html);
        $this->assertStringContainsString('required', $html);
    }

    public function test_validation_summary_renders_every_error_with_field_identity_and_accessible_alert(): void
    {
        $errors = new MessageBag([
            'form.name' => ['الاسم مطلوب.'],
            'form.lines.0.quantity' => ['الكمية غير صحيحة.'],
            'form.lines.1.quantity' => ['الكمية الثانية غير صحيحة.'],
        ]);

        $html = Blade::render('<x-ui.validation-summary :errors="$errors" />', compact('errors'));

        $this->assertStringContainsString('data-erp-validation', $html);
        $this->assertMatchesRegularExpression('/role="alert"/', $html);
        $this->assertMatchesRegularExpression('/<h[1-6][^>]*>/', $html);
        foreach ($errors->keys() as $field) {
            $this->assertStringContainsString('data-error-field="'.$field.'"', $html);
        }
        foreach ($errors->all() as $message) {
            $this->assertStringContainsString($message, $html);
        }
    }

    public function test_arabic_validation_rules_and_nested_attribute_names_are_translated(): void
    {
        app()->setLocale('ar');
        $validator = Validator::make([
            'form' => ['name' => '', 'lines' => [['quantity' => '0']]],
            'date' => 'not-a-date',
            'later' => '2025-01-01',
            'start' => '2025-02-01',
            'flag' => 'maybe',
            'items' => 'text',
            'too_large' => '2',
            'too_small' => '3',
            'upload' => UploadedFile::fake()->create('document.pdf', 1, 'application/pdf'),
            'identifier' => 'bad-uuid',
        ], [
            'form.name' => 'required',
            'form.lines.*.quantity' => 'numeric|min:1',
            'date' => 'date_format:Y-m-d',
            'later' => 'after_or_equal:start',
            'flag' => 'boolean',
            'items' => 'array',
            'too_large' => 'numeric|max:1',
            'too_small' => 'numeric|min:4',
            'upload' => 'mimetypes:image/png',
            'identifier' => 'uuid',
        ]);

        $messages = $validator->errors()->all();
        foreach (['form.name', 'form.lines.0.quantity', 'date', 'later', 'flag', 'items', 'too_large', 'too_small', 'upload', 'identifier'] as $field) {
            $this->assertTrue($validator->errors()->has($field), "Expected a validation error for [{$field}].");
        }
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('validation.', $message);
            $this->assertDoesNotMatchRegularExpression('/\b(field|must|required|invalid|greater|less)\b/i', $message);
        }
        $this->assertStringContainsString('الاسم', $validator->errors()->first('form.name'));
        $this->assertStringContainsString('الكمية', $validator->errors()->first('form.lines.0.quantity'));
    }
}

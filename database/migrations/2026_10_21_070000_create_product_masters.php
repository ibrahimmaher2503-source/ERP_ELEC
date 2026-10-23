<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_categories', 'brands', 'units_of_measure'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('code', 50)->unique('uq_'.$name.'_code');
                $table->string('name', 200);
                $table->boolean('active')->default(true);
                $table->integer('version')->default(1);
                if ($name === 'product_categories') {
                    $table->foreignId('parent_id')->nullable()->constrained('product_categories')->restrictOnDelete();
                }
                if ($name === 'units_of_measure') {
                    $table->string('dimension', 20);
                }
                $table->timestampsTz();
            });
        }
        DB::statement('ALTER TABLE product_categories ADD CONSTRAINT ck_product_categories_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement("ALTER TABLE units_of_measure ADD CONSTRAINT ck_units_dimension CHECK (dimension IN ('count','length','mass','volume','time'))");
        foreach ([
            'product_view' => 'عرض كتالوج المنتجات', 'product_create' => 'إنشاء الأصناف', 'product_update' => 'تعديل الأصناف',
            'product_status' => 'تفعيل وأرشفة الأصناف', 'category_manage' => 'إدارة التصنيفات', 'brand_manage' => 'إدارة العلامات التجارية',
            'unit_manage' => 'إدارة وحدات القياس', 'media_manage' => 'إدارة صور ومرفقات الأصناف', 'notes_view' => 'عرض الملاحظات الداخلية للأصناف',
            'notes_update' => 'تعديل الملاحظات الداخلية للأصناف',
        ] as $key => $label) {
            DB::table('permission_catalog')->updateOrInsert(['code' => 'M02.'.$key], ['label' => $label, 'scope' => 'company', 'module' => 'M02 · المنتجات', 'locked' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Product master history is retained; use a forward migration.');
    }
};

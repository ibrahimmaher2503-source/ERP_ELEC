<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupplierNote> */
class SupplierNoteFactory extends Factory
{
    protected $model = SupplierNote::class;

    public function definition(): array
    {
        return ['supplier_id' => Supplier::factory(), 'author_id' => null, 'body' => 'ملاحظة تجريبية: يرجى مراجعة بيان الكميات وأكواد الأصناف مع مهندس المشروع قبل التوريد.'];
    }
}

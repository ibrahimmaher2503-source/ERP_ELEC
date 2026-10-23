<?php

namespace Database\Factories;

use App\Modules\Organization\Domain\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/** @extends Factory<Model> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'id' => 1,
            'code' => 'DEMO-COMPANY',
            'name' => 'الشركة المصرية للتجهيزات الكهربائية - عرض توضيحي',
            'legal_name' => 'الشركة المصرية للتجهيزات الكهربائية - بيانات تجريبية',
            'tax_registration' => 'DEMO-TAX-COMPANY', 'commercial_registration' => 'DEMO-CR-COMPANY',
            'address' => '10 شارع مصطفى النحاس، مدينة نصر، القاهرة', 'phone' => '+20222100001', 'email' => 'company@egypt-market.example.test',
            'currency' => 'EGP',
            'setup_complete' => false,
            'version' => 1,
        ];
    }
}

<?php

use App\Models\User;
use App\Modules\Parties\Application\CustomerActions;
use App\Modules\Parties\Application\SupplierActions;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerAddress;
use App\Modules\Parties\Domain\CustomerContact;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierAddress;
use App\Modules\Parties\Domain\SupplierContact;
use App\Modules\Parties\Presentation\CustomerFormPage;
use App\Modules\Parties\Presentation\SupplierFormPage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

$verify = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }

    echo "PASS {$message}\n";
};

if (DB::connection()->getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
    throw new RuntimeException('Party primary-edit check requires the exact separate PostgreSQL test database.');
}

$suffix = strtoupper(bin2hex(random_bytes(5)));
DB::beginTransaction();

try {
    $actor = User::factory()->create(['active' => true, 'email_verified_at' => now()]);
    foreach ([
        'M03.customer_view', 'M03.customer_create', 'M03.customer_update', 'M03.contact_manage', 'M03.address_manage',
        'M03.supplier_view', 'M03.supplier_create', 'M03.supplier_update', 'M03.supplier_contact_manage', 'M03.supplier_address_manage',
    ] as $permission) {
        DB::table('access_grants')->insert(['user_id' => $actor->id, 'permission_code' => $permission, 'branch_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    $customer = app(CustomerActions::class)->save($actor->id, ['code' => 'DIRECT-CUS-'.$suffix, 'name' => 'Direct Customer '.$suffix, 'entity_type' => 'organization', 'status' => 'active']);
    $customerContact = app(CustomerActions::class)->saveContact($actor->id, $customer->id, ['name' => 'Customer Primary', 'active' => true, 'is_primary' => true]);
    $customerContact = app(CustomerActions::class)->saveContact($actor->id, $customer->id, ['name' => 'Edited Customer Primary', 'active' => true, 'is_primary' => true], $customerContact->id);
    $customerAddress = app(CustomerActions::class)->saveAddress($actor->id, $customer->id, ['label' => 'Billing', 'purpose' => 'billing', 'line' => '1 Original Customer Road', 'is_primary' => true]);
    $customerAddress = app(CustomerActions::class)->saveAddress($actor->id, $customer->id, ['label' => 'Billing', 'purpose' => 'billing', 'line' => '2 Edited Customer Road', 'is_primary' => true], $customerAddress->id);
    $verify($customerContact->fresh()->is_primary && $customer->contacts()->where('is_primary', true)->count() === 1 && $customer->contacts()->where('name', 'Edited Customer Primary')->exists(), 'Customer action edit preserves the existing primary contact');
    $verify($customerAddress->fresh()->is_primary && $customer->addresses()->where('purpose', 'billing')->where('is_primary', true)->count() === 1 && $customer->addresses()->where('line', '2 Edited Customer Road')->exists(), 'Customer action edit preserves the existing primary address');

    $supplier = app(SupplierActions::class)->save($actor->id, ['code' => 'DIRECT-SUP-'.$suffix, 'name' => 'Direct Supplier '.$suffix, 'entity_type' => 'organization', 'supplier_type' => 'distributor', 'status' => 'active']);
    $supplierContact = app(SupplierActions::class)->saveContact($actor->id, $supplier->id, ['name' => 'Supplier Primary', 'active' => true, 'is_primary' => true]);
    $supplierContact = app(SupplierActions::class)->saveContact($actor->id, $supplier->id, ['name' => 'Edited Supplier Primary', 'active' => true, 'is_primary' => true], $supplierContact->id);
    $supplierAddress = app(SupplierActions::class)->saveAddress($actor->id, $supplier->id, ['label' => 'Registered', 'purpose' => 'registered', 'line' => '1 Original Supplier Road', 'is_primary' => true]);
    $supplierAddress = app(SupplierActions::class)->saveAddress($actor->id, $supplier->id, ['label' => 'Registered', 'purpose' => 'registered', 'line' => '2 Edited Supplier Road', 'is_primary' => true], $supplierAddress->id);
    $verify($supplierContact->fresh()->is_primary && $supplier->contacts()->where('is_primary', true)->count() === 1 && $supplier->contacts()->where('name', 'Edited Supplier Primary')->exists(), 'Supplier action edit preserves the existing primary contact');
    $verify($supplierAddress->fresh()->is_primary && $supplier->addresses()->where('purpose', 'registered')->where('is_primary', true)->count() === 1 && $supplier->addresses()->where('line', '2 Edited Supplier Road')->exists(), 'Supplier action edit preserves the existing primary address');

    $guard = Auth::guard();
    $previousActor = $guard->user();
    $guard->setUser($actor);
    try {
        Livewire::test(CustomerFormPage::class)
            ->set('form.code', 'FORM-CUS-'.$suffix)
            ->set('form.name', 'Form Customer '.$suffix)
            ->set('form.entity_type', 'organization')
            ->set('form.status', 'active')
            ->set('contact.name', 'Form Customer Primary')
            ->set('contact.active', true)
            ->set('contact.is_primary', true)
            ->set('billing.label', 'Form customer billing')
            ->set('billing.line', '10 Customer Form Road')
            ->set('billing.country', 'Egypt')
            ->set('billing.city', 'Cairo')
            ->call('save');
        $formCustomer = Customer::where('code', 'FORM-CUS-'.$suffix)->firstOrFail();
        $formCustomerContact = $formCustomer->contacts()->where('is_primary', true)->firstOrFail();
        $formCustomerBilling = $formCustomer->addresses()->where('purpose', 'billing')->where('is_primary', true)->firstOrFail();
        $formCustomerShipping = $formCustomer->addresses()->where('purpose', 'shipping')->where('is_primary', true)->firstOrFail();
        Livewire::test(CustomerFormPage::class, ['customer' => $formCustomer->id])
            ->set('form.name', 'Edited Form Customer '.$suffix)
            ->set('contact.name', 'Edited Form Customer Primary')
            ->set('billing.line', '11 Customer Form Road')
            ->call('save');
        $verify($formCustomer->fresh()->name === 'Edited Form Customer '.$suffix && $formCustomerContact->fresh()->is_primary && $formCustomer->contacts()->where('is_primary', true)->count() === 1, 'Customer form edit preserves its primary contact');
        $verify($formCustomerBilling->fresh()->is_primary && $formCustomerShipping->fresh()->is_primary && $formCustomer->addresses()->where('purpose', 'billing')->where('is_primary', true)->count() === 1 && $formCustomer->addresses()->where('purpose', 'shipping')->where('is_primary', true)->count() === 1, 'Customer form edit preserves its primary billing and shipping addresses');

        Livewire::test(SupplierFormPage::class)
            ->set('form.code', 'FORM-SUP-'.$suffix)
            ->set('form.name', 'Form Supplier '.$suffix)
            ->set('form.entity_type', 'organization')
            ->set('form.supplier_type', 'manufacturer')
            ->set('form.status', 'active')
            ->set('contact.name', 'Form Supplier Primary')
            ->set('contact.active', true)
            ->set('contact.is_primary', true)
            ->set('billing.label', 'Form registered address')
            ->set('billing.line', '10 Supplier Form Road')
            ->set('billing.country', 'Egypt')
            ->set('billing.city', 'Cairo')
            ->set('shipping.label', 'Form office address')
            ->set('shipping.line', '20 Supplier Form Road')
            ->set('shipping.country', 'Egypt')
            ->set('shipping.city', 'Cairo')
            ->call('save');
        $formSupplier = Supplier::where('code', 'FORM-SUP-'.$suffix)->firstOrFail();
        $formSupplierContact = $formSupplier->contacts()->where('is_primary', true)->firstOrFail();
        $formRegistered = $formSupplier->addresses()->where('purpose', 'registered')->where('is_primary', true)->firstOrFail();
        $formOffice = $formSupplier->addresses()->where('purpose', 'office')->where('is_primary', true)->firstOrFail();
        Livewire::test(SupplierFormPage::class, ['supplier' => $formSupplier->id])
            ->set('form.name', 'Edited Form Supplier '.$suffix)
            ->set('contact.name', 'Edited Form Supplier Primary')
            ->set('billing.line', '11 Supplier Form Road')
            ->set('shipping.line', '21 Supplier Form Road')
            ->call('save');
        $verify($formSupplier->fresh()->name === 'Edited Form Supplier '.$suffix && $formSupplierContact->fresh()->is_primary && $formSupplier->contacts()->where('is_primary', true)->count() === 1, 'Supplier form edit preserves its primary contact');
        $verify($formRegistered->fresh()->is_primary && $formOffice->fresh()->is_primary && $formSupplier->addresses()->where('purpose', 'registered')->where('is_primary', true)->count() === 1 && $formSupplier->addresses()->where('purpose', 'office')->where('is_primary', true)->count() === 1, 'Supplier form edit preserves its primary registered and office addresses');
    } finally {
        if ($previousActor !== null) {
            $guard->setUser($previousActor);
        } else {
            $guard->forgetUser();
        }
    }

    DB::rollBack();
    echo "Customer and Supplier primary-edit regression checks passed.\n";
} catch (Throwable $exception) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    throw $exception;
}

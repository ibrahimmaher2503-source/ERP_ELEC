<?php

namespace App\Modules\Parties\Presentation;

use App\Modules\Parties\Application\CustomerAccess;
use App\Modules\Parties\Application\CustomerActions;
use App\Modules\Parties\Application\CustomerCatalogQuery;
use App\Modules\Parties\Application\CustomerDataDefinition;
use App\Modules\Parties\Application\SupplierAccess;
use App\Modules\Parties\Application\SupplierActions;
use App\Modules\Parties\Application\SupplierCatalogQuery;
use App\Modules\Parties\Application\SupplierDataDefinition;
use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerAddress;
use App\Modules\Parties\Domain\CustomerContact;
use App\Modules\Parties\Domain\CustomerHistoryReader;
use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierAddress;
use App\Modules\Parties\Domain\SupplierContact;
use App\Modules\Parties\Domain\SupplierHistoryReader;

final readonly class PartyPresentation
{
    public bool $supplier;

    public string $singular;

    public string $plural;

    public string $model;

    public string $contactModel;

    public string $addressModel;

    public string $foreignKey;

    public function __construct(public string $entity)
    {
        if (! in_array($entity, ['customers', 'suppliers'], true)) {
            throw new \LogicException('Unknown party presentation.');
        }
        $this->supplier = $entity === 'suppliers';
        $this->singular = $this->supplier ? 'المورد' : 'العميل';
        $this->plural = $this->supplier ? 'الموردون' : 'العملاء';
        $this->model = $this->supplier ? Supplier::class : Customer::class;
        $this->contactModel = $this->supplier ? SupplierContact::class : CustomerContact::class;
        $this->addressModel = $this->supplier ? SupplierAddress::class : CustomerAddress::class;
        $this->foreignKey = $this->supplier ? 'supplier_id' : 'customer_id';
    }

    public function permission(string $capability): string
    {
        if ($this->supplier) {
            return 'M03.supplier_'.$capability;
        }

        return 'M03.'.(in_array($capability, ['view', 'create', 'update', 'status', 'bulk_update', 'import', 'export', 'template'], true) ? 'customer_'.$capability : $capability);
    }

    public function authorize(int $actor, string $capability): void
    {
        app($this->supplier ? SupplierAccess::class : CustomerAccess::class)->authorize($actor, $this->permission($capability));
    }

    public function allows(int $actor, string $capability): bool
    {
        return app($this->supplier ? SupplierAccess::class : CustomerAccess::class)->allows($actor, $this->permission($capability));
    }

    public function actions(): CustomerActions|SupplierActions
    {
        return app($this->supplier ? SupplierActions::class : CustomerActions::class);
    }

    public function definition(): CustomerDataDefinition|SupplierDataDefinition
    {
        return app($this->supplier ? SupplierDataDefinition::class : CustomerDataDefinition::class);
    }

    public function query(int $actor, array $filters = [])
    {
        return app($this->supplier ? SupplierCatalogQuery::class : CustomerCatalogQuery::class)->query($actor, $filters);
    }

    public function history(int $id)
    {
        return app($this->supplier ? SupplierHistoryReader::class : CustomerHistoryReader::class)->{$this->supplier ? 'forSupplier' : 'forCustomer'}($id);
    }

    public function purposes(): array
    {
        return $this->supplier ? ['registered' => 'العنوان المسجل', 'office' => 'عنوان المكتب', 'pickup' => 'عنوان الاستلام', 'other' => 'عنوان إضافي'] : ['billing' => 'عنوان الفواتير', 'shipping' => 'عنوان الشحن', 'other' => 'عنوان إضافي'];
    }

    public function formPurposes(): array
    {
        return $this->supplier ? ['registered', 'office'] : ['billing', 'shipping'];
    }

    public function route(string $action): string
    {
        return $this->entity.'.'.$action;
    }

    public function typeLabels(): array
    {
        return ['manufacturer' => 'مصنّع', 'distributor' => 'موزع', 'service_provider' => 'مقدم خدمات', 'other' => 'آخر'];
    }

    public function formTitle(bool $editing): string
    {
        return $editing ? 'تعديل '.$this->singular : 'إضافة '.($this->supplier ? 'مورد' : 'عميل').' جديد';
    }
}

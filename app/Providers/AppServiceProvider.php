<?php

namespace App\Providers;

use App\Modules\Inventory\Application\Contracts\InventoryApi;
use App\Modules\Inventory\Application\InventoryAccess;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Domain\InventoryChanged;
use App\Modules\Inventory\Domain\InventoryHistoryReader;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use App\Modules\Organization\Application\Contracts\QuotationDelegation;
use App\Modules\Organization\Application\OrganizationAccess;
use App\Modules\Organization\Application\OrganizationService;
use App\Modules\Organization\Application\PermissionAccess;
use App\Modules\Organization\Application\QuotationDelegationService;
use App\Modules\Organization\Application\SyncAdministratorPermissions;
use App\Modules\Organization\Application\UserAccess;
use App\Modules\Organization\Domain\MasterChanged;
use App\Modules\Organization\Domain\UserActivityReader;
use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Parties\Application\CustomerAccess;
use App\Modules\Parties\Application\CustomerDataDefinition;
use App\Modules\Parties\Application\CustomersService;
use App\Modules\Parties\Application\SupplierAccess;
use App\Modules\Parties\Application\SupplierDataDefinition;
use App\Modules\Parties\Application\SuppliersService;
use App\Modules\Parties\Domain\CustomerChanged;
use App\Modules\Parties\Domain\CustomerHistoryReader;
use App\Modules\Parties\Domain\SupplierCategoryChanged;
use App\Modules\Parties\Domain\SupplierChanged;
use App\Modules\Parties\Domain\SupplierHistoryReader;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Application\ProductDataDefinition;
use App\Modules\Products\Application\ProductsService;
use App\Modules\Products\Domain\ProductChanged;
use App\Modules\Products\Domain\ProductHistoryReader;
use App\Modules\Purchasing\Application\Contracts\PurchasingApi;
use App\Modules\Purchasing\Application\PurchasingService;
use App\Modules\Purchasing\Domain\ProcurementChanged;
use App\Modules\Purchasing\Domain\PurchasingChanged;
use App\Modules\Purchasing\Domain\RfqChanged;
use App\Modules\Sales\Application\Contracts\QuotationPricing;
use App\Modules\Sales\Application\Contracts\QuotationsApi;
use App\Modules\Sales\Application\Contracts\SalesOrdersApi;
use App\Modules\Sales\Application\QuotationAccess;
use App\Modules\Sales\Application\QuotationsService;
use App\Modules\Sales\Application\SalesOrderAccess;
use App\Modules\Sales\Application\SalesOrdersService;
use App\Modules\Sales\Domain\QuotationChanged;
use App\Modules\Sales\Domain\QuotationHistoryReader;
use App\Modules\Sales\Domain\SalesOrderChanged;
use App\Modules\Sales\Domain\SalesOrderHistoryReader;
use App\Modules\Sales\Infrastructure\UnavailableQuotationPricing;
use App\Modules\System\Application\DataOperations\EntityRegistry;
use App\Modules\System\Application\ReadCustomerHistory;
use App\Modules\System\Application\ReadInventoryHistory;
use App\Modules\System\Application\ReadProductHistory;
use App\Modules\System\Application\ReadQuotationHistory;
use App\Modules\System\Application\ReadSalesOrderHistory;
use App\Modules\System\Application\ReadSupplierHistory;
use App\Modules\System\Application\ReadUserActivity;
use App\Modules\System\Application\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PurchasingApi::class, PurchasingService::class);

        $this->app->bind(InventoryHistoryReader::class, ReadInventoryHistory::class);
        $this->app->bind(InventoryApi::class, InventoryService::class);
        $this->app->bind(SalesOrdersApi::class, SalesOrdersService::class);
        $this->app->bind(SalesOrderHistoryReader::class, ReadSalesOrderHistory::class);
        $this->app->bind(QuotationsApi::class, QuotationsService::class);
        $this->app->bind(QuotationDelegation::class, QuotationDelegationService::class);
        $this->app->bind(QuotationPricing::class, UnavailableQuotationPricing::class);
        $this->app->bind(QuotationHistoryReader::class, ReadQuotationHistory::class);
        $this->app->bind(SuppliersApi::class, SuppliersService::class);
        $this->app->bind(SupplierHistoryReader::class, ReadSupplierHistory::class);
        $this->app->bind(CustomersApi::class, CustomersService::class);
        $this->app->bind(CustomerHistoryReader::class, ReadCustomerHistory::class);
        $this->app->singleton(EntityRegistry::class, fn () => new EntityRegistry(['products' => ProductDataDefinition::class, 'customers' => CustomerDataDefinition::class, 'suppliers' => SupplierDataDefinition::class]));
        $this->app->bind(ProductHistoryReader::class, ReadProductHistory::class);
        $this->app->bind(ProductsApi::class, ProductsService::class);
        $this->app->bind(OrganizationApi::class, OrganizationService::class);
        $this->app->bind(UserActivityReader::class, ReadUserActivity::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ProcurementChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, $event->type, $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Purchasing');
        });
        Event::listen(RfqChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, 'purchase_rfq', $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Purchasing');
        });
        Event::listen(PurchasingChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, 'purchase_requisition', $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Purchasing');
        });
        $this->configureDefaults();
        Event::listen(InventoryChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, $event->type, $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Inventory');
        });
        Event::listen(SalesOrderChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, 'sales_order', $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Sales');
        });
        Event::listen(MigrationsEnded::class, function ($event) {
            if ($event->method === 'up' && Schema::hasTable('migrations')
                && DB::table('migrations')->where('migration', '2026_10_21_114000_enable_administrator_default_permissions')->exists()) {
                app(SyncAdministratorPermissions::class)->execute();
            }
        });
        Event::listen(QuotationChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, 'quotation', $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Sales');
        });
        Event::listen(SupplierChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, 'supplier', $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Parties');
        });
        Event::listen(SupplierCategoryChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, 'supplier_category', $event->id, 'category_saved', $event->before, $event->after, 'succeeded', 'Parties');
        });
        Event::listen(CustomerChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, 'customer', $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Parties');
        });
        Event::listen(ProductChanged::class, function ($event) {
            app(RecordAudit::class)->execute($event->actorId, $event->type, $event->id, $event->action, $event->before, $event->after, 'succeeded', 'Products');
        });
        Event::listen(MasterChanged::class, function ($event) {
            app(RecordAudit::class)->execute(
                $event->actorId, $event->type, $event->id, $event->action, $event->before, $event->after, $event->outcome,
            );
        });
        View::composer('*::app.sidebar', function ($view) {
            $actor = auth()->user();
            $access = app(OrganizationAccess::class);
            $permissions = app(PermissionAccess::class);
            $productAccess = app(ProductAccess::class);
            $view->with('inventoryNavigation', $actor ? app(InventoryAccess::class)->navigation($actor->id) : []);
            $view->with('quotationNavigation', $actor && app(QuotationAccess::class)->allows($actor->id));
            $view->with('salesOrderNavigation', $actor && app(SalesOrderAccess::class)->allows($actor->id));
            $view->with('salesReturnNavigation', $actor && app(SalesOrderAccess::class)->allows($actor->id, 'return_view'));
            $view->with('supplierNavigation', $actor && app(SupplierAccess::class)->allows($actor->id, 'M03.supplier_view'));
            $view->with('customerNavigation', $actor && app(CustomerAccess::class)->allows($actor->id, 'M03.customer_view'));
            $view->with('productNavigation', collect(['index' => 'M02.product_view', 'categories' => 'M02.category_manage', 'brands' => 'M02.brand_manage', 'units' => 'M02.unit_manage'])->map(fn ($code) => $actor && $productAccess->allows($actor->id, $code))->all());
            $view->with('organizationNavigation', [
                'company' => $actor && $access->allows($actor, 'M01.company_view'),
                'branches' => $actor && ($access->allows($actor, 'M01.branch_admin') || $access->allows($actor, 'M01.branch_create') || $permissions->branchIds($actor, 'M01.branch_view') !== []),
                'users' => $actor && (app(UserAccess::class)->can($actor, 'M01.user_admin') || app(UserAccess::class)->can($actor, 'M01.user_create') || $permissions->branchIds($actor, 'M01.user_view') !== []),
                'security' => $actor && $actor->email_verified_at !== null && $permissions->allows($actor, 'M01.security_admin'),
            ]);
            $view->with('organizationCompany', auth()->check()
                ? app(OrganizationAccess::class)->companyIdentity(auth()->user())
                : null);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

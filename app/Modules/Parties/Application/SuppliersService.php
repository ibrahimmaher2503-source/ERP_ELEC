<?php

namespace App\Modules\Parties\Application;

use App\Modules\Parties\Application\Contracts\GetSupplierRequest;
use App\Modules\Parties\Application\Contracts\SearchSuppliersRequest;
use App\Modules\Parties\Application\Contracts\SupplierContractException;
use App\Modules\Parties\Application\Contracts\SupplierIdentity;
use App\Modules\Parties\Application\Contracts\SuppliersApi;
use App\Modules\Parties\Domain\Supplier;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SuppliersService implements SuppliersApi
{
    private function authorize(int $actor): void
    {
        try {
            app(SupplierAccess::class)->authorize($actor, 'M03.supplier_view');
        } catch (HttpException $e) {
            throw new SupplierContractException('Supplier access denied.', 403);
        }
    }

    private function identity(Supplier $r): SupplierIdentity
    {
        return new SupplierIdentity($r->id, $r->code, $r->name, $r->entity_type, $r->status);
    }

    public function getSupplier(GetSupplierRequest $request): SupplierIdentity
    {
        $this->authorize($request->actorId);
        $r = Supplier::query()->when(DB::transactionLevel() > 0, fn ($q) => $q->sharedLock())->find($request->supplierId);
        if (! $r) {
            throw new SupplierContractException('Supplier not found.', 404);
        }
        if ($request->requireActive && $r->status !== 'active') {
            throw new SupplierContractException('Supplier inactive.', 422);
        }

        return $this->identity($r);
    }

    public function searchSuppliers(SearchSuppliersRequest $request): array
    {
        $this->authorize($request->actorId);

        return app(SupplierCatalogQuery::class)->query($request->actorId, ['search' => $request->search, 'status' => 'active'])->orderBy('code')->limit(max(1, min(50, $request->limit)))->get()->map(fn ($r) => $this->identity($r))->all();
    }
}

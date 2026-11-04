<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Domain\InventoryChanged;
use App\Modules\Organization\Application\Contracts\AuthorizeRequest;
use App\Modules\Organization\Application\Contracts\OrganizationApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InventoryActions
{
    public static function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(10401)');
        DB::select('SELECT pg_advisory_xact_lock(60601)');
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => $message]);
    }

    public function setMinimum(int $actor, int $balanceId, ?string $minimum): void
    {
        DB::transaction(function () use ($actor, $balanceId, $minimum) {
            self::lock();
            $row = DB::table('inventory_balances')->where('id', $balanceId)->lockForUpdate()->first();
            abort_unless($row, 404);
            app(InventoryAccess::class)->authorize($actor, 'reorder_manage', $row->warehouse_id);
            $minimum = $minimum === null || $minimum === '' ? null : app(InventoryLedger::class)->quantity($minimum, false, true);
            DB::table('inventory_balances')->where('id', $balanceId)->update(['minimum' => $minimum, 'updated_at' => now()]);
            event(new InventoryChanged($actor, 'inventory_balance', $balanceId, 'minimum_changed', ['minimum' => $row->minimum], ['minimum' => $minimum, 'warehouse_id' => $row->warehouse_id, 'product_id' => $row->product_id]));
        });
    }

    public function saveWarehouse(int $actor, array $data, ?int $id = null, ?int $version = null): array
    {
        return DB::transaction(function () use ($actor, $data, $id, $version) {
            self::lock();
            app(InventoryAccess::class)->authorize($actor, $id ? 'warehouse_update' : 'warehouse_create');
            $d = Validator::make($data, [
                'code' => ['required', 'regex:/^[A-Za-z0-9_-]{1,40}$/D', Rule::unique('inventory_warehouses', 'code')->ignore($id)],
                'name' => ['required', 'string', 'max:160', 'not_regex:/\x00/'], 'branch_id' => ['required', 'integer', 'min:1'],
                'address' => ['nullable', 'string', 'max:1000', 'not_regex:/\x00/'], 'storekeeper_name' => ['nullable', 'string', 'max:200', 'not_regex:/\x00/'], 'notes' => ['nullable', 'string', 'max:2000', 'not_regex:/\x00/'], 'active' => ['sometimes', 'boolean'],
            ])->validate();
            $branches = app(OrganizationApi::class)->listBranchesForPermission(new AuthorizeRequest($actor, 'M01.branch_view'));
            $branch = collect($branches)->firstWhere('id', (int) $d['branch_id']);
            abort_unless($branch && $branch['active'], 403, 'يلزم نطاق فرع نشط لإنشاء أو تعديل المستودع.');
            $before = $id ? DB::table('inventory_warehouses')->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) {
                abort_unless($before, 404);
                if ($before->version !== $version) {
                    self::fail('تغير المستودع؛ أعد تحميل الصفحة.');
                }
                if ($before->branch_id !== (int) $d['branch_id']) {
                    self::fail('لا يمكن تغيير فرع المستودع بعد إنشائه.');
                }
                if (($d['active'] ?? true) === false) {
                    $stock = DB::table('inventory_balances')->where('warehouse_id', $id)->where(fn ($q) => $q->where('on_hand', '<>', '0')->orWhere('reserved', '<>', '0'))->exists();
                    $pending = DB::table('inventory_documents')->where(fn ($q) => $q->where('warehouse_id', $id)->orWhere('destination_id', $id))->whereNotIn('status', ['posted', 'received', 'cancelled'])->exists();
                    if ($stock || $pending) {
                        self::fail('المستودع به رصيد أو مستندات قيد التنفيذ.');
                    }
                }
            }
            $row = ['code' => $d['code'], 'name' => $d['name'], 'branch_id' => (int) $d['branch_id'], 'address' => $d['address'] ?? null, 'storekeeper_name' => $d['storekeeper_name'] ?? null, 'notes' => $d['notes'] ?? null, 'active' => $d['active'] ?? true, 'version' => ($before->version ?? 0) + 1, 'updated_at' => now()];
            if ($id) {
                DB::table('inventory_warehouses')->where('id', $id)->update($row);
            } else {
                $id = DB::table('inventory_warehouses')->insertGetId($row + ['created_at' => now()]);
            }
            event(new InventoryChanged($actor, 'warehouse', $id, 'saved', $before ? (array) $before : null, $row));

            return ['id' => $id, 'version' => $row['version']];
        });
    }
}

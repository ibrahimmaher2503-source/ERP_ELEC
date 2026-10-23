<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Customer;
use App\Modules\Sales\Domain\SalesOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Standalone test fixtures may use an existing active Organization branch; never use this factory at runtime.
 *
 * @extends Factory<SalesOrder>
 */
class SalesOrderFactory extends Factory
{
    protected $model = SalesOrder::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('company_id', 1)->where('active', true)->orderBy('id')->first(['id', 'code', 'name', 'address']);
        if (! $branch) {
            throw new RuntimeException('Sales order factories require an existing active Organization branch fixture.');
        }

        return [
            'draft_key' => (string) Str::uuid(),
            'number' => null,
            'branch_id' => (int) $branch->id,
            'branch_snapshot' => ['id' => (int) $branch->id, 'code' => $branch->code, 'name' => $branch->name, 'address' => $branch->address],
            'customer_id' => Customer::factory(),
            'customer_snapshot' => [],
            'source_type' => 'direct',
            'project_name' => null,
            'customer_reference' => 'DEMO-SO-'.Str::upper(Str::random(8)),
            'order_date' => now('Africa/Cairo')->toDateString(),
            'requested_delivery_date' => null,
            'currency' => 'EGP',
            'status' => 'draft',
            'version' => 1,
            'discount_mode' => 'percent',
            'discount_value' => '0.00000000',
            'payment_terms' => null,
            'delivery_terms' => null,
            'notes' => 'Synthetic draft fixture; no commercial approval or posting is implied.',
            'calculation' => [],
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SalesOrder $order): void {
            $customer = Customer::query()->findOrFail($order->customer_id);
            $order->update(['customer_snapshot' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'entityType' => $customer->entity_type,
                'status' => $customer->status,
            ]]);
        });
    }
}

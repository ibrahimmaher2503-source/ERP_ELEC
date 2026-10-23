<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->value('id');
        $user = DB::table('users')->where('active', false)->orderBy('id')->value('id');
        $supplier = DB::table('suppliers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->first();
        if (! $branch || ! $user || ! $supplier) {
            throw new \RuntimeException('Existing Egyptian synthetic branch, disabled user and supplier required.');
        }

        return ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name, 'created_by' => $user, 'status' => 'draft', 'version' => 1, 'order_date' => now('Africa/Cairo')->toDateString(), 'currency' => 'EGP', 'net_amount' => '900.00000000', 'notes' => 'DEMO-EG — مسودة شراء تجريبية، بلا اعتماد أو إصدار'];
    }
}

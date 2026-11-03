<?php

namespace Database\Factories;

use App\Modules\ReceivablesPayables\Domain\CustomerInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CustomerInvoiceFactory extends Factory
{
    protected $model = CustomerInvoice::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->value('id');
        $customer = DB::table('customers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->first();
        $user = DB::table('users')->where('active', false)->orderBy('id')->value('id');
        if (! $branch || ! $customer || ! $user) {
            throw new \RuntimeException('Synthetic branch, customer and disabled user are required.');
        }

        return ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch, 'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'invoice_date' => now('Africa/Cairo')->toDateString(), 'due_date' => now('Africa/Cairo')->addDays(30)->toDateString(),
            'status' => 'draft', 'version' => 1, 'net_amount' => '0.00', 'tax_amount' => '0.00', 'total_amount' => '0.00',
            'created_by' => $user, 'source_snapshot' => ['fixture' => 'DEMO-EG draft only']];
    }
}

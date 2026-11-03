<?php

namespace Database\Factories;

use App\Modules\ReceivablesPayables\Domain\SupplierInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupplierInvoiceFactory extends Factory
{
    protected $model = SupplierInvoice::class;

    public function definition(): array
    {
        $branch = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->value('id');
        $supplier = DB::table('suppliers')->where('code', 'like', 'DEMO-EG%')->where('status', 'active')->orderBy('id')->first();
        $user = DB::table('users')->where('active', false)->orderBy('id')->value('id');
        if (! $branch || ! $supplier || ! $user) {
            throw new \RuntimeException('Synthetic branch, supplier and disabled user are required.');
        }

        return ['creation_key' => (string) Str::uuid(), 'branch_id' => $branch, 'supplier_id' => $supplier->id, 'supplier_name' => $supplier->name,
            'supplier_invoice_number' => 'DEMO-DRAFT-'.Str::upper(Str::random(8)), 'invoice_date' => now('Africa/Cairo')->toDateString(),
            'due_date' => now('Africa/Cairo')->addDays(30)->toDateString(), 'tax_mode' => 'exclusive', 'status' => 'draft', 'version' => 1,
            'net_amount' => '0.00', 'tax_amount' => '0.00', 'total_amount' => '0.00', 'grni_amount' => '0.00', 'ppv_amount' => '0.00',
            'created_by' => $user, 'source_snapshot' => ['fixture' => 'DEMO-EG draft only']];
    }
}

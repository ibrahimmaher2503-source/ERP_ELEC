<?php

namespace Database\Seeders;

use App\Modules\Parties\Domain\Customer;
use App\Modules\Parties\Domain\CustomerAddress;
use App\Modules\Parties\Domain\CustomerContact;
use App\Modules\Parties\Domain\CustomerDocument;
use App\Modules\Parties\Domain\CustomerNote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CustomerDemoSeeder extends Seeder
{
    private array $createdFiles = [];

    public function run(): void
    {
        $this->assertSafeTarget();
        $this->createdFiles = [];

        try {
            DB::transaction(function (): void {
                DB::select('SELECT pg_advisory_xact_lock(30302)');
                foreach ($this->fixtures() as $fixture) {
                    if (Customer::where('code', $fixture['code'])->exists()) {
                        continue;
                    }

                    $customer = Customer::factory()->create(array_diff_key($fixture, array_flip(['contact', 'street', 'note'])));
                    CustomerContact::factory()->create([
                        'customer_id' => $customer->id,
                        'name' => $fixture['contact'],
                        'email' => strtolower(str_replace('-', '.', $fixture['code'])).'@example.test',
                        'is_primary' => true,
                    ]);
                    foreach ([['billing', true], ['shipping', true]] as [$purpose, $primary]) {
                        CustomerAddress::factory()->create([
                            'customer_id' => $customer->id,
                            'purpose' => $purpose,
                            'label' => ucfirst($purpose).' address',
                            'city' => $fixture['city'],
                            'line' => $fixture['street'],
                            'is_primary' => $primary,
                        ]);
                    }
                    CustomerNote::factory()->create([
                        'customer_id' => $customer->id,
                        'author_id' => null,
                        'body' => 'Synthetic demo note: '.$fixture['note'],
                    ]);

                    $document = CustomerDocument::factory()->make(['customer_id' => $customer->id]);
                    $path = $document->path;
                    $this->createdFiles[] = $path;
                    $document->save();
                }
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($this->createdFiles);
            throw $exception;
        }
    }

    private function assertSafeTarget(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Customer demo seeding is restricted to local/testing PostgreSQL databases.');
        }

        if (app()->environment('testing') && DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
            throw new RuntimeException('Testing customer demo seeding requires the exact ERP test database.');
        }
    }

    private function fixtures(): array
    {
        return [
            ['code' => 'DEMO-CUS-CAIRO-001', 'name' => 'Demo Cairo Electrical Projects', 'name_en' => 'Demo Cairo Electrical Projects', 'trading_name' => 'Demo Cairo Projects', 'entity_type' => 'organization', 'segment' => 'contractor', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Cairo', 'phone' => '+20 2 0000 0101', 'email' => 'demo-customer-cairo@example.test', 'contact' => 'Demo Contact Cairo', 'street' => '10 Synthetic Business Road', 'note' => 'Prefers coordination through the project office.'],
            ['code' => 'DEMO-CUS-ALEX-001', 'name' => 'Demo Alexandria Panel Works', 'name_en' => 'Demo Alexandria Panel Works', 'trading_name' => null, 'entity_type' => 'organization', 'segment' => 'panel_builder', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Alexandria', 'phone' => '+20 3 0000 0102', 'email' => 'demo-customer-alex@example.test', 'contact' => 'Demo Contact Alexandria', 'street' => '20 Synthetic Workshop Street', 'note' => 'Synthetic sample for the panel builder segment.'],
            ['code' => 'DEMO-CUS-GIZA-001', 'name' => 'Demo Giza Industrial Client', 'name_en' => 'Demo Giza Industrial Client', 'trading_name' => 'Demo Giza Industrial', 'entity_type' => 'organization', 'segment' => 'industrial', 'status' => 'inactive', 'country' => 'Egypt', 'city' => 'Giza', 'phone' => '+20 2 0000 0103', 'email' => 'demo-customer-giza@example.test', 'contact' => 'Demo Contact Giza', 'street' => '30 Synthetic Factory Avenue', 'note' => 'Inactive synthetic profile retained for catalog examples.'],
            ['code' => 'DEMO-CUS-CAIRO-002', 'name' => 'Demo Individual Customer', 'name_en' => 'Demo Individual Customer', 'trading_name' => null, 'entity_type' => 'individual', 'segment' => 'individual', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Cairo', 'phone' => null, 'email' => 'demo-individual-customer@example.test', 'contact' => 'Demo Individual Contact', 'street' => '40 Synthetic Residential Lane', 'note' => 'Synthetic individual profile; no tax or registration identity is assumed.'],
        ];
    }
}

<?php

namespace Database\Seeders;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierAddress;
use App\Modules\Parties\Domain\SupplierCategory;
use App\Modules\Parties\Domain\SupplierContact;
use App\Modules\Parties\Domain\SupplierDocument;
use App\Modules\Parties\Domain\SupplierNote;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SupplierDemoSeeder extends Seeder
{
    private array $createdFiles = [];

    public function run(): void
    {
        $this->assertSafeTarget();
        $this->createdFiles = [];

        try {
            DB::transaction(function (): void {
                DB::select('SELECT pg_advisory_xact_lock(30313)');
                $categories = $this->seedCategories();

                foreach ($this->suppliers() as $fixture) {
                    if (Supplier::where('code', $fixture['supplier']['code'])->exists()) {
                        continue;
                    }

                    $supplier = Supplier::factory()->create([
                        ...$fixture['supplier'],
                        'category_id' => $categories[$fixture['category']],
                    ]);
                    SupplierContact::factory()->create([
                        'supplier_id' => $supplier->id,
                        'name' => $fixture['contact'],
                        'email' => strtolower(str_replace('-', '.', $fixture['supplier']['code'])).'@example.test',
                        'is_primary' => true,
                    ]);
                    SupplierAddress::factory()->create([
                        'supplier_id' => $supplier->id,
                        'label' => 'Synthetic registered office',
                        'purpose' => 'registered',
                        'city' => $fixture['supplier']['city'],
                        'line' => $fixture['street'],
                        'is_primary' => true,
                    ]);
                    SupplierAddress::factory()->create([
                        'supplier_id' => $supplier->id,
                        'label' => 'Synthetic pickup location',
                        'purpose' => 'pickup',
                        'city' => $fixture['supplier']['city'],
                        'line' => $fixture['street'],
                        'is_primary' => true,
                    ]);
                    SupplierNote::factory()->create([
                        'supplier_id' => $supplier->id,
                        'author_id' => null,
                        'body' => 'Synthetic demo note: '.$fixture['note'],
                    ]);

                    $document = SupplierDocument::factory()->make(['supplier_id' => $supplier->id]);
                    $this->createdFiles[] = $document->path;
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
            throw new RuntimeException('Supplier demo seeding is restricted to local/testing PostgreSQL databases.');
        }

        if (app()->environment('testing') && DB::connection()->getDatabaseName() !== 'erp_elec_new_test') {
            throw new RuntimeException('Testing supplier demo seeding requires the exact ERP test database.');
        }
    }

    private function seedCategories(): array
    {
        $fixtures = [
            ['DEMO-SCAT-ELECTRICAL', 'Synthetic electrical equipment suppliers'],
            ['DEMO-SCAT-CABLES', 'Synthetic cable and conductor suppliers'],
            ['DEMO-SCAT-SERVICES', 'Synthetic technical service providers'],
        ];
        $ids = [];

        foreach ($fixtures as [$code, $name]) {
            $category = SupplierCategory::where('code', $code)->first();
            if (! $category) {
                $category = SupplierCategory::factory()->create(['code' => $code, 'name' => $name, 'active' => true]);
            }
            if (! $category->active) {
                throw new RuntimeException("Cannot seed new demo suppliers into inactive category {$code}.");
            }
            $ids[$code] = $category->id;
        }

        return $ids;
    }

    private function suppliers(): array
    {
        return [
            ['category' => 'DEMO-SCAT-ELECTRICAL', 'contact' => 'Demo Contact Cairo', 'street' => '10 Synthetic Supply Road', 'note' => 'Synthetic electrical equipment supplier example.', 'supplier' => ['code' => 'DEMO-SUP-CAIRO-001', 'name' => 'Demo Cairo Electrical Supply', 'name_en' => 'Demo Cairo Electrical Supply', 'trading_name' => 'Demo Cairo Supply', 'entity_type' => 'organization', 'supplier_type' => 'distributor', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Cairo', 'phone' => '+20 2 0000 0201', 'email' => 'demo-supplier-cairo@example.test']],
            ['category' => 'DEMO-SCAT-CABLES', 'contact' => 'Demo Contact Alexandria', 'street' => '20 Synthetic Cable Avenue', 'note' => 'Synthetic cable supplier example; no catalog claims are made.', 'supplier' => ['code' => 'DEMO-SUP-ALEX-001', 'name' => 'Demo Alexandria Cable Works', 'name_en' => 'Demo Alexandria Cable Works', 'trading_name' => null, 'entity_type' => 'organization', 'supplier_type' => 'manufacturer', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Alexandria', 'phone' => '+20 3 0000 0202', 'email' => 'demo-supplier-alex@example.test']],
            ['category' => 'DEMO-SCAT-ELECTRICAL', 'contact' => 'Demo Contact Giza', 'street' => '30 Synthetic Workshop Street', 'note' => 'Inactive synthetic profile for status examples.', 'supplier' => ['code' => 'DEMO-SUP-GIZA-001', 'name' => 'Demo Giza Panel Components', 'name_en' => 'Demo Giza Panel Components', 'trading_name' => null, 'entity_type' => 'organization', 'supplier_type' => 'other', 'status' => 'inactive', 'country' => 'Egypt', 'city' => 'Giza', 'phone' => '+20 2 0000 0203', 'email' => 'demo-supplier-giza@example.test']],
            ['category' => 'DEMO-SCAT-SERVICES', 'contact' => 'Demo Service Contact Cairo', 'street' => '40 Synthetic Service Lane', 'note' => 'Synthetic service provider example.', 'supplier' => ['code' => 'DEMO-SUP-CAIRO-002', 'name' => 'Demo Cairo Technical Services', 'name_en' => 'Demo Cairo Technical Services', 'trading_name' => null, 'entity_type' => 'organization', 'supplier_type' => 'service_provider', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Cairo', 'phone' => '+20 2 0000 0204', 'email' => 'demo-service-cairo@example.test']],
            ['category' => 'DEMO-SCAT-ELECTRICAL', 'contact' => 'Demo Contact Mansoura', 'street' => '50 Synthetic Market Street', 'note' => 'Synthetic supplier record without asserted registration identifiers.', 'supplier' => ['code' => 'DEMO-SUP-MANS-001', 'name' => 'Demo Mansoura Electrical Traders', 'name_en' => 'Demo Mansoura Electrical Traders', 'trading_name' => 'Demo Mansoura Traders', 'entity_type' => 'organization', 'supplier_type' => 'distributor', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Mansoura', 'phone' => '+20 50 0000 0205', 'email' => 'demo-supplier-mansoura@example.test']],
            ['category' => 'DEMO-SCAT-SERVICES', 'contact' => 'Demo Individual Supplier Contact', 'street' => '60 Synthetic Residential Lane', 'note' => 'Synthetic individual service profile with no business identifiers assumed.', 'supplier' => ['code' => 'DEMO-SUP-INDIVIDUAL-001', 'name' => 'Demo Individual Service Supplier', 'name_en' => 'Demo Individual Service Supplier', 'trading_name' => null, 'entity_type' => 'individual', 'supplier_type' => 'service_provider', 'status' => 'active', 'country' => 'Egypt', 'city' => 'Cairo', 'phone' => null, 'email' => 'demo-individual-supplier@example.test']],
        ];
    }
}

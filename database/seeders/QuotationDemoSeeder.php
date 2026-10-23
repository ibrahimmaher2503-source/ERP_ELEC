<?php

namespace Database\Seeders;

use App\Modules\Parties\Application\Contracts\CustomersApi;
use App\Modules\Parties\Application\Contracts\SearchCustomersRequest;
use App\Modules\Products\Application\Contracts\GetProductRequest;
use App\Modules\Products\Application\Contracts\ListMastersRequest;
use App\Modules\Products\Application\Contracts\ProductsApi;
use App\Modules\Sales\Application\DraftAmounts;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;

class QuotationDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Quotation demo data may only be seeded in local or testing environments.');
        }

        $actorId = (int) env('SALES_QUOTATION_DEMO_ACTOR_ID', 2);
        $actor = DB::table('users')->where('id', $actorId)->where('active', true)->first();
        if (! $actor) {
            throw new \RuntimeException('Configure SALES_QUOTATION_DEMO_ACTOR_ID to an existing active account authorized for M02 and M03 lookups.');
        }

        // Read customer and product identity only through the owning modules' public contracts.
        $customers = app(CustomersApi::class)->searchCustomers(new SearchCustomersRequest($actorId, 'DEMO-', 50));
        $products = app(ProductsApi::class)->listMasters(new ListMastersRequest($actorId, 'products', 'DEMO-PROD-', true, 1, 50));
        $customer = $customers[0] ?? null;
        $productRow = $products->items[0] ?? null;
        if ($customer === null || $productRow === null) {
            throw new \RuntimeException('Quotation demo seeding requires an active DEMO customer and DEMO product visible through M03/M02 contracts.');
        }

        $product = app(ProductsApi::class)->getProduct(new GetProductRequest($actorId, (int) $productRow['id']))->product;
        $unitId = (int) $product['base_unit_id'];
        $units = app(ProductsApi::class)->listMasters(new ListMastersRequest($actorId, 'units', '', true, 1, 100))->items;
        $unit = collect($units)->firstWhere('id', $unitId);
        if ($unit === null) {
            throw new \RuntimeException('The demo quotation product base unit is unavailable through the M02 public contract.');
        }

        $snapshot = [
            'id' => (int) $product['id'],
            'sku' => (string) $product['sku'],
            'name' => (string) $product['name'],
            'description' => (string) ($product['short_description'] ?? ''),
            'unit_id' => $unitId,
            'unit_code' => (string) $unit['code'],
            'unit_name' => (string) $unit['name'],
            'version' => (int) $product['version'],
        ];

        DB::transaction(function () use ($customer, $snapshot): void {
            $draftKey = Uuid::uuid5(Uuid::NAMESPACE_URL, 'erp-elec-new:demo:quotation:draft:001')->toString();
            if (DB::table('sales_quotations')->where('draft_key', $draftKey)->exists()) {
                return;
            }

            $now = now();
            $quoteId = DB::table('sales_quotations')->insertGetId([
                'draft_key' => $draftKey,
                'number' => null,
                'customer_id' => $customer->id,
                'customer_snapshot' => json_encode([
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'entityType' => $customer->entityType,
                    'status' => $customer->status,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'project_name' => null,
                'rfq_reference' => 'DEMO-RFQ-001',
                'quotation_date' => now('Africa/Cairo')->toDateString(),
                'valid_until' => null,
                'currency' => 'EGP',
                'status' => 'draft',
                'revision' => 1,
                'version' => 1,
                // Demo fixtures deliberately carry no claimed human authorship.
                'created_by' => null,
                'updated_by' => null,
                'copied_from_id' => null,
                'discount_mode' => 'percent',
                'discount_value' => '0.00000000',
                'payment_terms' => null,
                'delivery_terms' => null,
                'notes' => 'Synthetic demo draft. No approved price, tax, approval, issue, or posting is implied.',
                'exception_reason' => null,
                'calculation' => json_encode(app(DraftAmounts::class)->calculate([[
                    'quantity' => '1.00000000',
                    'requested_price' => null,
                    'suggested_price' => null,
                    'discount_mode' => 'percent',
                    'discount_value' => '0.00000000',
                ]]), JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('sales_quotation_lines')->insert([
                'quotation_id' => $quoteId,
                'position' => 1,
                'product_id' => $snapshot['id'],
                'unit_id' => $snapshot['unit_id'],
                'product_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'description' => $snapshot['name'],
                'quantity' => '1.00000000',
                'suggested_price' => null,
                'requested_price' => null,
                'price_source' => null,
                'discount_mode' => 'percent',
                'discount_value' => '0.00000000',
                'override_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $path = 'sales/quotations/internal/demo/'.$quoteId.'/demo-rfq-reference.pdf';
            $contents = self::syntheticPdf();
            if (! Storage::disk('local')->put($path, $contents)) {
                throw new \RuntimeException('Unable to write private quotation demo attachment.');
            }
            DB::table('sales_quotation_attachments')->insert([
                'quotation_id' => $quoteId,
                'name' => 'demo-rfq-reference.pdf',
                'path' => $path,
                'mime' => 'application/pdf',
                'size' => strlen($contents),
                'uploaded_by' => null,
                'sha256' => hash('sha256', $contents),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private static function syntheticPdf(): string
    {
        $stream = "BT\n/F1 16 Tf\n32 65 Td\n(Synthetic internal quotation RFQ) Tj\nET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 320 120] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}endstream",
        ];

        $pdf = "%PDF-1.4\n% Private synthetic fixture\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n0 ".count($offsets)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer'."\n<< /Size ".count($offsets)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }
}

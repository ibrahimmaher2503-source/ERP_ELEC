<?php

namespace Database\Seeders;

use App\Modules\Inventory\Domain\InventoryDocument;
use App\Modules\Inventory\Domain\InventoryDocumentLine;
use App\Modules\Inventory\Domain\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Local/testing only.');
        }
        DB::transaction(function () {
            $branches = DB::table('branches')->where('code', 'like', 'DEMO-EG%')->where('active', true)->orderBy('id')->limit(4)->get();
            if ($branches->isEmpty()) {
                throw new \RuntimeException('Run Egyptian market master fixtures first.');
            }
            foreach ($branches as $i => $b) {
                $code = 'DEMO-EG-WH-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                if (Warehouse::where('code', $code)->exists()) {
                    continue;
                }
                $w = Warehouse::factory()->create(['code' => $code, 'branch_id' => $b->id, 'name' => 'مستودع تجريبي — '.$b->name]);
                $doc = InventoryDocument::factory()->create(['warehouse_id' => $w->id]);
                InventoryDocumentLine::factory()->create(['document_id' => $doc->id]);
            }
        });
    }
}

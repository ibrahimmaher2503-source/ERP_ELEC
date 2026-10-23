<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Inventory\Domain\InventoryDocument;
use App\Modules\Inventory\Domain\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InventoryDocumentFactory extends Factory
{
    protected $model = InventoryDocument::class;

    public function definition(): array
    {
        $actor = User::where('active', false)->orderBy('id')->value('id');
        if (! $actor) {
            throw new \RuntimeException('Draft fixtures require an existing disabled synthetic user. No credentials or privileges are generated.');
        }

        return ['creation_key' => (string) Str::uuid(), 'kind' => 'count', 'status' => 'draft', 'warehouse_id' => Warehouse::factory(), 'reason' => 'جرد تجريبي لمعدات كهربائية — لا يغير الأرصدة', 'created_by' => $actor, 'version' => 1, 'cutoff' => 0];
    }
}

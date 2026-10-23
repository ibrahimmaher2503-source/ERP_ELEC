<?php

namespace Database\Factories;

use App\Modules\Purchasing\Domain\SupplierOffer;
use App\Modules\Purchasing\Domain\SupplierOfferFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupplierOfferFileFactory extends Factory
{
    protected $model = SupplierOfferFile::class;

    public function definition(): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Private fixtures are local/testing only.');
        }

        return ['offer_id' => SupplierOffer::factory(), 'original_name' => 'DEMO-EG-illustration.png', 'mime' => 'image/png', 'created_at' => now()];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SupplierOfferFile $file) {
            $offer = SupplierOffer::findOrFail($file->offer_id);
            $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a5XcAAAAASUVORK5CYII=');
            $file->path = 'purchasing/fixtures/'.Str::uuid().'.png';
            Storage::disk('local')->put($file->path, $bytes);
            $file->size = strlen($bytes);
            $file->sha256 = hash('sha256', $bytes);
            $file->created_by = $offer->created_by;
        });
    }
}

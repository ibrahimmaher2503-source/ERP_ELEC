<?php

namespace Database\Factories;

use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @extends Factory<ProductMedia> */
class ProductMediaFactory extends Factory
{
    protected $model = ProductMedia::class;

    public function definition(): array
    {
        $png = $this->illustration();

        return [
            'product_id' => Product::factory(),
            'path' => function (array $attributes) use ($png): string {
                $path = 'product-media/'.$attributes['product_id'].'/demo-illustration-'.Str::uuid().'.png';
                if (! Storage::disk('local')->put($path, $png)) {
                    throw new \RuntimeException('Unable to write generated demo product image.');
                }

                return $path;
            },
            'name' => 'demo-illustration.png',
            'mime' => 'image/png',
            'size' => strlen($png),
            'is_image' => true,
            'removed_at' => null,
            'created_by' => fn (array $attributes) => Product::findOrFail($attributes['product_id'])->created_by,
        ];
    }

    private function illustration(): string
    {
        $image = imagecreatetruecolor(160, 160);
        $background = imagecolorallocate($image, 246, 249, 252);
        $copper = imagecolorallocate($image, 192, 91, 45);
        $blue = imagecolorallocate($image, 40, 94, 157);
        $dark = imagecolorallocate($image, 39, 51, 63);
        imagefilledrectangle($image, 0, 0, 159, 159, $background);
        imagefilledrectangle($image, 26, 42, 133, 118, $dark);
        imagefilledrectangle($image, 39, 54, 67, 106, $copper);
        imagefilledrectangle($image, 92, 54, 120, 106, $blue);
        imagefilledellipse($image, 53, 80, 18, 18, $background);
        imagefilledellipse($image, 106, 80, 18, 18, $background);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        if (! is_string($png) || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new \RuntimeException('Unable to generate demo product image.');
        }

        return $png;
    }
}

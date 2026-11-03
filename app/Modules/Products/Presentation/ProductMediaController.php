<?php

namespace App\Modules\Products\Presentation;

use App\Modules\Products\Application\ProductAccess;
use App\Modules\Products\Domain\ProductMedia;
use Illuminate\Support\Facades\Storage;

class ProductMediaController
{
    public function __invoke(int $media)
    {
        app(ProductAccess::class)->authorize(auth()->id(), 'M02.product_view');
        $record = ProductMedia::whereNull('removed_at')->findOrFail($media);
        abort_unless(str_starts_with($record->path, 'product-media/'.$record->product_id.'/') && Storage::disk('local')->exists($record->path), 404);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '_', str_replace(['/', '\\'], '_', $record->name));
        $name = trim($name) !== '' ? $name : 'attachment';
        $headers = ['Content-Type' => $record->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"];
        if (request()->routeIs('products.media.download') || ! $record->is_image) {
            return Storage::disk('local')->download($record->path, $name, $headers);
        }

        return Storage::disk('local')->response($record->path, $name, $headers, 'inline');
    }
}

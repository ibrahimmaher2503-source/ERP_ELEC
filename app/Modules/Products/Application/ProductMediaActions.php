<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Domain\Product;
use App\Modules\Products\Domain\ProductChanged;
use App\Modules\Products\Domain\ProductMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductMediaActions
{
    public function upload(int $actorId, int $productId, UploadedFile $file): ProductMedia
    {
        app(ProductAccess::class)->authorize($actorId, 'M02.media_manage');
        app(ProductAccess::class)->authorize($actorId, 'M02.product_view');
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf']])->validate();
        $mime = $file->getMimeType();
        $isImage = str_starts_with($mime, 'image/');
        if ($isImage) {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
                throw ValidationException::withMessages(['uploads' => 'الصورة غير صالحة أو أكبر من 6000 بكسل.']);
            }
        }
        $path = null;
        try {
            return DB::transaction(function () use ($actorId, $productId, $file, $mime, $isImage, &$path) {
                DB::select('SELECT pg_advisory_xact_lock(20401)');
                app(ProductAccess::class)->authorize($actorId, 'M02.media_manage');
                app(ProductAccess::class)->authorize($actorId, 'M02.product_view');
                Product::lockForUpdate()->findOrFail($productId);
                if (ProductMedia::where('product_id', $productId)->whereNull('removed_at')->count() >= 20) {
                    throw ValidationException::withMessages(['uploads' => 'الحد الأقصى 20 مرفقًا لكل صنف.']);
                }
                $path = $file->store('product-media/'.$productId, 'local');
                if (! $path) {
                    throw new \RuntimeException('تعذر حفظ المرفق.');
                }
                $name = mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '_', basename(str_replace('\\', '/', $file->getClientOriginalName()))), 0, 200);
                $media = ProductMedia::create(['product_id' => $productId, 'path' => $path, 'name' => $name ?: 'attachment', 'mime' => $mime, 'size' => $file->getSize(), 'is_image' => $isImage, 'created_by' => $actorId]);
                event(new ProductChanged($actorId, 'product', $productId, 'media_added', null, ['media_id' => $media->id, 'name' => $media->name, 'mime' => $mime]));

                return $media;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    public function remove(int $actorId, int $mediaId): void
    {
        DB::transaction(function () use ($actorId, $mediaId) {
            DB::select('SELECT pg_advisory_xact_lock(20401)');
            app(ProductAccess::class)->authorize($actorId, 'M02.media_manage');
            app(ProductAccess::class)->authorize($actorId, 'M02.product_view');
            $media = ProductMedia::whereNull('removed_at')->lockForUpdate()->findOrFail($mediaId);
            $media->update(['removed_at' => now()]);
            event(new ProductChanged($actorId, 'product', $media->product_id, 'media_removed', ['media_id' => $mediaId, 'name' => $media->name], ['removed' => true]));
        });
    }
}

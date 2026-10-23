<?php

namespace Database\Factories;

use App\Modules\Parties\Domain\Supplier;
use App\Modules\Parties\Domain\SupplierDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @extends Factory<SupplierDocument> */
class SupplierDocumentFactory extends Factory
{
    protected $model = SupplierDocument::class;

    public function definition(): array
    {
        $contents = $this->syntheticPdf();
        $name = 'demo-supplier-reference-'.Str::lower(Str::random(8)).'.pdf';

        return [
            'supplier_id' => Supplier::factory(),
            'name' => $name,
            'path' => function (array $attributes) use ($name, $contents): string {
                $path = 'suppliers/'.$attributes['supplier_id'].'/'.$name;
                if (! Storage::disk('local')->put($path, $contents)) {
                    throw new \RuntimeException('Unable to write private synthetic supplier PDF.');
                }

                return $path;
            },
            'mime' => 'application/pdf',
            'size' => strlen($contents),
        ];
    }

    private function syntheticPdf(): string
    {
        $stream = "BT\n/F1 18 Tf\n32 65 Td\n(Synthetic supplier demo reference) Tj\nET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 120] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
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

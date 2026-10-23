<?php

namespace Database\Factories;

use App\Modules\Sales\Domain\Quotation;
use App\Modules\Sales\Domain\QuotationAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** @extends Factory<QuotationAttachment> */
class QuotationAttachmentFactory extends Factory
{
    protected $model = QuotationAttachment::class;

    public function definition(): array
    {
        $name = 'demo-internal-reference-'.Str::lower(Str::random(8)).'.pdf';
        $contents = $this->syntheticPdf();

        return [
            'quotation_id' => Quotation::factory(),
            'name' => $name,
            'path' => function (array $attributes) use ($contents, $name): string {
                $path = 'sales/quotations/internal/demo/'.$attributes['quotation_id'].'/'.$name;
                if (! Storage::disk('local')->put($path, $contents)) {
                    throw new \RuntimeException('Unable to write private synthetic quotation PDF.');
                }

                return $path;
            },
            'mime' => 'application/pdf',
            'size' => strlen($contents),
            'uploaded_by' => null,
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function syntheticPdf(): string
    {
        $stream = "BT\n/F1 16 Tf\n32 65 Td\n(Synthetic internal quotation reference) Tj\nET\n";
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

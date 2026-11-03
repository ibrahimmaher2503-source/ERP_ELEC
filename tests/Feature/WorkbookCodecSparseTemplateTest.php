<?php

namespace Tests\Feature;

use App\Modules\Products\Application\ProductDataDefinition;
use App\Modules\System\Infrastructure\Spreadsheets\WorkbookCodec;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Tests\TestCase;

final class WorkbookCodecSparseTemplateTest extends TestCase
{
    public function test_original_500_row_template_reads_sparse_empty_rows_without_creating_a_large_cell_cache(): void
    {
        $codec = app(WorkbookCodec::class);
        $definition = new ProductDataDefinition;
        $path = $codec->template('products', $definition->version(), $definition->columns(), []);

        try {
            $reader = new Xlsx;
            $workbook = $reader->load($path);
            try {
                self::assertSame(501, $workbook->getSheetByName('Data')?->getHighestRow(), 'The untrimmed import template must retain its 500 formatted data rows.');
            } finally {
                $workbook->disconnectWorksheets();
            }

            $before = hash_file('sha256', $path);
            $started = hrtime(true);
            $rows = $codec->read($path, 'products', $definition->version(), $definition->columns());
            $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;

            self::assertSame([], $rows, 'Blank formatted template rows must not become import records.');
            self::assertSame($before, hash_file('sha256', $path), 'Reading a sparse workbook must not rewrite its archive.');
            self::assertLessThan(10.0, $elapsedSeconds, 'Reading 500 sparse formatted rows must avoid expanding every absent cell in the disk cache.');
        } finally {
            @unlink($path);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\System\Infrastructure\Spreadsheets;

use Closure;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Generator;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LengthException;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithProperties;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Writer as ExcelWriter;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Shared\StringHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Stringable;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

final class WorkbookCodec
{
    private const REQUIRED_SHEETS = ['Data', 'Instructions', 'Lookups', '_erp_template'];

    private const MAX_COLUMNS = 100;

    private const MAX_IMPORT_ROWS = 500;

    private const MAX_EXPORT_ROWS = 10_000;

    private const MAX_ARCHIVE_BYTES = 30 * 1024 * 1024;

    private const MAX_ARCHIVE_ENTRIES = 1_000;

    private const OFFICE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    public function template(string $entity, string $version, array $columns, array $lookups): string
    {
        $this->validateIdentity($entity, $version);
        $columns = $this->validateColumns($columns);
        $lookupColumns = $this->normalizeLookups($columns, $lookups);
        $path = $this->newPrivateFile('xlsx');

        try {
            $this->withDiskCellCache(function () use ($entity, $version, $columns, $lookupColumns, $path): void {
                $workbook = new Spreadsheet;

                try {
                    $workbook->getProperties()->setTitle('قالب استيراد البيانات');

                    $data = $workbook->getActiveSheet()->setTitle('Data');
                    $instructions = $workbook->createSheet()->setTitle('Instructions');
                    $lookupSheet = $workbook->createSheet()->setTitle('Lookups');
                    $metadata = $workbook->createSheet()->setTitle('_erp_template');

                    $this->buildDataSheet($workbook, $data, $columns, $lookupColumns);
                    $this->buildInstructionsSheet($instructions, $entity, $version, $columns);
                    $this->buildLookupsSheet($workbook, $data, $lookupSheet, $columns, $lookupColumns);
                    $this->writeString($metadata, 'A1', 'entity');
                    $this->writeString($metadata, 'B1', $entity);
                    $this->writeString($metadata, 'A2', 'version');
                    $this->writeString($metadata, 'B2', $version);
                    $metadata->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
                    $metadata->getProtection()->setSheet(true);

                    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook);
                    $writer->save($path);
                } finally {
                    $workbook->disconnectWorksheets();
                }
            });

            return $path;
        } catch (Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
    }

    /**
     * @return list<array{row_number: int, data: array<string, mixed>}>
     */
    public function read(string $path, string $entity, string $version, array $columns): array
    {
        $this->validateIdentity($entity, $version);
        $columns = $this->validateColumns($columns);
        $inspection = $this->inspectWorkbook($path);

        if ($inspection['data_columns'] > count($columns)) {
            throw new UnexpectedValueException('The Data sheet contains columns that are not in this template.');
        }

        if ($inspection['data_rows'] - 1 > self::MAX_IMPORT_ROWS) {
            throw new LengthException('The workbook may contain at most 500 data rows.');
        }

        return $this->withDiskCellCache(function () use ($path, $entity, $version, $columns, $inspection): array {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setIgnoreRowsWithNoCells(true);
            $reader->setLoadSheetsOnly(['Data', '_erp_template']);
            $workbook = $reader->load($path);

            try {
                $metadata = $workbook->getSheetByName('_erp_template');
                $data = $workbook->getSheetByName('Data');

                if ($metadata === null || $data === null) {
                    throw new UnexpectedValueException('The workbook is missing a required sheet.');
                }

                if ($this->rawString($metadata->getCell('A1')) !== 'entity'
                    || $this->rawString($metadata->getCell('B1')) !== $entity
                    || $this->rawString($metadata->getCell('A2')) !== 'version'
                    || $this->rawString($metadata->getCell('B2')) !== $version) {
                    throw new UnexpectedValueException('This workbook belongs to a different entity or template version.');
                }

                $expectedKeys = array_column($columns, 'key');
                $actualHeaders = [];
                for ($column = 1; $column <= count($expectedKeys); $column++) {
                    $headerCell = $data->getCell([$column, 1]);
                    $actualHeaders[] = $this->rawString($headerCell);
                }

                if (count(array_unique($actualHeaders, SORT_STRING)) !== count($actualHeaders)) {
                    throw new UnexpectedValueException('The Data sheet has duplicate column headers.');
                }

                if ($actualHeaders !== $expectedKeys) {
                    throw new UnexpectedValueException('The Data sheet headers must exactly match the technical keys in this template.');
                }

                $rows = [];
                $highestRow = max(1, $inspection['data_rows']);

                for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                    $row = [];
                    $hasValue = false;

                    foreach ($expectedKeys as $index => $key) {
                        $cell = $data->getCell([$index + 1, $rowNumber]);

                        if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                            throw new UnexpectedValueException("Formula cells are not allowed in Data (row {$rowNumber}).");
                        }

                        $value = $cell->getValue();
                        if ($value instanceof RichText) {
                            $value = $value->getPlainText();
                        }

                        if (! is_null($value) && ! is_scalar($value)) {
                            throw new UnexpectedValueException("Unsupported cell value in Data (row {$rowNumber}).");
                        }

                        $hasValue = $hasValue || ($value !== null && $value !== '');
                        $row[$key] = $value;
                    }

                    if ($hasValue) {
                        $rows[] = ['row_number' => $rowNumber, 'data' => $row];
                    }
                }

                if (count($rows) > self::MAX_IMPORT_ROWS) {
                    throw new LengthException('The workbook may contain at most 500 data rows.');
                }

                return $rows;
            } finally {
                $workbook->disconnectWorksheets();
            }
        });
    }

    /**
     * @param  array<int|string, string>  $headers  A list of headings or a technical-key-to-label map.
     */
    public function write(string $name, array $headers, iterable $rows): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('The workbook name must contain 1 to 255 characters.');
        }

        if ($headers === [] || count($headers) > self::MAX_COLUMNS) {
            throw new \InvalidArgumentException('Exports must contain between 1 and 100 columns.');
        }

        foreach ($headers as $key => $label) {
            if (! is_string($label) || $label === '') {
                throw new \InvalidArgumentException('Export headings must be non-empty strings.');
            }
            if (! is_int($key) && (! is_string($key) || $key === '')) {
                throw new \InvalidArgumentException('Export heading keys must be strings.');
            }
        }

        $headingKeys = array_keys($headers);
        $headings = array_values($headers);
        $mapRow = fn (mixed $row): array => $this->exportRow($row, $headingKeys);
        $export = new class($name, $headings, $rows, $mapRow) implements FromGenerator, WithCustomValueBinder, WithHeadings, WithProperties, WithTitle
        {
            /** @param array<int, string> $headings
             * @param  iterable<array-key, mixed>  $rows
             */
            public function __construct(
                private string $name,
                private array $headings,
                private iterable $rows,
                private Closure $mapRow,
            ) {}

            public function generator(): Generator
            {
                $count = 0;
                foreach ($this->rows as $row) {
                    if (++$count > 10_000) {
                        throw new LengthException('Exports may contain at most 10,000 data rows.');
                    }

                    yield ($this->mapRow)($row);
                }
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function properties(): array
            {
                return ['title' => $this->name];
            }

            public function title(): string
            {
                return 'Data';
            }

            public function bindValue(Cell $cell, mixed $value): bool
            {
                if ($value === null) {
                    $cell->setValueExplicit(null, DataType::TYPE_NULL);
                } elseif (is_scalar($value) || $value instanceof Stringable) {
                    $cell->setValueExplicit(StringHelper::sanitizeUTF8((string) $value), DataType::TYPE_STRING);
                } else {
                    throw new \InvalidArgumentException('Export cells must contain scalar values.');
                }

                return true;
            }
        };

        $temporaryDirectory = $this->privateDirectory('tmp');
        $cachePath = config('excel.temporary_files.local_path');
        $permissions = config('excel.temporary_files.local_permissions.file');

        return $this->withDiskCellCache(function () use ($export, $temporaryDirectory, $cachePath, $permissions): string {
            config([
                'excel.temporary_files.local_path' => $temporaryDirectory,
                'excel.temporary_files.local_permissions.file' => 0600,
            ]);

            try {
                $temporaryFile = app(ExcelWriter::class)->export($export, ExcelFormat::XLSX);
                $path = $temporaryFile->getLocalPath();
                @chmod($path, 0600);

                return $path;
            } finally {
                config([
                    'excel.temporary_files.local_path' => $cachePath,
                    'excel.temporary_files.local_permissions.file' => $permissions,
                ]);
            }
        });
    }

    private function buildDataSheet(Spreadsheet $workbook, Worksheet $data, array $columns, array $lookups): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $data->setRightToLeft(true);
        $data->freezePane('A2');
        $data->setAutoFilter("A1:{$lastColumn}".(self::MAX_IMPORT_ROWS + 1));

        foreach ($columns as $index => $column) {
            $columnNumber = $index + 1;
            $columnLetter = Coordinate::stringFromColumnIndex($columnNumber);
            $header = $data->getCell("{$columnLetter}1");
            $header->setValueExplicit($column['key'], DataType::TYPE_STRING);
            $commentText = new RichText;
            $commentText->createTextRun(
                $column['label']."\n".$column['description']."\n".
                ($column['required'] ? 'مطلوب' : 'اختياري').' — '.$column['type']."\n".
                'مثال: '.(string) ($column['example'] ?? '')
            );
            $data->getComment("{$columnLetter}1")->setAuthor('ERP')->setText($commentText);
            $header->getStyle()->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '19324D']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $data->getColumnDimension($columnLetter)->setWidth(min(34, max(16, mb_strlen($column['key']), mb_strlen($column['label']) + 2)));

            if ($lookups[$column['key']] === []) {
                continue;
            }

            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(! $column['required'])
                ->setShowInputMessage(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('قيمة غير مسموحة')
                ->setError('اختر قيمة من القائمة. سيتحقق الخادم من القيمة عند الاستيراد.')
                ->setPromptTitle($column['label'])
                ->setPrompt('القائمة للمساعدة فقط؛ التحقق النهائي يتم عند الاستيراد.')
                ->setFormula1('=erp_lookup_'.($index + 1));

            for ($row = 2; $row <= self::MAX_IMPORT_ROWS + 1; $row++) {
                $data->setDataValidation("{$columnLetter}{$row}", clone $validation);
            }
        }

        $data->getRowDimension(1)->setRowHeight(32);
    }

    private function buildInstructionsSheet(Worksheet $sheet, string $entity, string $version, array $columns): void
    {
        $sheet->setRightToLeft(true);
        $lines = [
            ['تعليمات استيراد البيانات'],
            ['املأ البيانات في ورقة Data واحتفظ بالصف الأول كما هو.'],
            ['لا تغيّر مفاتيح الأعمدة التقنية ولا تضف أعمدة أو أوراق.'],
            ['املأ الحقول المطلوبة، وتأكد من القيم قبل رفع الملف.'],
            ['القوائم المرجعية للمساعدة فقط؛ الخادم يتحقق من كل قيمة عند الاستيراد.'],
            ['الكيان', $entity],
            ['إصدار القالب', $version],
            [],
            ['مفتاح الحقل', 'اسم الحقل', 'مطلوب', 'النوع', 'مثال', 'الوصف'],
        ];

        foreach ($lines as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 1);
                $this->writeString($sheet, $coordinate, (string) $value);
            }
        }

        foreach ($columns as $index => $column) {
            $rowNumber = $index + 10;
            $values = [
                $column['key'],
                $column['label'],
                $column['required'] ? 'نعم' : 'لا',
                $column['type'],
                $column['example'] === null ? '' : (string) $column['example'],
                $column['description'],
            ];

            foreach ($values as $columnIndex => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).$rowNumber;
                $this->writeString($sheet, $coordinate, $value);
            }
        }

        $sheet->getStyle('A1:F1')->getFont()->setBold(true)->setSize(15);
        $sheet->getStyle('A9:F9')->getFont()->setBold(true);
        foreach (['A' => 24, 'B' => 28, 'C' => 14, 'D' => 18, 'E' => 24, 'F' => 50] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A10');
        $sheet->setAutoFilter('A9:F'.(count($columns) + 9));
    }

    private function buildLookupsSheet(Spreadsheet $workbook, Worksheet $data, Worksheet $sheet, array $columns, array $lookups): void
    {
        $sheet->setRightToLeft(true);
        $columnIndex = 0;

        foreach ($columns as $index => $column) {
            $options = $lookups[$column['key']];
            if ($options === []) {
                continue;
            }

            $columnIndex++;
            $valueColumn = Coordinate::stringFromColumnIndex($columnIndex);
            $labelColumn = Coordinate::stringFromColumnIndex($columnIndex + 1);
            $columnIndex++;

            $this->writeString($sheet, "{$valueColumn}1", $column['key']);
            $this->writeString($sheet, "{$labelColumn}1", $column['label'].' — الاسم');
            $sheet->getColumnDimension($valueColumn)->setWidth(24);
            $sheet->getColumnDimension($labelColumn)->setWidth(32);

            foreach ($options as $optionIndex => [$value, $label]) {
                $rowNumber = $optionIndex + 2;
                $this->writeString($sheet, "{$valueColumn}{$rowNumber}", $value);
                $this->writeString($sheet, "{$labelColumn}{$rowNumber}", $label);
            }

            $lastRow = count($options) + 1;
            $range = "'Lookups'!\${$valueColumn}\$2:\${$valueColumn}\${$lastRow}";
            $workbook->addNamedRange(new NamedRange('erp_lookup_'.($index + 1), $sheet, $range));
        }

        if ($columnIndex === 0) {
            $this->writeString($sheet, 'A1', 'لا توجد قوائم مرجعية');
            $sheet->getColumnDimension('A')->setWidth(32);
        }

        $sheet->getStyle('1:1')->getFont()->setBold(true);
    }

    /**
     * @return array<string, list<array{0: string, 1: string}>>
     */
    private function normalizeLookups(array $columns, array $lookups): array
    {
        $knownKeys = array_fill_keys(array_column($columns, 'key'), true);
        foreach (array_keys($lookups) as $key) {
            if (! is_string($key) || ! isset($knownKeys[$key]) || ! is_array($lookups[$key])) {
                throw new \InvalidArgumentException('Lookup lists must be keyed by a defined technical column key.');
            }
        }

        $normalized = [];
        foreach ($columns as $column) {
            $key = $column['key'];
            $items = $lookups[$key] ?? $column['allowed'];
            if (count($items) > 1_000) {
                throw new LengthException("The lookup list for {$key} may contain at most 1,000 options.");
            }

            $pairs = [];
            $seen = [];
            foreach ($items as $itemKey => $item) {
                if (is_array($item)) {
                    $value = $item['code'] ?? $item['value'] ?? null;
                    $label = $item['name'] ?? $item['label'] ?? $value;
                } elseif (! array_is_list($items) && is_string($itemKey)) {
                    $value = $itemKey;
                    $label = $item;
                } else {
                    $value = $item;
                    $label = $item;
                }

                if (! is_scalar($value) || ! is_scalar($label)) {
                    throw new \InvalidArgumentException("Lookup options for {$key} must contain scalar code and label values.");
                }

                $value = (string) $value;
                $label = (string) $label;
                if ($value === '' || mb_strlen($value) > 32_767 || mb_strlen($label) > 32_767) {
                    throw new \InvalidArgumentException("Lookup options for {$key} must be non-empty and fit in an Excel cell.");
                }
                if (isset($seen[$value])) {
                    throw new \InvalidArgumentException("The lookup list for {$key} contains duplicate values.");
                }

                $seen[$value] = true;
                $pairs[] = [$value, $label];
            }

            $normalized[$key] = $pairs;
        }

        return $normalized;
    }

    private function validateIdentity(string $entity, string $version): void
    {
        if ($entity === '' || $version === '' || mb_strlen($entity) > 128 || mb_strlen($version) > 128) {
            throw new \InvalidArgumentException('Entity and template version must contain 1 to 128 characters.');
        }
    }

    private function validateColumns(array $columns): array
    {
        if ($columns === [] || count($columns) > self::MAX_COLUMNS || ! array_is_list($columns)) {
            throw new \InvalidArgumentException('Templates must define between 1 and 100 ordered columns.');
        }

        $seen = [];
        foreach ($columns as $column) {
            foreach (['key', 'label', 'required', 'type', 'allowed', 'example', 'description'] as $field) {
                if (! array_key_exists($field, $column)) {
                    throw new \InvalidArgumentException("Each template column must define {$field}.");
                }
            }

            if (! is_string($column['key']) || $column['key'] === '' || mb_strlen($column['key']) > 128
                || ! is_string($column['label']) || ! is_bool($column['required']) || ! is_string($column['type'])
                || ! is_array($column['allowed']) || ! is_string($column['description'])
                || ($column['example'] !== null && ! is_scalar($column['example']))) {
                throw new \InvalidArgumentException('A template column has an invalid field shape.');
            }

            if (isset($seen[$column['key']])) {
                throw new \InvalidArgumentException('Template technical column keys must be unique.');
            }

            $seen[$column['key']] = true;
        }

        return $columns;
    }

    private function inspectWorkbook(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || is_link($path)) {
            throw new \InvalidArgumentException('The workbook path must be a readable local file.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new UnexpectedValueException('The uploaded file is not a valid XLSX workbook.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new LengthException('The workbook archive may contain at most 1,000 files.');
            }

            $totalBytes = 0;
            $entryNames = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $stat = $zip->statIndex($index);
                if (! is_string($name) || ! is_array($stat) || ! isset($stat['size']) || ! is_int($stat['size']) || $stat['size'] < 0) {
                    throw new UnexpectedValueException('The workbook archive contains an invalid entry.');
                }
                if (isset($entryNames[$name])) {
                    throw new UnexpectedValueException('The workbook archive contains duplicate entries.');
                }
                $entryNames[$name] = true;
                $lowerName = strtolower($name);
                if (str_contains($lowerName, 'vbaproject.bin') || str_contains($lowerName, 'macrosheets/')
                    || str_starts_with($lowerName, 'xl/externallinks/')) {
                    throw new UnexpectedValueException('Macros and external workbook links are not allowed.');
                }

                $totalBytes += $stat['size'];
                if ($totalBytes > self::MAX_ARCHIVE_BYTES) {
                    throw new LengthException('The workbook archive may expand to at most 30 MiB.');
                }
            }

            $contentTypes = $zip->getFromName('[Content_Types].xml');
            if (! is_string($contentTypes)
                || preg_match('/macroEnabled|vbaProject|sheet\.macroSheet/i', $contentTypes) === 1) {
                throw new UnexpectedValueException('Macros and macro-enabled workbooks are not allowed.');
            }

            foreach ($entryNames as $name => $_) {
                if (! str_ends_with(strtolower($name), '.rels')) {
                    continue;
                }

                $relationships = $zip->getFromName($name);
                if (! is_string($relationships)) {
                    throw new UnexpectedValueException('The workbook contains an unreadable relationships file.');
                }
                $document = $this->xmlDocument($relationships);
                $xpath = new DOMXPath($document);
                foreach ($xpath->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
                    if (! $relationship instanceof DOMElement) {
                        continue;
                    }

                    $target = $relationship->getAttribute('Target');
                    $targetMode = strtolower($relationship->getAttribute('TargetMode'));
                    if ($targetMode === 'external' || parse_url($target, PHP_URL_SCHEME) !== null || str_starts_with($target, '//')) {
                        throw new UnexpectedValueException('External hyperlinks and relationships are not allowed.');
                    }
                }
            }

            $workbookXml = $zip->getFromName('xl/workbook.xml');
            $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if (! is_string($workbookXml) || ! is_string($relationshipsXml)) {
                throw new UnexpectedValueException('The workbook is missing its workbook metadata.');
            }

            $workbookDocument = $this->xmlDocument($workbookXml);
            $workbookXPath = new DOMXPath($workbookDocument);
            $relationshipsDocument = $this->xmlDocument($relationshipsXml);
            $relationshipsXPath = new DOMXPath($relationshipsDocument);
            $relationshipMap = [];
            foreach ($relationshipsXPath->query('//*[local-name()="Relationship"]') ?: [] as $relationship) {
                if ($relationship instanceof DOMElement) {
                    $relationshipMap[$relationship->getAttribute('Id')] = $relationship;
                }
            }

            $sheetEntries = [];
            foreach ($workbookXPath->query('//*[local-name()="sheet"]') ?: [] as $sheet) {
                if (! $sheet instanceof DOMElement) {
                    continue;
                }
                $name = $sheet->getAttribute('name');
                $relationshipId = $sheet->getAttributeNS(self::OFFICE_RELATIONSHIPS, 'id');
                $relationship = $relationshipMap[$relationshipId] ?? null;
                if ($name === '' || ! $relationship instanceof DOMElement
                    || ! str_ends_with($relationship->getAttribute('Type'), '/worksheet')) {
                    throw new UnexpectedValueException('The workbook contains an invalid worksheet reference.');
                }
                if (isset($sheetEntries[$name])) {
                    throw new UnexpectedValueException('The workbook contains duplicate sheet names.');
                }

                $entry = $this->resolveZipTarget('xl/workbook.xml', $relationship->getAttribute('Target'));
                if (! isset($entryNames[$entry])) {
                    throw new UnexpectedValueException('The workbook references a missing worksheet.');
                }
                $sheetEntries[$name] = $entry;
            }

            $actualSheets = array_keys($sheetEntries);
            sort($actualSheets);
            $requiredSheets = self::REQUIRED_SHEETS;
            sort($requiredSheets);
            if ($actualSheets !== $requiredSheets) {
                throw new UnexpectedValueException('The workbook must contain exactly Data, Instructions, Lookups, and _erp_template sheets.');
            }

            foreach ($sheetEntries as $entry) {
                $sheetXml = $zip->getFromName($entry);
                if (! is_string($sheetXml)) {
                    throw new UnexpectedValueException('The workbook contains an unreadable worksheet.');
                }
                $this->rejectExternalLocations($sheetXml);
            }

            $dataXml = $zip->getFromName($sheetEntries['Data']);
            if (! is_string($dataXml)) {
                throw new UnexpectedValueException('The Data sheet is missing.');
            }
            $bounds = $this->inspectDataSheet($dataXml);

            return [
                'data_rows' => $bounds['rows'],
                'data_columns' => $bounds['columns'],
            ];
        } finally {
            $zip->close();
        }
    }

    private function inspectDataSheet(string $xml): array
    {
        $document = $this->xmlDocument($xml);
        $xpath = new DOMXPath($document);
        $maxRow = 1;
        $maxColumn = 1;

        foreach ($xpath->query('//*[local-name()="dimension"]') ?: [] as $dimension) {
            if ($dimension instanceof DOMElement && $dimension->hasAttribute('ref')) {
                foreach (explode(':', $dimension->getAttribute('ref')) as $coordinate) {
                    [$row, $column] = $this->coordinateBounds($coordinate);
                    $maxRow = max($maxRow, $row);
                    $maxColumn = max($maxColumn, $column);
                }
            }
        }

        foreach ($xpath->query('//*[local-name()="row"]') ?: [] as $row) {
            if ($row instanceof DOMElement && $row->hasAttribute('r')) {
                $maxRow = max($maxRow, $this->positiveInteger($row->getAttribute('r'), 'row'));
            }
        }

        foreach ($xpath->query('//*[local-name()="col"]') ?: [] as $column) {
            if ($column instanceof DOMElement && $column->hasAttribute('max')) {
                $maxColumn = max($maxColumn, $this->positiveInteger($column->getAttribute('max'), 'column'));
            }
        }

        foreach ($xpath->query('//*[local-name()="c"]') ?: [] as $cell) {
            if (! $cell instanceof DOMElement || ! $cell->hasAttribute('r')) {
                throw new UnexpectedValueException('The Data sheet contains a cell without a coordinate.');
            }
            [$row, $column] = $this->coordinateBounds($cell->getAttribute('r'));
            $maxRow = max($maxRow, $row);
            $maxColumn = max($maxColumn, $column);

            if ($xpath->query('./*[local-name()="f"]', $cell)->length > 0) {
                throw new UnexpectedValueException("Formula cells are not allowed in Data (row {$row}).");
            }
        }

        if ($maxRow > self::MAX_IMPORT_ROWS + 1) {
            throw new LengthException('The Data sheet may contain at most 500 data rows.');
        }
        if ($maxColumn > self::MAX_COLUMNS) {
            throw new LengthException('The Data sheet may contain at most 100 columns.');
        }

        return ['rows' => $maxRow, 'columns' => $maxColumn];
    }

    private function rejectExternalLocations(string $xml): void
    {
        $xpath = new DOMXPath($this->xmlDocument($xml));
        foreach ($xpath->query('//*[local-name()="hyperlink"]') ?: [] as $hyperlink) {
            if (! $hyperlink instanceof DOMElement) {
                continue;
            }
            $location = $hyperlink->getAttribute('location');
            if ($location !== '' && (parse_url($location, PHP_URL_SCHEME) !== null || str_starts_with($location, '//'))) {
                throw new UnexpectedValueException('External hyperlinks are not allowed.');
            }
        }
    }

    private function xmlDocument(string $xml): DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw new UnexpectedValueException('Document type declarations are not allowed in workbook XML.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new UnexpectedValueException('The workbook contains invalid XML.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function coordinateBounds(string $coordinate): array
    {
        if (preg_match('/^\$?([A-Z]{1,3})\$?([1-9][0-9]*)$/i', $coordinate, $matches) !== 1) {
            throw new UnexpectedValueException('The Data sheet contains an invalid cell coordinate.');
        }

        return [
            $this->positiveInteger($matches[2], 'row'),
            Coordinate::columnIndexFromString(strtoupper($matches[1])),
        ];
    }

    private function positiveInteger(string $value, string $part): int
    {
        if (preg_match('/^[1-9][0-9]*$/', $value) !== 1 || strlen($value) > 9) {
            throw new UnexpectedValueException("The workbook contains an invalid {$part} number.");
        }

        return (int) $value;
    }

    private function resolveZipTarget(string $sourceEntry, string $target): string
    {
        if ($target === '' || parse_url($target, PHP_URL_SCHEME) !== null || str_contains($target, "\0")) {
            throw new UnexpectedValueException('The workbook contains an invalid worksheet target.');
        }

        $target = str_replace('\\', '/', $target);
        $segments = str_starts_with($target, '/') ? [] : explode('/', dirname($sourceEntry));
        foreach (explode('/', ltrim($target, '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new UnexpectedValueException('The workbook contains an invalid worksheet target.');
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function rawString(Cell $cell): string
    {
        $value = $cell->getValue();
        if ($cell->getDataType() === DataType::TYPE_FORMULA || ! is_string($value)) {
            return '';
        }

        return $value;
    }

    private function exportRow(mixed $row, array $headingKeys): array
    {
        if (! is_array($row)) {
            throw new \InvalidArgumentException('Export rows must be arrays.');
        }

        if (array_is_list($row)) {
            if (count($row) !== count($headingKeys)) {
                throw new \InvalidArgumentException('Each positional export row must match the number of headings.');
            }

            return $row;
        }

        return array_map(static fn (int|string $key): mixed => $row[$key] ?? null, $headingKeys);
    }

    private function writeString(Worksheet $sheet, string $coordinate, string $value): void
    {
        $sheet->getCell($coordinate)->setValueExplicit(StringHelper::sanitizeUTF8($value), DataType::TYPE_STRING);
    }

    private function withDiskCellCache(Closure $callback): mixed
    {
        $previousCache = Settings::getCache();
        $cachePath = $this->privateDirectory('cache/'.Str::uuid());
        Settings::setCache(new Repository(new FileStore(app('files'), $cachePath, 0600)));

        try {
            return $callback();
        } finally {
            Settings::setCache($previousCache);
            File::deleteDirectory($cachePath);
        }
    }

    private function privateDirectory(string $relativePath): string
    {
        $basePath = storage_path('app/private/data-operations');
        $path = $basePath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        File::ensureDirectoryExists($path, 0700, true);
        if (is_link($path)) {
            throw new RuntimeException('Private data-operation storage may not be a symbolic link.');
        }
        @chmod($path, 0700);

        return $path;
    }

    private function newPrivateFile(string $extension): string
    {
        $directory = $this->privateDirectory('tmp');
        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.'.$extension;
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Could not create a private workbook file.');
        }

        fclose($handle);
        @chmod($path, 0600);

        return $path;
    }
}

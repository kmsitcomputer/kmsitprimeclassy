<?php

namespace App\Services\Catalog;

use App\Exceptions\ApiException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * REM-01 file boundary. Nothing here trusts the client: the format is sniffed
 * from the bytes, sizes/rows/columns/cells are capped BEFORE parsing, XLSX
 * packages carrying formulas, macros, external links or embedded objects are
 * refused, and exported strings are neutralised against spreadsheet formula
 * injection. Uploaded files are read from PHP's temp path and never stored.
 */
class CatalogSpreadsheet
{
    public const MAX_BYTES = 2_097_152;            // 2 MiB uploaded

    public const MAX_UNCOMPRESSED_BYTES = 20_971_520; // 20 MiB inflated XLSX package

    public const MAX_ZIP_ENTRIES = 60;

    public const MAX_ROWS = 500;

    public const MAX_COLUMNS = 20;

    public const MAX_CELL_LENGTH = 500;

    /** Canonical template. Order is the export order. */
    public const HEADERS = ['type', 'sku', 'product_slug', 'product_name', 'category_id', 'status', 'price', 'weight_grams', 'attributes', 'stock_target'];

    /**
     * @return array{rows: list<array<string,string>>, format: string}  header-keyed rows, 1-based line numbers via index+2
     */
    public function read(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new ApiException('File kosong atau tidak terbaca.', 422);
        }
        if ($size > self::MAX_BYTES) {
            throw new ApiException('File melebihi batas ukuran 2 MiB.', 422);
        }

        $head = (string) file_get_contents($path, false, null, 0, 4);
        $grid = str_starts_with($head, "PK\x03\x04") ? $this->readXlsx($path) : $this->readCsv($path);
        $format = str_starts_with($head, "PK\x03\x04") ? 'xlsx' : 'csv';

        if (count($grid) < 2) {
            throw new ApiException('File tidak memiliki baris data.', 422);
        }
        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($grid));
        if (count($headers) > self::MAX_COLUMNS) {
            throw new ApiException('Jumlah kolom melebihi batas.', 422);
        }
        $unknown = array_values(array_diff(array_filter($headers, fn ($h) => $h !== ''), self::HEADERS));
        if ($unknown) {
            throw new ApiException('Kolom tidak dikenal: '.implode(', ', array_slice($unknown, 0, 5)), 422);
        }
        foreach (['type', 'sku'] as $required) {
            if (! in_array($required, $headers, true)) {
                throw new ApiException("Kolom wajib '{$required}' tidak ada.", 422);
            }
        }
        if (count($grid) > self::MAX_ROWS) {
            throw new ApiException('Jumlah baris melebihi batas '.self::MAX_ROWS.'.', 422);
        }

        $rows = [];
        foreach ($grid as $line) {
            $row = [];
            foreach ($headers as $i => $h) {
                if ($h !== '') {
                    $row[$h] = trim((string) ($line[$i] ?? ''));
                }
            }
            if (implode('', $row) === '') {
                continue; // fully blank line
            }
            $rows[] = $row;
        }
        if ($rows === []) {
            throw new ApiException('File tidak memiliki baris data.', 422);
        }

        return ['rows' => $rows, 'format' => $format];
    }

    /** @return list<list<string>> */
    private function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_contains($raw, "\0") || ! mb_check_encoding($raw, 'UTF-8')) {
            throw new ApiException('File bukan CSV UTF-8 yang valid.', 422);
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);
        $grid = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($grid) > self::MAX_ROWS + 1) {
                break; // read() reports the row-limit error
            }
            $grid[] = $this->guardCells($cells);
        }
        fclose($handle);

        return $grid;
    }

    /** @return list<list<string>> */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ApiException('File XLSX tidak valid.', 422);
        }
        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new ApiException('Paket XLSX terlalu kompleks.', 422);
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = strtolower((string) $stat['name']);
                $total += (int) $stat['size'];
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new ApiException('Isi XLSX terlalu besar setelah dekompresi.', 422);
                }
                if (preg_match('#(^|/)(vbaproject|externallinks|embeddings|activex|oleobject|macrosheets)#', $name) || str_ends_with($name, '.bin')) {
                    throw new ApiException('XLSX berisi makro/tautan eksternal/objek tertanam dan ditolak.', 422);
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#i', $name)) {
                    $xml = (string) $zip->getFromIndex($i);
                    if (preg_match('/<f[\s>\/]/', $xml)) {
                        throw new ApiException('XLSX berisi rumus dan ditolak (gunakan nilai).', 422);
                    }
                }
            }
        } finally {
            $zip->close();
        }

        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheet(0);
        $grid = [];
        $maxRow = min($sheet->getHighestDataRow(), self::MAX_ROWS + 2);
        $maxCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($maxCol > self::MAX_COLUMNS) {
            throw new ApiException('Jumlah kolom melebihi batas.', 422);
        }
        for ($r = 1; $r <= $maxRow; $r++) {
            $cells = [];
            for ($c = 1; $c <= $maxCol; $c++) {
                $cells[] = (string) $sheet->getCell([$c, $r])->getValue();
            }
            $grid[] = $this->guardCells($cells);
        }
        $spreadsheet->disconnectWorksheets();

        return $grid;
    }

    /** @param  array<mixed>  $cells  @return list<string> */
    private function guardCells(array $cells): array
    {
        $out = [];
        foreach ($cells as $cell) {
            $cell = (string) $cell;
            if (mb_strlen($cell) > self::MAX_CELL_LENGTH) {
                throw new ApiException('Ada sel yang melebihi '.self::MAX_CELL_LENGTH.' karakter.', 422);
            }
            $out[] = $cell;
        }

        return $out;
    }

    /** Spreadsheet formula-injection neutralisation for any exported STRING. */
    public static function safeCell(mixed $value): string|int|float|null
    {
        if ($value === null || is_int($value) || is_float($value)) {
            return $value;
        }
        $s = (string) $value;

        return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'".$s : $s;
    }

    /** @param  iterable<list<mixed>>  $rows */
    public function streamCsv(string $filename, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, self::HEADERS, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => self::safeCell($v), $row), ',', '"', '');
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @param  iterable<list<mixed>>  $rows */
    public function streamXlsx(string $filename, iterable $rows): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $line = 1;
        foreach ([self::HEADERS, ...$rows] as $row) {
            foreach (array_values($row) as $i => $value) {
                $value = $line === 1 ? $value : self::safeCell($value);
                // Always an explicit string/number — never let the binder interpret text as a formula.
                $sheet->getCell([$i + 1, $line])->setValueExplicit(
                    $value ?? '', is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING
                );
            }
            $line++;
        }

        return new StreamedResponse(function () use ($spreadsheet) {
            (new XlsxWriter($spreadsheet))->save('php://output');
        }, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'X-Content-Type-Options' => 'nosniff']);
    }
}

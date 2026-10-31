<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads a CSV or XLSX upload into associative rows keyed by the
 * normalised header (lower-case, spaces → underscores).
 */
class SpreadsheetReader
{
    /**
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public function read(string $path, string $extension): array
    {
        $raw = in_array(strtolower($extension), ['xlsx', 'xls'], true)
            ? IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false)
            : $this->csv($path);

        $raw = array_values(array_filter($raw, fn ($row) => array_filter($row, fn ($v) => trim((string) $v) !== '') !== []));

        if ($raw === []) {
            throw new RuntimeException('empty');
        }

        $headers = array_map(fn ($h) => str_replace([' ', '-'], '_', mb_strtolower(trim((string) $h))), array_shift($raw));

        $rows = array_map(function (array $row) use ($headers) {
            $assoc = [];
            foreach ($headers as $i => $header) {
                $assoc[$header] = trim((string) ($row[$i] ?? ''));
            }

            return $assoc;
        }, $raw);

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return list<list<string>>
     */
    private function csv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1256');
        }

        $first = strtok($content, "\n") ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }
}

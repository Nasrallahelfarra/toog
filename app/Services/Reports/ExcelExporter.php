<?php

namespace App\Services\Reports;

use App\Models\Store;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a ReportBuilder report to .xlsx: a summary sheet plus one sheet
 * per table, right-to-left when the user works in Arabic, with bilingual
 * headers in the brand's navy color.
 */
class ExcelExporter
{
    private const NAVY = '1B3A5C';

    private const SAND = 'F8F4E8';

    /**
     * @param  array<string, mixed>  $report
     */
    public function spreadsheet(array $report, Store $store, CarbonImmutable $from, CarbonImmutable $to, string $locale): Spreadsheet
    {
        $rtl = $locale === 'ar';
        $book = new Spreadsheet;
        $book->getProperties()
            ->setCreator('طوق')
            ->setTitle($report['title_en'].' — '.$store->name);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);

        // Summary sheet.
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($rtl ? 'الملخص' : 'Summary');
        $sheet->setRightToLeft($rtl);
        $this->banner($sheet, $report, $store, $from, $to, $rtl, 2);

        $row = 5;
        foreach ($report['summary'] as $metric) {
            $sheet->setCellValue("A{$row}", $rtl ? "{$metric['label_ar']} / {$metric['label_en']}" : "{$metric['label_en']} / {$metric['label_ar']}");
            $this->value($sheet, "B{$row}", $metric['value'], $metric['format']);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $sheet->getStyle('A5:B'.($row - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E3D3A6');
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(22);

        // One sheet per table.
        foreach ($report['sections'] as $section) {
            $sheet = $book->createSheet();
            $sheet->setTitle($this->sheetTitle($rtl ? $section['title_ar'] : $section['title_en']));
            $sheet->setRightToLeft($rtl);

            $columns = $section['columns'];
            $this->banner($sheet, ['title_ar' => $section['title_ar'], 'title_en' => $section['title_en']], $store, $from, $to, $rtl, count($columns));

            $headerRow = 4;
            foreach ($columns as $i => $column) {
                $cell = Coordinate::stringFromColumnIndex($i + 1).$headerRow;
                $sheet->setCellValue($cell, $rtl ? "{$column['ar']}\n{$column['en']}" : "{$column['en']}\n{$column['ar']}");
            }

            $last = Coordinate::stringFromColumnIndex(count($columns));
            $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::NAVY]],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getRowDimension($headerRow)->setRowHeight(34);

            $r = $headerRow + 1;
            foreach ($section['rows'] as $data) {
                foreach ($columns as $i => $column) {
                    $this->value($sheet, Coordinate::stringFromColumnIndex($i + 1).$r, $data[$column['key']] ?? null, $column['format']);
                }

                if (($r - $headerRow) % 2 === 0) {
                    $sheet->getStyle("A{$r}:{$last}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SAND);
                }

                $r++;
            }

            if ($r > $headerRow + 1) {
                $sheet->getStyle("A{$headerRow}:{$last}".($r - 1))->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E3D3A6');
                $sheet->setAutoFilter("A{$headerRow}:{$last}".($r - 1));
            }

            $sheet->freezePane('A'.($headerRow + 1));

            foreach (range(1, count($columns)) as $i) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
            }
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public function download(array $report, Store $store, CarbonImmutable $from, CarbonImmutable $to, string $locale): StreamedResponse
    {
        $book = $this->spreadsheet($report, $store, $from, $to, $locale);
        $filename = "toog-{$report['type']}-{$store->slug}-{$to->format('Y-m-d')}.xlsx";

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Title rows: report name, store and period.
     *
     * @param  array<string, mixed>  $report
     */
    private function banner(Worksheet $sheet, array $report, Store $store, CarbonImmutable $from, CarbonImmutable $to, bool $rtl, int $width): void
    {
        $last = Coordinate::stringFromColumnIndex(max(2, $width));

        $sheet->setCellValue('A1', $rtl ? "{$report['title_ar']} — {$report['title_en']}" : "{$report['title_en']} — {$report['title_ar']}");
        $sheet->mergeCells("A1:{$last}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB(self::NAVY);

        $period = $from->toDateString().' → '.$to->toDateString();
        $sheet->setCellValue('A2', $rtl ? "{$store->name} · الفترة: {$period} · طوق" : "{$store->name} · Period: {$period} · طوق");
        $sheet->mergeCells("A2:{$last}2");
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('6B7280');
    }

    private function value(Worksheet $sheet, string $cell, mixed $value, string $format): void
    {
        if ($value === null || $value === '') {
            $sheet->setCellValue($cell, '—');

            return;
        }

        $sheet->setCellValue($cell, $value);

        $code = match ($format) {
            'money' => '#,##0.00 "SAR"',
            'percent' => '0.0"%"',
            'number' => '#,##0',
            default => null,
        };

        if ($code && is_numeric($value)) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($code);
        }
    }

    /**
     * Excel sheet names: max 31 chars, no []:*?/\ characters.
     */
    private function sheetTitle(string $title): string
    {
        return mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $title), 0, 31);
    }
}

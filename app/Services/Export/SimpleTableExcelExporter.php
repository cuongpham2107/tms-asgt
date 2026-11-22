<?php

namespace App\Services\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Xuất một bảng phẳng (tiêu đề, dòng header, các dòng dữ liệu) ra file .xlsx.
 */
class SimpleTableExcelExporter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    public function build(string $title, array $headers, iterable $rows): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->fromArray($headers, null, 'A2');
        $headerStyle = $sheet->getStyle("A2:{$lastColumn}2");
        $headerStyle->getFont()->setBold(true);
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DBEAFE');

        $rowIndex = 3;
        foreach ($rows as $row) {
            $sheet->fromArray($row, null, "A{$rowIndex}", true);
            $rowIndex++;
        }

        $sheet->setAutoFilter("A2:{$lastColumn}".max(2, $rowIndex - 1));
        $sheet->freezePane('A3');

        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string|int|float|null>>  $rows
     */
    public function download(string $title, array $headers, iterable $rows, string $filename): StreamedResponse
    {
        $spreadsheet = $this->build($title, $headers, $rows);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}

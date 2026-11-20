<?php

namespace App\Actions\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a report table into a downloadable .xlsx file or a printable PDF.
 */
class ReportExporter
{
    /**
     * Format a cell for screen and PDF.
     */
    public static function display(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => Number::rupiah((int) $value),
            'number' => number_format((int) $value, 0, ',', '.'),
            'percent' => number_format((float) $value, 1, ',', '.').'%',
            'date' => $value instanceof CarbonInterface ? $value->translatedFormat('j M Y') : (string) $value,
            default => (string) $value,
        };
    }

    public function excel(ReportTable $table): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'laporan').'.xlsx';
        $bold = (new Style)->withFontBold(true);
        $money = (new Style)->withFormat('#,##0');
        $totalStyle = (new Style)->withFontBold(true)->withFormat('#,##0');

        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        $writer->nameCurrentSheet(Str::limit($table->title, 31, ''));

        $writer->addRow([$table->title], (new Style)->withFontBold(true)->withFontSize(14));
        $writer->addRow([$table->subtitle]);
        $writer->addRow(['Dicetak '.now()->translatedFormat('j F Y H:i').' oleh '.(Auth::user()->name ?? 'sistem')]);
        $writer->addRow([]);
        $writer->addRow(array_column($table->columns, 'label'), $bold->withBackgroundColor('E8F6EE'));

        foreach ($table->rows as $row) {
            $writer->addRow($this->excelRow($table, $row), $money);
        }

        if ($table->totals !== null) {
            $writer->addRow($this->excelRow($table, $table->totals), $totalStyle);
        }

        if ($table->summary !== []) {
            $writer->addRow([]);

            foreach ($table->summary as $item) {
                $writer->addRow([$item['label'], $item['value']]);
            }
        }

        $writer->close();

        return response()
            ->download($path, $this->fileName($table).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public function pdf(ReportTable $table): Response
    {
        return Pdf::loadView('reports.pdf', ['table' => $table])
            ->setPaper('a4', count($table->columns) > 6 ? 'landscape' : 'portrait')
            ->setOption('enable_font_subsetting', true)
            ->stream($this->fileName($table).'.pdf');
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    private function excelRow(ReportTable $table, array $row): array
    {
        return array_map(function (array $column) use ($row) {
            $value = $row[$column['key']] ?? null;

            return match (true) {
                $value === null => '',
                $column['type'] === 'money', $column['type'] === 'number' => (int) $value,
                $column['type'] === 'percent' => (float) $value,
                $value instanceof CarbonInterface => $value->format('d/m/Y'),
                default => (string) $value,
            };
        }, $table->columns);
    }

    private function fileName(ReportTable $table): string
    {
        return Str::slug($table->title.' '.now()->format('Y-m-d'));
    }
}

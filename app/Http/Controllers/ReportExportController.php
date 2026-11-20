<?php

namespace App\Http\Controllers;

use App\Actions\Reports\ReportExporter;
use App\Actions\Reports\ReportFilters;
use App\Actions\Reports\ReportRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReportExportController extends Controller
{
    /**
     * Download a report as .xlsx or open it as a printable PDF, using the filters in the query string.
     */
    public function __invoke(Request $request, string $report, string $format, ReportExporter $exporter): Response
    {
        $reportClass = ReportRegistry::find($report);

        abort_if($reportClass === null, 404);
        abort_unless($reportClass::allows($request->user()), 403);

        $table = (new $reportClass)->build(ReportFilters::fromRequest($request));

        return $format === 'excel' ? $exporter->excel($table) : $exporter->pdf($table);
    }
}

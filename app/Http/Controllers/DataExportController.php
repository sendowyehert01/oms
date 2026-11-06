<?php

namespace App\Http\Controllers;

use App\DataExports;
use App\Http\Requests\ExportDataRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DataExportController extends Controller
{
    public function index(): View
    {
        $timezone = config('app.reporting_timezone');
        $today = CarbonImmutable::now($timezone);

        return view('exports.index', [
            'defaults' => ['date_from' => $today->startOfMonth()->toDateString(), 'date_to' => $today->toDateString()],
            'timezone' => $timezone,
        ]);
    }

    public function download(ExportDataRequest $request, string $type, DataExports $exports): StreamedResponse|RedirectResponse
    {
        $filters = $request->validated();
        $workbook = null;

        try {
            $workbook = $exports->workbook($type, $filters['date_from'], $filters['date_to'], $request->user());
            $path = $workbook->build();
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new RuntimeException('The Excel report could not be opened.');
            }
        } catch (Throwable $exception) {
            $workbook?->cleanup();
            report($exception);

            return to_route('exports.index')->withInput($filters)
                ->withErrors(['export' => 'The Excel report could not be generated. Please try again.'], $type);
        }

        $filename = 'list-of-'.$type.'-'.$filters['date_from'].'-to-'.$filters['date_to'].'.xlsx';

        return response()->streamDownload(function () use ($workbook, $stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
                $workbook->cleanup();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

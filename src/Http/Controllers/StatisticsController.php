<?php

namespace KraftInk\Plugins\Stats\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;
use KraftInk\Plugins\Stats\Services\StatisticsReport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StatisticsController
{
    public function __invoke(Request $request, StatisticsReport $report): View|StreamedResponse
    {
        $export = $request->query('export') === 'csv';
        $data = $report->read($request->query(), $export);

        return $export ? $this->download($data) : view('stats::admin.index', $data);
    }

    private function download(array $data): StreamedResponse
    {
        $columns = ['views', 'guests', 'members', 'registrations', 'visitors', 'players', 'capacity', 'tps', 'memory', 'memory_bytes', 'memory_max_bytes', 'cpu', 'uptime', 'samples'];
        $headers = array_map(fn ($column) => $column === 'uptime' ? 'uptime_seconds' : $column, $columns);
        array_unshift($headers, 'day');
        if ($data['comparison'] !== null) {
            $headers = [...$headers, ...array_map(fn ($header) => 'previous_'.$header, $headers)];
        }

        return response()->streamDownload(function () use ($data, $columns, $headers): void {
            $stream = fopen('php://output', 'w');
            try {
                fputcsv($stream, $headers, ',', '"', '');
                foreach ($data['days'] as $index => $day) {
                    $row = [$day];
                    foreach ($columns as $column) {
                        $row[] = $data['series'][$column][$index];
                    }
                    if ($data['comparison'] !== null) {
                        $row[] = $data['comparisonDays'][$index];
                        foreach ($columns as $column) {
                            $row[] = $data['comparison'][$column][$index];
                        }
                    }
                    fputcsv($stream, $row, ',', '"', '');
                }
            } finally {
                fclose($stream);
            }
        }, 'stats-'.$data['from'].'-'.$data['to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

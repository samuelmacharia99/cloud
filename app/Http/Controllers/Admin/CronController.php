<?php

namespace App\Http\Controllers\Admin;

use App\Models\CronJob;
use App\Models\CronJobLog;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class CronController extends Controller
{
    public function index(Request $request)
    {
        $jobs = CronJob::withCount([
            'logs as logs_24h' => fn($q) => $q->where('started_at', '>=', now()->subDay())
        ])
        ->with('latestLog')
        ->latest('updated_at')
        ->paginate(15);

        // Calculate summary stats
        $stats = [
            'total_jobs' => CronJob::count(),
            'enabled_jobs' => CronJob::where('enabled', true)->count(),
            'total_runs_24h' => CronJobLog::where('started_at', '>=', now()->subDay())->count(),
            'failed_runs_24h' => CronJobLog::where('started_at', '>=', now()->subDay())->where('status', 'failed')->count(),
        ];

        // Build 24h chart data: group logs by hour for system-wide performance
        $logs24h = CronJobLog::where('started_at', '>=', now()->subDay())
            ->with('cronJob')
            ->get();

        $chartData = $this->buildChartData($logs24h);

        return view('admin.cron.index', compact('jobs', 'stats', 'chartData'));
    }

    public function show(CronJob $job)
    {
        $job->load('logs');

        // Paginate logs
        $logsQuery = CronJobLog::where('cron_job_id', $job->id)
            ->latest('started_at');

        $logs = $logsQuery->paginate(50);

        // Calculate stats
        $totalRuns = CronJobLog::where('cron_job_id', $job->id)->count();
        $successCount = CronJobLog::where('cron_job_id', $job->id)->where('status', 'success')->count();
        $successRate = $totalRuns > 0 ? round(($successCount / $totalRuns) * 100) : 0;

        $avgDuration = CronJobLog::where('cron_job_id', $job->id)
            ->where('status', 'success')
            ->avg('duration_ms');

        $stats = [
            'total_runs' => $totalRuns,
            'success_rate' => $successRate,
            'avg_duration' => $avgDuration ? round($avgDuration) : 0,
            'last_status' => $job->last_status,
        ];

        return view('admin.cron.show', compact('job', 'logs', 'stats'));
    }

    public function run(CronJob $job)
    {
        try {
            $output = Artisan::call($job->command);
            $outputText = Artisan::output();

            return response()->json([
                'success' => true,
                'output' => $outputText,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'output' => $e->getMessage(),
            ], 500);
        }
    }

    public function toggle(CronJob $job)
    {
        $job->update(['enabled' => !$job->enabled]);

        return back()->with('success', $job->enabled
            ? "Cron job '{$job->name}' enabled."
            : "Cron job '{$job->name}' disabled."
        );
    }

    public function logs(CronJob $job)
    {
        // Return last 24h logs grouped by hour for Chart.js polling
        $logs24h = CronJobLog::where('cron_job_id', $job->id)
            ->where('started_at', '>=', now()->subDay())
            ->get();

        $chartData = $this->buildChartData($logs24h);

        return response()->json($chartData);
    }

    /**
     * Build chart data from logs grouped by hour
     * Returns: {labels: [...], success: [...], failed: [...], durations: [...]}
     */
    private function buildChartData($logs)
    {
        $hourly = [];

        // Initialize last 24 hours
        for ($i = 23; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('H:00');
            $hourly[$hour] = [
                'success' => 0,
                'failed' => 0,
                'total_duration' => 0,
                'count' => 0,
            ];
        }

        // Fill in actual data from logs collection (PHP-level grouping for SQLite compatibility)
        foreach ($logs as $log) {
            $hour = $log->started_at->format('H:00');
            if (!isset($hourly[$hour])) {
                $hourly[$hour] = ['success' => 0, 'failed' => 0, 'total_duration' => 0, 'count' => 0];
            }

            if ($log->status === 'success') {
                $hourly[$hour]['success']++;
            } elseif ($log->status === 'failed') {
                $hourly[$hour]['failed']++;
            }

            $hourly[$hour]['total_duration'] += $log->duration_ms ?? 0;
            $hourly[$hour]['count']++;
        }

        // Calculate averages and format
        $labels = [];
        $successSeries = [];
        $failedSeries = [];
        $durations = [];

        foreach ($hourly as $hour => $data) {
            $labels[] = $hour;
            $successSeries[] = $data['success'];
            $failedSeries[] = $data['failed'];
            $durations[] = $data['count'] > 0 ? round($data['total_duration'] / $data['count']) : 0;
        }

        return [
            'labels' => $labels,
            'success' => $successSeries,
            'failed' => $failedSeries,
            'durations' => $durations,
        ];
    }
}

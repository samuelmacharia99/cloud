<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use Illuminate\Console\Command;

class CronStatusCommand extends Command
{
    protected $signature = 'cron:status';
    protected $description = 'Show the current status of all cron jobs';

    public function handle(): int
    {
        $jobs = CronJob::with('latestLog')->orderBy('next_run_at')->get();

        if ($jobs->isEmpty()) {
            $this->warn('No cron jobs configured. Run: php artisan db:seed --class=CronJobSeeder');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════════════════════════════');
        $this->info('CRON JOB STATUS REPORT');
        $this->info('═══════════════════════════════════════════════════════════════════════════════════════');
        $this->newLine();

        $headers = ['Job Name', 'Command', 'Schedule', 'Status', 'Last Run', 'Next Run'];
        $rows = [];

        foreach ($jobs as $job) {
            $statusBadge = $this->getStatusBadge($job);
            $lastRun = $job->last_ran_at ? $job->last_ran_at->format('M d H:i') : 'Never';
            $nextRun = $job->next_run_at ? $job->next_run_at->format('M d H:i') : 'N/A';

            $rows[] = [
                $job->name,
                $job->command,
                $job->schedule,
                $statusBadge,
                $lastRun,
                $nextRun,
            ];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->info('Status Legend: ✓ = Success, ✗ = Failed, ⏳ = Running, ⚪ = Disabled, ❓ = Unknown');
        $this->newLine();

        // Summary stats
        $enabledCount = $jobs->where('enabled', true)->count();
        $successCount = $jobs->where('last_status', 'success')->count();
        $failedCount = $jobs->where('last_status', 'failed')->count();
        $runningCount = $jobs->where('last_status', 'running')->count();

        $this->line("Enabled: {$enabledCount} | Success: {$successCount} | Failed: {$failedCount} | Running: {$runningCount}");
        $this->newLine();

        // Check for issues
        $issues = [];

        // Check for jobs that haven't run in a while
        foreach ($jobs as $job) {
            if ($job->enabled && $job->last_ran_at && $job->last_ran_at->diffInHours(now()) > 24) {
                $issues[] = "⚠️  {$job->name} hasn't run in over 24 hours";
            }

            if ($job->enabled && $job->last_status === 'running') {
                $issues[] = "⚠️  {$job->name} is still running (may be hung)";
            }

            if ($job->enabled && $job->last_status === 'failed') {
                $issues[] = "❌ {$job->name} failed on last attempt";
            }
        }

        if (!empty($issues)) {
            $this->newLine();
            $this->warn('Issues Detected:');
            foreach ($issues as $issue) {
                $this->line($issue);
            }
            $this->newLine();
            $this->comment('View details: php artisan cron:status | tail');
        } else {
            $this->newLine();
            $this->info('✅ All cron jobs are healthy!');
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function getStatusBadge(CronJob $job): string
    {
        if (!$job->enabled) {
            return '⚪ Disabled';
        }

        return match ($job->last_status) {
            'success' => '✓ Success',
            'failed' => '✗ Failed',
            'running' => '⏳ Running',
            default => '❓ Unknown',
        };
    }
}

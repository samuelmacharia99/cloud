<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\ContainerCronJob;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ContainerCronService
{
    public function __construct(
        private ContainerStackCommandService $stackCommands,
    ) {}

    /**
     * @return array<int, ContainerCronJob>
     */
    public function listForService(Service $service): array
    {
        return $service->containerCronJobs()
            ->orderBy('name')
            ->get()
            ->all();
    }

    public function create(Service $service, array $data): ContainerCronJob
    {
        $this->assertCanManage($service);
        $this->assertJobLimit($service);

        $validated = $this->validatePayload($data);

        return ContainerCronJob::create([
            'service_id' => $service->id,
            'name' => $validated['name'],
            'schedule' => $validated['schedule'],
            'command' => $validated['command'],
            'enabled' => $validated['enabled'] ?? true,
            'next_run_at' => $this->calculateNextRun($validated['schedule']),
        ]);
    }

    public function update(ContainerCronJob $job, array $data): ContainerCronJob
    {
        $this->assertContainerService($job->service);

        $validated = $this->validatePayload($data, updating: true);

        $job->update([
            'name' => $validated['name'],
            'schedule' => $validated['schedule'],
            'command' => $validated['command'],
            'enabled' => $validated['enabled'] ?? $job->enabled,
            'next_run_at' => $this->calculateNextRun($validated['schedule']),
        ]);

        return $job->fresh();
    }

    public function delete(ContainerCronJob $job): void
    {
        $this->assertContainerService($job->service);
        $job->delete();
    }

    public function toggle(ContainerCronJob $job, bool $enabled): ContainerCronJob
    {
        $this->assertContainerService($job->service);

        $job->update([
            'enabled' => $enabled,
            'next_run_at' => $enabled ? $this->calculateNextRun($job->schedule) : null,
        ]);

        return $job->fresh();
    }

    /**
     * @return array{processed: int, succeeded: int, failed: int, skipped: int}
     */
    public function runDueJobs(?int $limit = null): array
    {
        $limit ??= (int) config('containers.cron.batch_size', 50);
        $now = now();

        $jobs = ContainerCronJob::query()
            ->where('enabled', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->with(['service.containerDeployment.node', 'service.product.containerTemplate'])
            ->orderBy('next_run_at')
            ->limit($limit)
            ->get();

        $summary = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($jobs as $job) {
            $summary['processed']++;

            try {
                if (! $this->shouldRunNow($job)) {
                    $summary['skipped']++;

                    continue;
                }

                $this->execute($job);
                $summary['succeeded']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $this->markFailed($job, $e->getMessage());
                \Log::warning('Container cron job failed', [
                    'job_id' => $job->id,
                    'service_id' => $job->service_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    public function execute(ContainerCronJob $job): void
    {
        $job->loadMissing('service.containerDeployment.node', 'service.product.containerTemplate');
        $service = $job->service;
        $deployment = $service?->containerDeployment;

        if (! $service || $service->product?->type !== 'container_hosting') {
            throw new \RuntimeException('Invalid container service.');
        }

        if ($service->status !== ServiceStatus::Active) {
            throw new \RuntimeException('Service is not active.');
        }

        if (! $deployment || ! $deployment->node) {
            throw new \RuntimeException('Container is not deployed.');
        }

        if ($deployment->status !== 'running') {
            throw new \RuntimeException('Container is not running.');
        }

        if (! $this->isAllowedCommand($job->command)) {
            throw new \RuntimeException('Stored command failed safety validation.');
        }

        if ($this->stackCommands->isLongRunningCommand($job->command)) {
            throw new \RuntimeException('Long-running commands are not allowed in cron jobs.');
        }

        $template = $service->product->containerTemplate;
        $workDir = $template ? $this->stackCommands->resolveWorkDir($template) : '/app';
        $containerPath = ContainerDeploymentService::CONTAINER_BASE_PATH.'/'.$deployment->container_name;
        $timeout = (int) config('containers.cron.command_timeout_seconds', 300);

        $ssh = SSHService::forNode($deployment->node);

        try {
            app(ContainerDeploymentService::class)->waitForContainerRunning($ssh, $deployment->container_name, 30);

            $output = $this->stackCommands->execInContainer(
                $ssh,
                $containerPath,
                $deployment->container_name,
                $job->command,
                $workDir,
                $timeout,
            );

            $job->update([
                'last_run_at' => now(),
                'last_status' => 'success',
                'last_output' => Str::limit($output, (int) config('containers.cron.output_max_chars', 2000)),
                'next_run_at' => $this->calculateNextRun($job->schedule),
            ]);
        } finally {
            $ssh->disconnect();
        }
    }

    public function isAllowedCommand(string $command): bool
    {
        $command = trim($command);

        if ($command === '' || strlen($command) > (int) config('containers.cron.max_command_length', 500)) {
            return false;
        }

        if (preg_match('/[;&|`$<>\\\\]/', $command)) {
            return false;
        }

        $allowedPrefixes = config('containers.cron.allowed_prefixes', []);

        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with(strtolower($command), strtolower((string) $prefix))) {
                return true;
            }
        }

        return false;
    }

    public function isValidSchedule(string $schedule): bool
    {
        $schedule = trim($schedule);

        return $schedule !== '' && CronExpression::isValidExpression($schedule);
    }

    public function calculateNextRun(string $schedule, ?Carbon $from = null): Carbon
    {
        return Carbon::instance(
            CronExpression::factory(trim($schedule))->getNextRunDate($from ?? now())
        );
    }

    /**
     * @return array{name: string, schedule: string, command: string, enabled?: bool}
     */
    private function validatePayload(array $data, bool $updating = false): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $schedule = trim((string) ($data['schedule'] ?? ''));
        $command = trim((string) ($data['command'] ?? ''));

        if ($name === '' || strlen($name) > 120) {
            throw new \InvalidArgumentException('Job name is required (max 120 characters).');
        }

        if (! $this->isValidSchedule($schedule)) {
            throw new \InvalidArgumentException('Invalid cron schedule. Use five fields, e.g. */5 * * * * or 0 * * * *.');
        }

        if (! $this->isAllowedCommand($command)) {
            throw new \InvalidArgumentException('Command is not allowed. Use php, node, npm run, yarn, python, or bundle exec without shell operators.');
        }

        if ($this->stackCommands->isLongRunningCommand($command)) {
            throw new \InvalidArgumentException('Long-running server commands cannot be scheduled as cron jobs.');
        }

        return [
            'name' => $name,
            'schedule' => $schedule,
            'command' => $command,
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : true,
        ];
    }

    private function assertCanManage(Service $service): void
    {
        $this->assertContainerService($service);

        if (! $service->containerDeployment) {
            throw new \InvalidArgumentException('Deploy the container before adding cron jobs.');
        }
    }

    private function assertContainerService(Service $service): void
    {
        if ($service->product?->type !== 'container_hosting') {
            throw new \InvalidArgumentException('Cron jobs are only available for container services.');
        }
    }

    private function assertJobLimit(Service $service): void
    {
        $max = (int) config('containers.cron.max_jobs_per_service', 20);
        $count = $service->containerCronJobs()->count();

        if ($count >= $max) {
            throw new \InvalidArgumentException("Maximum of {$max} cron jobs per service reached.");
        }
    }

    private function shouldRunNow(ContainerCronJob $job): bool
    {
        if (! $job->enabled) {
            return false;
        }

        if ($job->next_run_at === null) {
            return true;
        }

        return $job->next_run_at->lte(now());
    }

    private function markFailed(ContainerCronJob $job, string $message): void
    {
        $job->update([
            'last_run_at' => now(),
            'last_status' => 'failed',
            'last_output' => Str::limit($message, (int) config('containers.cron.output_max_chars', 2000)),
            'next_run_at' => $this->calculateNextRun($job->schedule),
        ]);
    }
}

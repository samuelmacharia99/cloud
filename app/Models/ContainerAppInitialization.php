<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerAppInitialization extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'service_id',
        'container_deployment_id',
        'user_id',
        'template_slug',
        'status',
        'steps',
        'log',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(ContainerDeployment::class, 'container_deployment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    public function appendLog(string $message): void
    {
        $line = '['.now()->toIso8601String().'] '.$message;
        $existing = (string) ($this->log ?? '');
        $combined = $existing === '' ? $line : $existing."\n".$line;

        if (strlen($combined) > 500000) {
            $combined = substr($combined, -500000);
        }

        $this->log = $combined;
        $this->save();
    }

    public function updateStep(string $key, string $status, ?string $message = null, ?string $output = null): void
    {
        $steps = is_array($this->steps) ? $this->steps : [];
        $now = now()->toIso8601String();

        foreach ($steps as &$step) {
            if (($step['key'] ?? null) !== $key) {
                continue;
            }

            $step['status'] = $status;
            if ($message !== null) {
                $step['message'] = $message;
            }
            if ($output !== null) {
                $step['output'] = $this->truncateOutput($output);
            }
            if ($status === 'running' && empty($step['started_at'])) {
                $step['started_at'] = $now;
            }
            if (in_array($status, ['completed', 'failed', 'skipped', 'warning'], true)) {
                $step['completed_at'] = $now;
            }
        }
        unset($step);

        $this->steps = $steps;
        $this->save();
    }

    private function truncateOutput(string $output): string
    {
        $output = trim($output);
        if (strlen($output) <= 8000) {
            return $output;
        }

        return substr($output, 0, 4000)."\n...\n".substr($output, -4000);
    }
}

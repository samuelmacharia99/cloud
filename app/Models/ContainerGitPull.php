<?php

namespace App\Models;

use App\Exceptions\SSH\SSHCommandException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerGitPull extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * MySQL TEXT is 64KiB; npm/git output can exceed that and then fail-to-save
     * replaces the real error. Keep a head+tail so operators still see the cause.
     */
    public const ERROR_MESSAGE_MAX_BYTES = 8000;

    protected $fillable = [
        'service_id',
        'container_deployment_id',
        'user_id',
        'template_slug',
        'status',
        'options',
        'steps',
        'log',
        'error_message',
        'commit',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'options' => 'array',
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

    public function isRestartable(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
        ], true);
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
                $step['message'] = self::truncateErrorMessage($message);
            }
            if ($output !== null) {
                $step['output'] = self::truncateErrorMessage($output);
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

    public function resetStepsForRetry(): void
    {
        $steps = is_array($this->steps) ? $this->steps : [];
        foreach ($steps as &$step) {
            $step['status'] = 'pending';
            unset($step['message'], $step['output'], $step['started_at'], $step['completed_at']);
        }
        unset($step);

        $this->steps = $steps;
        $this->started_at = null;
        $this->completed_at = null;
        $this->commit = null;
        $this->save();
    }

    public function setErrorMessageAttribute(?string $value): void
    {
        $this->attributes['error_message'] = $value === null
            ? null
            : self::truncateErrorMessage(SSHCommandException::redactSensitive($value));
    }

    public static function truncateErrorMessage(string $message): string
    {
        $message = trim($message);
        if (strlen($message) <= self::ERROR_MESSAGE_MAX_BYTES) {
            return $message;
        }

        $keep = (int) (self::ERROR_MESSAGE_MAX_BYTES / 2);

        return substr($message, 0, $keep)."\n...\n".substr($message, -$keep);
    }
}

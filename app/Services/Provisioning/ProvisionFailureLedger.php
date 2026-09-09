<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisionFailureClass;
use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ProvisionFailureLedger
{
    public const META_KEY = 'provision_failure';

    public const OPERATOR_ALERT_COOLDOWN_HOURS = 6;

    public const MAX_TRANSIENT_AUTO_RETRIES = 8;

    /**
     * @var list<string>
     */
    private const CONFIG_NEEDLES = [
        'expo/react native',
        'not a browser application',
        'not a valid frontend',
        'not a valid backend',
        'must be different directories',
        'multiple frontend',
        'multiple backend',
        'workload roots must be safe',
        'is not supported by the split node runtime',
        'no backend application matched',
        'no frontend application matched',
        'unknown provisioning driver',
        'authentication failed',
        'repository not found',
        'could not find remote',
        'not a valid git',
        'invalid repository',
        'permission denied (publickey)',
    ];

    /**
     * @var list<string>
     */
    private const CAPACITY_NEEDLES = [
        'no available node',
        'insufficient capacity',
        'no capacity',
        'no space left',
        'disk space',
    ];

    /**
     * @var list<string>
     */
    private const TRANSIENT_NEEDLES = [
        'port is already allocated',
        'connection timed out',
        'connection refused',
        'broken pipe',
        'image pull',
        'temporarily unavailable',
        'try again',
        'deadlock',
        'lock wait',
        'could not resolve host',
        'network is unreachable',
        'marked for removal',
        'ssh',
        'timed out',
    ];

    /**
     * Config holds the platform used to abort on, then started recovering.
     * Cron must retry those rows after a deploy; Retry deploy is not required.
     *
     * @var list<string>
     */
    private const SUPERSEDED_CONFIG_NEEDLES = [
        'must be different directories',
        'expo/react native',
        'not a browser application',
    ];

    public function classify(\Throwable $e): ProvisionFailureClass
    {
        $message = strtolower($e->getMessage());

        foreach (self::CONFIG_NEEDLES as $needle) {
            if (str_contains($message, $needle)) {
                return ProvisionFailureClass::Config;
            }
        }

        foreach (self::CAPACITY_NEEDLES as $needle) {
            if (str_contains($message, $needle)) {
                return ProvisionFailureClass::Capacity;
            }
        }

        if ($e instanceof \DomainException) {
            return ProvisionFailureClass::Config;
        }

        foreach (self::TRANSIENT_NEEDLES as $needle) {
            if (str_contains($message, $needle)) {
                return ProvisionFailureClass::Transient;
            }
        }

        return ProvisionFailureClass::Transient;
    }

    public function record(Service $service, \Throwable $e): void
    {
        $service->refresh();
        $class = $this->classify($e);
        $hash = $this->hash($e->getMessage());
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $existing = is_array($meta[self::META_KEY] ?? null) ? $meta[self::META_KEY] : [];
        $same = ($existing['hash'] ?? null) === $hash;
        $attempts = $same ? ((int) ($existing['attempts'] ?? 0)) + 1 : 1;
        $autoRetry = $this->autoRetryEnabled($class, $attempts);

        $meta[self::META_KEY] = [
            'class' => $class->value,
            'hash' => $hash,
            'message' => Str::limit($e->getMessage(), 500),
            'attempts' => $attempts,
            'first_at' => $same
                ? (string) ($existing['first_at'] ?? now()->toIso8601String())
                : now()->toIso8601String(),
            'last_at' => now()->toIso8601String(),
            'retry_after' => $autoRetry ? $this->retryAfter($class, $attempts)->toIso8601String() : null,
            'auto_retry' => $autoRetry,
            'customer_notified_hash' => $same ? ($existing['customer_notified_hash'] ?? null) : null,
            'operator_alerted_at' => $same ? ($existing['operator_alerted_at'] ?? null) : null,
        ];
        $meta['provision_error'] = $e->getMessage();
        $meta['provision_failed_at'] = now()->toIso8601String();

        $service->update(['service_meta' => $meta]);
    }

    public function clear(Service $service): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if (! array_key_exists(self::META_KEY, $meta)
            && ! array_key_exists('provision_error', $meta)
            && ! array_key_exists('provision_failed_at', $meta)) {
            return;
        }

        unset($meta[self::META_KEY], $meta['provision_error'], $meta['provision_failed_at']);
        $service->update(['service_meta' => $meta]);
    }

    public function shouldAutoRetry(Service $service): bool
    {
        $status = $service->status instanceof ServiceStatus
            ? $service->status
            : ServiceStatus::tryFrom((string) $service->status);

        if ($status !== ServiceStatus::Failed) {
            return true;
        }

        $snapshot = $this->snapshot($service);
        if ($snapshot === null) {
            return true;
        }

        if ($this->isSupersededConfigHold($snapshot)) {
            return true;
        }

        if (($snapshot['auto_retry'] ?? false) !== true) {
            return false;
        }

        $retryAfter = $snapshot['retry_after'] ?? null;
        if (! is_string($retryAfter) || $retryAfter === '') {
            return true;
        }

        try {
            return Carbon::parse($retryAfter)->lte(now());
        } catch (\Throwable) {
            return true;
        }
    }

    public function shouldNotifyCustomer(Service $service): bool
    {
        $snapshot = $this->snapshot($service);
        if ($snapshot === null) {
            return true;
        }

        return ($snapshot['customer_notified_hash'] ?? null) !== ($snapshot['hash'] ?? null);
    }

    public function shouldAlertOperators(Service $service): bool
    {
        $snapshot = $this->snapshot($service);
        if ($snapshot === null) {
            return true;
        }

        $alertedAt = $snapshot['operator_alerted_at'] ?? null;
        if (! is_string($alertedAt) || $alertedAt === '') {
            return true;
        }

        try {
            return Carbon::parse($alertedAt)->lte(now()->subHours(self::OPERATOR_ALERT_COOLDOWN_HOURS));
        } catch (\Throwable) {
            return true;
        }
    }

    public function markCustomerNotified(Service $service): void
    {
        $this->patchSnapshot($service, function (array $snapshot): array {
            $snapshot['customer_notified_hash'] = $snapshot['hash'] ?? null;

            return $snapshot;
        });
    }

    public function markOperatorAlerted(Service $service): void
    {
        $this->patchSnapshot($service, function (array $snapshot): array {
            $snapshot['operator_alerted_at'] = now()->toIso8601String();

            return $snapshot;
        });
    }

    public function hash(string $message): string
    {
        return sha1(strtolower(trim(preg_replace('/\s+/', ' ', $message) ?? $message)));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function snapshot(Service $service): ?array
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $snapshot = $meta[self::META_KEY] ?? null;

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function isSupersededConfigHold(array $snapshot): bool
    {
        $message = strtolower((string) ($snapshot['message'] ?? ''));
        if ($message === '') {
            return false;
        }

        foreach (self::SUPERSEDED_CONFIG_NEEDLES as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function autoRetryEnabled(ProvisionFailureClass $class, int $attempts): bool
    {
        return match ($class) {
            ProvisionFailureClass::Config => false,
            ProvisionFailureClass::Capacity => $attempts < self::MAX_TRANSIENT_AUTO_RETRIES,
            ProvisionFailureClass::Transient => $attempts < self::MAX_TRANSIENT_AUTO_RETRIES,
        };
    }

    private function retryAfter(ProvisionFailureClass $class, int $attempts): Carbon
    {
        $minutes = match (true) {
            $attempts <= 1 => 10,
            $attempts === 2 => 20,
            $attempts === 3 => 60,
            $attempts === 4 => 180,
            default => 360,
        };

        if ($class === ProvisionFailureClass::Capacity) {
            $minutes = max($minutes, 60);
        }

        return now()->addMinutes($minutes);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function patchSnapshot(Service $service, callable $mutator): void
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $snapshot = is_array($meta[self::META_KEY] ?? null) ? $meta[self::META_KEY] : null;
        if ($snapshot === null) {
            return;
        }

        $meta[self::META_KEY] = $mutator($snapshot);
        $service->update(['service_meta' => $meta]);
    }
}

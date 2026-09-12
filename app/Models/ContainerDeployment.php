<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContainerDeployment extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'node_id',
        'container_name',
        'status',
        'docker_compose_content',
        'assigned_port',
        'network_subnet',
        'internal_ip',
        'domain',
        'env_values',
        'last_status_check_at',
        'last_status_check_output',
        'deployed_at',
        'terminated_at',
        'auto_restart',
        'restart_policy',
        'restart_attempts',
        'last_restart_at',
        'cpu_limit',
        'memory_limit_mb',
        'selected_version',
        'migrated_from_node_id',
        'migrated_at',
        'migration_reason',
    ];

    protected $casts = [
        'env_values' => 'encrypted:array',
        'docker_compose_content' => 'encrypted',
        'assigned_port' => 'integer',
        'deployed_at' => 'datetime',
        'terminated_at' => 'datetime',
        'last_status_check_at' => 'datetime',
        'migrated_at' => 'datetime',
        'auto_restart' => 'boolean',
        'restart_attempts' => 'integer',
        'last_restart_at' => 'datetime',
        'cpu_limit' => 'decimal:2',
        'memory_limit_mb' => 'integer',
    ];

    // Relationships
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function migratedFromNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'migrated_from_node_id');
    }

    public function metrics()
    {
        return $this->hasMany(ContainerMetric::class);
    }

    public function latestRecordedMetric()
    {
        return $this->hasOne(ContainerMetric::class)->latestOfMany('recorded_at');
    }

    public function domains()
    {
        return $this->hasMany(ContainerDomain::class);
    }

    public function backups()
    {
        return $this->hasMany(ContainerBackup::class);
    }

    public function events()
    {
        return $this->hasMany(ContainerDeploymentEvent::class, 'container_deployment_id')
            ->orderByDesc('recorded_at');
    }

    // Status Helpers
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isStopped(): bool
    {
        return $this->status === 'stopped';
    }

    public function isDeploying(): bool
    {
        return $this->status === 'deploying';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isTerminated(): bool
    {
        return $this->status === 'terminated';
    }

    // Access URL Helper
    public function getAccessUrl(): ?string
    {
        $this->loadMissing(['domains', 'node']);

        $active = $this->domains->where('status', 'active');

        // A customer's own domain first, the platform hostname second.
        $preferred = $active->first(fn (ContainerDomain $domain): bool => ! $domain->isPlatformHostname() && filled($domain->domain))
            ?? $active->first(fn (ContainerDomain $domain): bool => $domain->isPlatformHostname() && filled($domain->domain));

        if ($preferred) {
            return 'https://'.ltrim((string) $preferred->domain, '/');
        }

        if (filled($this->domain)) {
            return 'https://'.ltrim((string) $this->domain, '/');
        }

        // An isolated stack publishes on loopback only; node:port would be a
        // dead link. It is still the address of a stack on the old layout.
        if (filled($this->network_subnet)) {
            return null;
        }

        $node = $this->node;
        if ($node && $this->assigned_port) {
            $host = filled($node->hostname) ? $node->hostname : $node->ip_address;
            if (filled($host)) {
                return "http://{$host}:{$this->assigned_port}";
            }
        }

        return null;
    }

    /**
     * Where a probe run on the node itself reaches this stack. Published ports
     * are bound to loopback, so the public URL is not reachable from the host
     * without going through nginx; this is.
     */
    public function loopbackUrl(): ?string
    {
        return $this->assigned_port ? "http://127.0.0.1:{$this->assigned_port}" : null;
    }

    /**
     * The Host header a loopback probe should carry so the application sees
     * the name it is normally served under.
     */
    public function probeHostHeader(): ?string
    {
        $this->loadMissing('domains');
        $domain = $this->domains->firstWhere('status', 'active');

        return $domain ? ltrim((string) $domain->domain, '/') : null;
    }

    // Uptime helper
    public function getUptimeSeconds(): ?int
    {
        if (! $this->deployed_at) {
            return null;
        }

        return now()->diffInSeconds($this->deployed_at);
    }

    // Metrics helper
    public function latestMetric(): ?ContainerMetric
    {
        return $this->metrics()->latest('recorded_at')->first();
    }

    // Primary domain helper
    public function primaryDomain(): ?ContainerDomain
    {
        return $this->domains()->where('status', 'active')->first();
    }
}

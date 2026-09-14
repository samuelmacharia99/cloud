<?php

namespace App\Models;

use App\Services\NodeNameserverService;
use App\Services\SSH\NodeSshCredentials;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    use HasFactory;

    protected $hidden = [
        'ssh_password',
        'ssh_private_key',
        'ssh_key_passphrase',
        'da_login_key',
        'api_token',
    ];

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'type',
        'status',
        'cpu_cores',
        'ram_gb',
        'storage_gb',
        'cpu_used',
        'ram_used_gb',
        'storage_used_gb',
        'ssh_port',
        'ssh_username',
        'ssh_auth_method',
        'ssh_password',
        'ssh_private_key',
        'ssh_key_passphrase',
        'da_admin_username',
        'da_login_key',
        'da_port',
        'api_url',
        'api_token',
        'verify_ssl',
        'region',
        'datacenter',
        'description',
        'monthly_cost_usd',
        'nameserver_1',
        'nameserver_2',
        'nameserver_3',
        'nameserver_4',
        'container_count',
        'last_heartbeat_at',
        'last_health_check_at',
        'is_active',
    ];

    protected $casts = [
        'cpu_cores' => 'integer',
        'ram_gb' => 'integer',
        'storage_gb' => 'integer',
        'cpu_used' => 'integer',
        'ram_used_gb' => 'integer',
        'storage_used_gb' => 'integer',
        'container_count' => 'integer',
        'monthly_cost_usd' => 'decimal:2',
        'verify_ssl' => 'boolean',
        'is_active' => 'boolean',
        'ssh_password' => 'encrypted',
        'ssh_private_key' => 'encrypted',
        'ssh_key_passphrase' => 'encrypted',
        'da_login_key' => 'encrypted',
        'api_token' => 'encrypted',
        'last_heartbeat_at' => 'datetime',
        'last_health_check_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * How SSH logs in: the stored method, or inferred from which secrets exist
     * for rows written before the method column.
     */
    public function sshAuthMethod(): string
    {
        $method = (string) ($this->ssh_auth_method ?? '');
        if (in_array($method, [NodeSshCredentials::METHOD_PASSWORD, NodeSshCredentials::METHOD_KEY], true)) {
            return $method;
        }

        return blank($this->ssh_password) && NodeSshCredentials::storedPrivateKey($this) !== null
            ? NodeSshCredentials::METHOD_KEY
            : NodeSshCredentials::METHOD_PASSWORD;
    }

    public function hasSshPrivateKey(): bool
    {
        return NodeSshCredentials::storedPrivateKey($this) !== null;
    }

    /**
     * A username plus at least one secret (password or private key).
     */
    public function hasSshCredentials(): bool
    {
        return filled($this->ssh_username)
            && (filled($this->ssh_password) || $this->hasSshPrivateKey());
    }

    // Relationships
    public function services()
    {
        return $this->hasMany(Service::class, 'node_id');
    }

    /**
     * Services hosted on this node via direct assignment or current container deployment.
     */
    public function servicesOnNodeQuery(): Builder
    {
        return Service::query()
            ->where(function ($query) {
                $query->where('node_id', $this->id)
                    ->orWhereHas('containerDeployment', function ($q) {
                        $q->where('node_id', $this->id);
                    });
            });
    }

    public function containerDeployments()
    {
        return $this->hasMany(ContainerDeployment::class);
    }

    public function monitoring()
    {
        return $this->hasMany(NodeMonitoring::class, 'node_id');
    }

    public function latestMonitoring()
    {
        return $this->hasOne(NodeMonitoring::class, 'node_id')->latest('recorded_at');
    }

    public function directAdminPackages()
    {
        return $this->hasMany(DirectAdminPackage::class);
    }

    public function assignedResellers()
    {
        return $this->hasMany(User::class, 'reseller_node_id')
            ->where('is_reseller', true);
    }

    /**
     * USD row used for spend conversion. Missing or zero rates must not convert.
     */
    public static function usdCurrency(): ?Currency
    {
        $usd = Currency::query()->where('code', 'USD')->first();

        if (! $usd || (float) $usd->exchange_rate <= 0) {
            return null;
        }

        return $usd;
    }

    /**
     * How many KES one USD is worth at the current catalog rate.
     */
    public static function usdToKesRate(): ?float
    {
        $usd = static::usdCurrency();

        return $usd ? (1 / (float) $usd->exchange_rate) : null;
    }

    /**
     * Monthly node spend converted to KES using the live USD rate. Not snapshotted.
     */
    public function monthlyCostKes(): ?float
    {
        if ($this->monthly_cost_usd === null) {
            return null;
        }

        $usd = static::usdCurrency();
        if (! $usd) {
            return null;
        }

        return round($usd->convertToKES((float) $this->monthly_cost_usd), 2);
    }

    // Helper Methods
    public function isMonitored(): bool
    {
        return in_array($this->type, ['container_host', 'database_server', 'directadmin', 'mailcow']);
    }

    public function isHealthy(): bool
    {
        return $this->status === 'online' && $this->is_active;
    }

    public function isOffline(): bool
    {
        return $this->status === 'offline';
    }

    public function isDegraded(): bool
    {
        return $this->status === 'degraded';
    }

    public function getAvailableCpuCores(): int
    {
        return max(0, $this->cpu_cores - ($this->cpu_used * $this->cpu_cores / 100));
    }

    public function getAvailableRamGb(): int
    {
        return max(0, $this->ram_gb - $this->ram_used_gb);
    }

    public function getAvailableStorageGb(): int
    {
        return max(0, $this->storage_gb - $this->storage_used_gb);
    }

    public function getCpuUsagePercentage(): int
    {
        if ($this->cpu_cores === 0) {
            return 0;
        }

        return min(100, $this->cpu_used);
    }

    public function getRamUsagePercentage(): int
    {
        if ($this->ram_gb === 0) {
            return 0;
        }

        return (int) (($this->ram_used_gb / $this->ram_gb) * 100);
    }

    public function getStorageUsagePercentage(): int
    {
        if ($this->storage_gb === 0) {
            return 0;
        }

        return (int) (($this->storage_used_gb / $this->storage_gb) * 100);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'online' => 'emerald',
            'offline' => 'red',
            'degraded' => 'amber',
            'maintenance' => 'blue',
            default => 'slate',
        };
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'dedicated_server' => 'Dedicated Server',
            'container_host' => 'Application Host',
            'load_balancer' => 'Load Balancer',
            'database_server' => 'Database Server',
            'directadmin' => 'DirectAdmin Server',
            'mailcow' => 'Mailcow (Email)',
            default => 'Unknown',
        };
    }

    public function getMailcowPanelUrl(): ?string
    {
        if ($this->type !== 'mailcow') {
            return null;
        }

        $url = trim((string) ($this->api_url ?: ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        if (empty($this->hostname)) {
            return null;
        }

        return 'https://'.$this->hostname;
    }

    public function isMailcow(): bool
    {
        return $this->type === 'mailcow';
    }

    public function getDirectAdminPanelUrl(): ?string
    {
        if ($this->type !== 'directadmin' || empty($this->hostname)) {
            return null;
        }

        $port = $this->da_port ?: '2222';

        return 'https://'.$this->hostname.':'.$port;
    }

    public function updateUtilization(int $cpuUsed, int $ramUsedGb, int $storageUsedGb): void
    {
        $this->update([
            'cpu_used' => $cpuUsed,
            'ram_used_gb' => $ramUsedGb,
            'storage_used_gb' => $storageUsedGb,
            'last_health_check_at' => now(),
        ]);
    }

    public function recordHeartbeat(): void
    {
        $this->update([
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function nameservers(): array
    {
        return app(NodeNameserverService::class)->forNode($this);
    }
}

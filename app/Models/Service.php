<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_id',
        'order_item_id',
        'reseller_id',
        'invoice_id',
        'node_id',
        'name',
        'provisioning_driver_key',
        'status',
        'live_status',
        'live_status_label',
        'live_status_source',
        'live_status_checked_at',
        'live_status_detail',
        'live_status_mismatch',
        'billing_cycle',
        'custom_price',
        'next_due_date',
        'commenced_at',
        'suspend_date',
        'terminate_date',
        'service_meta',
        'external_reference',
        'credentials',
        'notes',
    ];

    protected $casts = [
        'service_meta' => 'array',
        'next_due_date' => 'datetime',
        'commenced_at' => 'datetime',
        'suspend_date' => 'datetime',
        'terminate_date' => 'datetime',
        'custom_price' => 'decimal:2',
        'status' => ServiceStatus::class,
        'live_status_checked_at' => 'datetime',
        'live_status_detail' => 'array',
        'live_status_mismatch' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function reseller()
    {
        return $this->belongsTo(User::class, 'reseller_id');
    }

    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    public function containerDeployment()
    {
        // A service can accumulate multiple deployment rows across redeploys;
        // the current one is always the most recent.
        return $this->hasOne(ContainerDeployment::class)->latestOfMany();
    }

    public function containerBackups()
    {
        return $this->hasMany(ContainerBackup::class);
    }

    public function containerAppInitializations()
    {
        return $this->hasMany(ContainerAppInitialization::class);
    }

    // Status helpers
    public function isActive(): bool
    {
        return $this->status === ServiceStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === ServiceStatus::Suspended;
    }

    public function isTerminated(): bool
    {
        return $this->status === ServiceStatus::Terminated;
    }

    public function isPending(): bool
    {
        return $this->status === ServiceStatus::Pending;
    }

    /**
     * Unpaid invoice blocking activation for a newly ordered pending service.
     */
    public function unpaidActivationInvoice(): ?Invoice
    {
        if (! $this->isPending()) {
            return null;
        }

        $invoice = $this->relationLoaded('invoice') ? $this->invoice : null;
        if (! $invoice && $this->invoice_id) {
            $invoice = $this->invoice()->first();
        }

        if (! $invoice) {
            $invoice = $this->invoiceItems()->with('invoice')->latest('id')->first()?->invoice;
        }

        if (! $invoice) {
            return null;
        }

        $status = $invoice->status instanceof InvoiceStatus
            ? $invoice->status
            : InvoiceStatus::tryFrom((string) $invoice->status);

        if (! in_array($status, [InvoiceStatus::Draft, InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true)) {
            return null;
        }

        return $invoice;
    }

    public function isProvisioning(): bool
    {
        return $this->status === ServiceStatus::Provisioning;
    }

    public function isFailed(): bool
    {
        return $this->status === ServiceStatus::Failed;
    }

    public function provisioningDriver(): ?string
    {
        return $this->provisioning_driver_key ?: $this->product?->provisioning_driver_key;
    }

    /**
     * Resolve a DirectAdmin/hosting username for external_reference assignment.
     * Reclaims the reference from terminal duplicate services when safe.
     *
     * @throws \InvalidArgumentException
     */
    public static function resolveExternalReferenceForAssignment(string $username, int $serviceId): string
    {
        $username = trim($username);

        if ($username === '') {
            throw new \InvalidArgumentException('Hosting username is required.');
        }

        $conflict = static::query()
            ->where('external_reference', $username)
            ->where('id', '!=', $serviceId)
            ->first();

        if (! $conflict) {
            return $username;
        }

        $status = $conflict->status instanceof ServiceStatus
            ? $conflict->status
            : ServiceStatus::tryFrom((string) $conflict->status);

        if ($status?->isTerminal()) {
            $conflict->update(['external_reference' => null]);

            return $username;
        }

        throw new \InvalidArgumentException(
            "Hosting username \"{$username}\" is already linked to service #{$conflict->id} ({$conflict->name})."
        );
    }

    public function isSharedHosting(): bool
    {
        if ($this->product?->type !== 'shared_hosting') {
            return false;
        }

        if ($this->provisioningDriver() === 'directadmin') {
            return true;
        }

        $meta = $this->service_meta ?? [];

        if (filled($this->external_reference) || filled($meta['username'] ?? null)) {
            return true;
        }

        if ($this->node_id) {
            if ($this->relationLoaded('node')) {
                return $this->node?->type === 'directadmin';
            }

            return true;
        }

        return false;
    }

    /**
     * Backfill provisioning_driver_key for legacy shared hosting accounts on DirectAdmin.
     */
    public function normalizeDirectAdminProvisioning(): bool
    {
        if (! $this->isSharedHosting() || $this->provisioning_driver_key === 'directadmin') {
            return false;
        }

        $this->update(['provisioning_driver_key' => 'directadmin']);

        return true;
    }

    public function isContainerHosting(): bool
    {
        return $this->product?->type === 'container_hosting'
            || $this->provisioningDriver() === 'container';
    }

    public function supportsLiveStatusProbe(): bool
    {
        return $this->isSharedHosting() || $this->isContainerHosting();
    }

    public function hasLiveStatusMismatch(): bool
    {
        return (bool) $this->live_status_mismatch;
    }

    /**
     * @return array{username: string, password: string, domain?: string, panel_url?: string}|null
     */
    public function getHostingCredentials(): ?array
    {
        $panelUrl = $this->getDirectAdminPanelUrl();

        if ($this->credentials) {
            $decoded = json_decode($this->credentials, true);
            if (is_array($decoded) && ! empty($decoded['username'])) {
                return array_merge($decoded, [
                    'panel_url' => $panelUrl,
                ]);
            }
        }

        $meta = $this->service_meta ?? [];
        if (empty($meta['username']) || empty($meta['password'])) {
            return null;
        }

        return [
            'username' => (string) $meta['username'],
            'password' => (string) $meta['password'],
            'domain' => $meta['domain'] ?? null,
            'panel_url' => $panelUrl,
        ];
    }

    public function getDirectAdminPanelUrl(): ?string
    {
        if (! $this->node || $this->node->type !== 'directadmin') {
            return null;
        }

        $port = $this->node->da_port ?: '2222';

        if ($this->isSharedHosting()) {
            $domain = $this->attachedDomainName();
            if ($domain) {
                return 'https://'.trim($domain).':'.$port;
            }
        }

        return $this->node->getDirectAdminPanelUrl();
    }

    /**
     * Primary domain attached to this service (hosting), if any.
     * VPS and dedicated servers intentionally return null.
     */
    public function attachedDomainName(): ?string
    {
        $productType = $this->product?->type;
        if ($productType && Product::isServerType($productType)) {
            return null;
        }

        $meta = $this->service_meta ?? [];

        if (! empty($meta['domain'])) {
            return (string) $meta['domain'];
        }

        if (! empty($meta['domain_id'])) {
            $domain = Domain::query()->find($meta['domain_id']);

            return $domain?->fqdn();
        }

        if ($this->isContainerHosting()) {
            $deployment = $this->relationLoaded('containerDeployment')
                ? $this->containerDeployment
                : $this->containerDeployment()->with('domains')->first();

            if ($deployment?->domain) {
                return $deployment->domain;
            }

            $domains = $deployment?->relationLoaded('domains')
                ? $deployment->domains
                : $deployment?->domains;

            $customDomain = $domains?->firstWhere('status', 'active') ?? $domains?->first();

            if ($customDomain?->domain) {
                return $customDomain->domain;
            }
        }

        return null;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', ServiceStatus::Active);
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', ServiceStatus::Suspended);
    }

    public function scopeTerminated($query)
    {
        return $query->where('status', ServiceStatus::Terminated);
    }

    protected static function booted(): void
    {
        static::creating(function (Service $service) {
            if ($service->reseller_id || ! $service->user_id) {
                return;
            }

            $user = User::query()->find($service->user_id);
            if ($user?->reseller_id) {
                $service->reseller_id = $user->reseller_id;
            }
        });
    }
}

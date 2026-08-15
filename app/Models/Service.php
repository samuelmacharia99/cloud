<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Services\ResellerProvisionProductResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    /**
     * Resolve the actual application stack for both stack-specific products
     * and shared plans that persist the selected stack in service_meta.
     */
    public function effectiveContainerTemplate(): ?ContainerTemplate
    {
        $this->loadMissing('product.containerTemplate');
        $meta = is_array($this->service_meta) ? $this->service_meta : [];

        $slug = $meta['provision_template_slug'] ?? null;
        if (is_string($slug) && $slug !== '') {
            $template = $this->findContainerTemplateBySlug($slug);
            if ($template) {
                return $template;
            }
        }

        $templateId = (int) ($meta['container_template_id'] ?? 0);
        if ($templateId > 0) {
            $template = ContainerTemplate::query()
                ->whereKey($templateId)
                ->orderByRaw('is_active DESC')
                ->first();
            if ($template) {
                return $template;
            }
        }

        $languageSlug = $meta['language_slug'] ?? null;
        if (is_string($languageSlug) && $languageSlug !== '') {
            $template = $this->findContainerTemplateBySlug($languageSlug);
            if ($template) {
                return $template;
            }
        }

        return $this->product?->containerTemplate;
    }

    /**
     * Whether this service runs the WordPress application stack.
     *
     * Reads every place the stack can be recorded, because shared plans keep
     * the choice in service_meta while stack-specific products keep it on the
     * product template. When metadata is incomplete, fall back to fingerprints
     * left on the live deployment (compose image / WORDPRESS_* env).
     */
    public function isWordPressContainer(): bool
    {
        if (! $this->isContainerHosting()) {
            return false;
        }

        if ($this->slugIsWordPress($this->effectiveContainerTemplate()?->slug)) {
            return true;
        }

        if ($this->slugIsWordPress($this->product?->containerTemplate?->slug)) {
            return true;
        }

        $meta = is_array($this->service_meta) ? $this->service_meta : [];

        foreach (['provision_template_slug', 'language_slug', 'backend', 'framework', 'stack'] as $key) {
            if ($this->slugIsWordPress($meta[$key] ?? null)) {
                return true;
            }
        }

        foreach (['application_stack', 'language_name'] as $key) {
            if ($this->textMentionsWordPress($meta[$key] ?? null)) {
                return true;
            }
        }

        return $this->deploymentLooksLikeWordPress();
    }

    private function slugIsWordPress(mixed $slug): bool
    {
        return is_string($slug) && strtolower(trim($slug)) === 'wordpress';
    }

    private function textMentionsWordPress(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return str_contains(strtolower($value), 'wordpress');
    }

    private function deploymentLooksLikeWordPress(): bool
    {
        $this->loadMissing('containerDeployment');
        $deployment = $this->containerDeployment;
        if (! $deployment) {
            return false;
        }

        $env = is_array($deployment->env_values) ? $deployment->env_values : [];
        foreach (array_keys($env) as $key) {
            if (is_string($key) && str_starts_with(strtoupper($key), 'WORDPRESS_')) {
                return true;
            }
        }

        $compose = strtolower((string) ($deployment->docker_compose_content ?? ''));
        if ($compose !== '' && (
            str_contains($compose, 'image: wordpress')
            || str_contains($compose, 'image:wordpress')
            || str_contains($compose, '/wordpress:')
            || str_contains($compose, 'wordpress:latest')
            || str_contains($compose, 'wordpress:php')
        )) {
            return true;
        }

        $credentials = is_array($this->credentials) ? $this->credentials : [];
        foreach (array_keys($credentials) as $key) {
            if (is_string($key) && str_contains(strtolower($key), 'wordpress')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer the active template row, but never let a deactivated catalog entry
     * silently change the stack of an already provisioned service.
     */
    private function findContainerTemplateBySlug(string $slug): ?ContainerTemplate
    {
        return ContainerTemplate::query()
            ->where('slug', $slug)
            ->orderByRaw('is_active DESC')
            ->first();
    }

    protected $fillable = [
        'user_id',
        'project_id',
        'product_id',
        'reseller_product_id',
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

    public function project()
    {
        return $this->belongsTo(CustomerProject::class, 'project_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function resellerProduct()
    {
        return $this->belongsTo(ResellerProduct::class);
    }

    public function customerPlanName(): string
    {
        $this->loadMissing(['product', 'resellerProduct', 'user']);
        $ownerId = (int) ($this->reseller_id ?: $this->user?->reseller_id);
        $listing = $this->resellerProduct;

        if ($listing
            && $ownerId > 0
            && (int) $listing->reseller_id === $ownerId
            && filled($listing->name)) {
            return trim((string) $listing->name);
        }

        if ($this->product?->slug === ResellerProvisionProductResolver::SHELL_PRODUCT_SLUG) {
            return 'Shared Hosting';
        }

        return filled($this->product?->name)
            ? trim((string) $this->product->name)
            : 'Service';
    }

    public function customerServiceName(): string
    {
        $name = trim((string) $this->name);

        if ($name !== ''
            && ! str_contains(strtolower($name), 'reseller directadmin hosting')
            && ! str_contains(strtolower($name), '(system)')) {
            return $name;
        }

        $meta = is_array($this->service_meta) ? $this->service_meta : [];
        $domain = trim((string) ($meta['domain'] ?? $meta['primary_domain'] ?? ''));

        return $domain !== '' ? $domain : $this->customerPlanName();
    }

    public function customerPlanTypeLabel(): string
    {
        return match ($this->product?->type) {
            'shared_hosting' => 'Hosting',
            'container_hosting' => 'Application Hosting',
            'email_hosting' => 'Email Hosting',
            'vps' => 'VPS',
            'dedicated_server' => 'Dedicated Server',
            default => ucfirst(str_replace('_', ' ', (string) ($this->product?->type ?? 'Service'))),
        };
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

    public function containerCronJobs()
    {
        return $this->hasMany(ContainerCronJob::class);
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

    public function isEmailHosting(): bool
    {
        return $this->product?->type === 'email_hosting'
            || $this->provisioningDriver() === 'mailcow';
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

    /**
     * Extend the billing period from the current next due date (or today if overdue).
     */
    public function calculateNextDueDateAfterRenewal(?Carbon $reference = null): Carbon
    {
        $reference = ($reference ?? now())->copy()->startOfDay();
        $base = $this->next_due_date?->copy()->startOfDay() ?? $reference;

        if ($base->lessThan($reference)) {
            $base = $reference;
        }

        return match ($this->billing_cycle) {
            'monthly' => $base->copy()->addMonthNoOverflow(),
            'quarterly' => $base->copy()->addMonthsNoOverflow(3),
            'semi-annual' => $base->copy()->addMonthsNoOverflow(6),
            'annual', 'yearly' => $base->copy()->addYearNoOverflow(),
            default => $base->copy()->addMonthNoOverflow(),
        };
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
        static::saving(function (Service $service): void {
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $listingId = (int) ($meta['reseller_product_id'] ?? 0);

            if ($listingId <= 0) {
                if (array_key_exists('reseller_product_id', $meta) && empty($meta['reseller_product_id'])) {
                    $service->reseller_product_id = null;
                }

                return;
            }

            $listingExists = ResellerProduct::query()->whereKey($listingId)->exists();
            $service->reseller_product_id = $listingExists ? $listingId : null;

            if (! $listingExists) {
                unset($meta['reseller_product_id']);
                $service->service_meta = $meta;
            }
        });

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

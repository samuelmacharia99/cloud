<?php

namespace App\Models;

use App\Services\ResellerProvisionProductResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'type',
        'price',
        'monthly_price',
        'yearly_price',
        'wholesale_monthly_price',
        'wholesale_yearly_price',
        'billing_cycle',
        'features',
        'setup_fee',
        'provisioning_driver_key',
        'resource_limits',
        'container_template_id',
        'direct_admin_package_id',
        'cpu_overage_rate',
        'ram_overage_rate',
        'disk_overage_rate',
        'overage_enabled',
        'is_active',
        'visible_to_resellers',
        'featured',
        'order',
    ];

    protected $casts = [
        'features' => 'array',
        'resource_limits' => 'array',
        'is_active' => 'boolean',
        'visible_to_resellers' => 'boolean',
        'featured' => 'boolean',
        'price' => 'decimal:2',
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'wholesale_monthly_price' => 'decimal:2',
        'wholesale_yearly_price' => 'decimal:2',
        'setup_fee' => 'decimal:2',
        'cpu_overage_rate' => 'float',
        'ram_overage_rate' => 'float',
        'disk_overage_rate' => 'float',
        'overage_enabled' => 'boolean',
    ];

    const TYPES = [
        'shared_hosting' => 'Shared (email & legacy)',
        'container_hosting' => 'App Hosting',
        'ssl' => 'SSL Certificate',
        'email_hosting' => 'Email Hosting',
        'vps' => 'VPS Server',
        'dedicated_server' => 'Dedicated Server',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($product) {
            if ($product->type === 'container_hosting' && ! $product->provisioning_driver_key) {
                $product->provisioning_driver_key = 'container';
            }
            if ($product->type === 'email_hosting' && ! $product->provisioning_driver_key) {
                $product->provisioning_driver_key = 'mailcow';
            }
            if ($product->type === 'email_hosting' && $product->provisioning_driver_key === 'roundcube') {
                $product->provisioning_driver_key = 'mailcow';
            }
        });
    }

    public function services()
    {
        return $this->hasMany(Service::class);
    }

    public function invoiceItems()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Whether this product can be permanently removed from the catalog.
     */
    public function canBeDeleted(): bool
    {
        return $this->deletionBlockers() === [];
    }

    /**
     * @return list<string> Human-readable reasons deletion is blocked.
     */
    public function deletionBlockers(): array
    {
        if ($this->slug === ResellerProvisionProductResolver::SHELL_PRODUCT_SLUG) {
            return ['This is a system product used for reseller DirectAdmin provisioning and cannot be deleted. Deactivate it instead if needed.'];
        }

        $blockers = [];

        $servicesCount = $this->services()->count();
        if ($servicesCount > 0) {
            $blockers[] = "It is linked to {$servicesCount} service(s).";
        }

        $invoiceItemsCount = $this->invoiceItems()->count();
        if ($invoiceItemsCount > 0) {
            $blockers[] = "It appears on {$invoiceItemsCount} invoice line item(s).";
        }

        $orderItemsCount = $this->orderItems()->count();
        if ($orderItemsCount > 0) {
            $blockers[] = "It appears on {$orderItemsCount} order line item(s).";
        }

        return $blockers;
    }

    public function deletionBlockedMessage(): string
    {
        $blockers = $this->deletionBlockers();

        if ($blockers === []) {
            return '';
        }

        return 'This product cannot be deleted. '.implode(' ', $blockers)
            .' Deactivate the product instead to hide it from new orders.';
    }

    public function containerTemplate()
    {
        return $this->belongsTo(ContainerTemplate::class);
    }

    public function directAdminPackage()
    {
        return $this->belongsTo(DirectAdminPackage::class);
    }

    /**
     * Get the label for a product type
     */
    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Check if a product type is a server type (VPS or Dedicated Server)
     */
    public static function isServerType(string $type): bool
    {
        return in_array($type, ['vps', 'dedicated_server']);
    }

    /**
     * Included CPU (cores), memory (MB), and disk (GB) for container overage billing.
     * Product resource_limits take precedence, then template, then deployment overrides.
     *
     * @return array{cpu: float, memory_mb: int, disk_gb: float}
     */
    public function getIncludedContainerLimits(
        ?ContainerTemplate $template = null,
        ?ContainerDeployment $deployment = null
    ): array {
        $limits = $this->resource_limits ?? [];

        $cpu = isset($limits['cpu']) && $limits['cpu'] !== '' && $limits['cpu'] !== null
            ? (float) $limits['cpu']
            : null;

        $memoryMb = isset($limits['memory']) && $limits['memory'] !== '' && $limits['memory'] !== null
            ? (int) $limits['memory']
            : null;

        $diskGb = isset($limits['disk']) && $limits['disk'] !== '' && $limits['disk'] !== null
            ? (float) $limits['disk']
            : null;

        if ($cpu === null && $template) {
            $cpu = (float) $template->required_cpu_cores;
        }
        if ($cpu === null && $deployment?->cpu_limit) {
            $cpu = (float) $deployment->cpu_limit;
        }

        if ($memoryMb === null && $template) {
            $memoryMb = (int) $template->required_ram_mb;
        }
        if ($memoryMb === null && $deployment?->memory_limit_mb) {
            $memoryMb = (int) $deployment->memory_limit_mb;
        }

        if ($diskGb === null && $template) {
            $diskGb = (float) $template->required_storage_gb;
        }

        return [
            'cpu' => $cpu ?? 1.0,
            'memory_mb' => $memoryMb ?? 256,
            'disk_gb' => $diskGb ?? (float) ($template?->required_storage_gb ?? 0),
        ];
    }
}

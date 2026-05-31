<?php

namespace App\Models;

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
        'shared_hosting' => 'Shared Hosting',
        'container_hosting' => 'Container Hosting',
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

        return [
            'cpu' => $cpu ?? 1.0,
            'memory_mb' => $memoryMb ?? 256,
            'disk_gb' => $diskGb ?? 0.0,
        ];
    }
}

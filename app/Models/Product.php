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
        'billing_cycle',
        'features',
        'setup_fee',
        'provisioning_driver_key',
        'resource_limits',
        'container_template_id',
        'cpu_overage_rate',
        'ram_overage_rate',
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
        'setup_fee' => 'decimal:2',
        'cpu_overage_rate' => 'float',
        'ram_overage_rate' => 'float',
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
}

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
    ];

    const TYPES = [
        'shared_hosting' => 'Shared Hosting',
        'container_hosting' => 'Container Hosting',
        'domain' => 'Domain',
        'ssl' => 'SSL Certificate',
        'email_hosting' => 'Email Hosting',
        'sms_bundle' => 'SMS Bundle',
        'hotspot_plan' => 'Hotspot Plan',
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
}

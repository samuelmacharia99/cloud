<?php

namespace App\Models;

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
        'billing_cycle',
        'next_due_date',
        'suspend_date',
        'terminate_date',
        'service_meta',
        'external_reference',
        'credentials',
    ];

    protected $casts = [
        'service_meta' => 'array',
        'next_due_date' => 'datetime',
        'suspend_date' => 'datetime',
        'terminate_date' => 'datetime',
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
        return $this->hasOne(ContainerDeployment::class);
    }

    // Status helpers
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isTerminated(): bool
    {
        return $this->status === 'terminated';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProvisioning(): bool
    {
        return $this->status === 'provisioning';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeTerminated($query)
    {
        return $query->where('status', 'terminated');
    }
}

<?php

namespace App\Models;

use App\Services\ResellerProvisionProductResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'billing_cycle',
        'custom_options',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'custom_options' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service()
    {
        return $this->hasOne(Service::class, 'order_item_id');
    }

    public function displayTitle(): string
    {
        $this->loadMissing(['service', 'product', 'order.user']);

        if ($this->service) {
            return $this->service->customerPlanName();
        }

        $listingId = (int) (($this->custom_options ?? [])['reseller_product_id'] ?? 0);
        $resellerId = (int) $this->order?->user?->reseller_id;
        if ($listingId > 0 && $resellerId > 0) {
            $name = ResellerProduct::query()
                ->whereKey($listingId)
                ->where('reseller_id', $resellerId)
                ->value('name');

            if (filled($name)) {
                return trim((string) $name);
            }
        }

        if ($this->product?->slug === ResellerProvisionProductResolver::SHELL_PRODUCT_SLUG) {
            return 'Shared Hosting';
        }

        return $this->product?->name ?? 'Unknown Product';
    }
}

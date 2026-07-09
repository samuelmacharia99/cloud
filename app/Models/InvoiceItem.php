<?php

namespace App\Models;

use App\Services\Billing\InvoiceCurrencyService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'service_id',
        'product_id',
        'product_type',
        'domain_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'custom_options',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'custom_options' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $item) {
            app(InvoiceCurrencyService::class)->convertItemToInvoiceCurrency($item);
        });
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Domain label for invoice display (service-attached or domain line item).
     * Hidden for VPS and dedicated server line items.
     */
    public function attachedDomainLabel(): ?string
    {
        if ($this->domain_id && $this->domain) {
            return $this->domain->fqdn();
        }

        if (! $this->service_id || ! $this->service) {
            return null;
        }

        $productType = $this->product?->type ?? $this->service->product?->type;
        if ($productType && Product::isServerType($productType)) {
            return null;
        }

        return $this->service->attachedDomainName();
    }

    public function displayTitle(): string
    {
        if ($this->domain_id) {
            return 'Domain';
        }

        return match ($this->product_type) {
            'reseller_package' => $this->resellerPackageNameFromDescription() ?? 'Reseller Package',
            'reseller_disk_usage' => 'Disk Usage',
            'reseller_disk_overage' => 'Disk Overage',
            'Domain' => 'Domain',
            default => $this->product?->name
                ?? $this->inferTitleFromDescription()
                ?? 'Unknown Product',
        };
    }

    private function inferTitleFromDescription(): ?string
    {
        $description = $this->description ?? '';

        if (str_starts_with($description, 'Disk usage')) {
            return 'Disk Usage';
        }

        if (str_starts_with($description, 'Disk overage')) {
            return 'Disk Overage';
        }

        return null;
    }

    private function resellerPackageNameFromDescription(): ?string
    {
        $description = $this->description ?? '';

        if (preg_match('/Reseller Package Renewal:\s*(.+?)\s*\(/s', $description, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/Reseller Package Upgrade:\s*(.+?)\s*\(/s', $description, $matches)) {
            return 'Upgrade: '.trim($matches[1]);
        }

        if (preg_match('/Reseller Package Downgrade:\s*(.+?)\s*\(/s', $description, $matches)) {
            return 'Downgrade: '.trim($matches[1]);
        }

        if (preg_match('/Reseller Package:\s*(.+?)\s*\(/s', $description, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}

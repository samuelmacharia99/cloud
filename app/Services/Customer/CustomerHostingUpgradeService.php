<?php

namespace App\Services\Customer;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use App\Services\Provisioning\DirectAdminSetupService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\TaxService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerHostingUpgradeService
{
    public function __construct(
        private DirectAdminSetupService $directAdminSetup,
        private ResellerCustomerCatalogService $catalog,
    ) {}

    /**
     * @return Collection<int, Product>
     */
    public function upgradeOptions(Service $service, User $customer): Collection
    {
        if ($service->user_id !== $customer->id) {
            return collect();
        }

        if ($service->product?->type !== 'shared_hosting') {
            return collect();
        }

        if (! in_array($service->status->value, ['active', 'suspended'], true)) {
            return collect();
        }

        $currentDisk = (float) ($service->product->directAdminPackage?->disk_quota ?? 0);

        $query = Product::query()
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->where('id', '!=', $service->product_id)
            ->whereHas('directAdminPackage', fn ($q) => $q->where('disk_quota', '>', $currentDisk))
            ->with('directAdminPackage')
            ->orderBy('monthly_price');

        $this->catalog->scopePlatformProducts($query, $customer);

        return $query->get();
    }

    public function createUpgradeInvoice(Service $service, User $customer, Product $targetProduct): Invoice
    {
        if ($service->user_id !== $customer->id) {
            throw new \InvalidArgumentException('Unauthorized service upgrade.');
        }

        if ($targetProduct->type !== 'shared_hosting') {
            throw new \InvalidArgumentException('Target product is not a shared hosting plan.');
        }

        $options = $this->upgradeOptions($service, $customer);

        if (! $options->contains('id', $targetProduct->id)) {
            throw new \InvalidArgumentException('Selected plan is not a valid upgrade for this service.');
        }

        $price = $this->proratedUpgradePrice($service, $targetProduct);
        $taxBreakdown = TaxService::calculateForUser($price, $service->user);
        $tax = $taxBreakdown['tax'];
        $total = $taxBreakdown['total'];
        $dueDate = now()->addDays((int) Setting::getValue('invoice_due_days', 14))->toDateString();
        $prefix = Setting::getValue('invoice_prefix', 'INV');

        return DB::transaction(function () use ($service, $customer, $targetProduct, $prefix, $price, $tax, $total, $dueDate, $taxBreakdown) {
            $year = now()->format('Y');
            $sequence = Invoice::whereYear('created_at', $year)->lockForUpdate()->count() + 1;
            $number = $prefix.'-'.$year.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

            $invoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => $number,
                'status' => 'unpaid',
                'due_date' => $dueDate,
                'subtotal' => $taxBreakdown['subtotal'],
                'tax' => $tax,
                'total' => $total,
                'notes' => "Hosting upgrade: {$service->product->name} → {$targetProduct->name}",
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $targetProduct->id,
                'description' => "Upgrade to {$targetProduct->name}",
                'quantity' => 1,
                'unit_price' => $price,
                'amount' => $price,
                'custom_options' => [
                    'hosting_upgrade' => true,
                    'from_product_id' => $service->product_id,
                    'to_product_id' => $targetProduct->id,
                ],
            ]);

            return $invoice;
        });
    }

    public function applyPaidUpgradesForInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items.service.product', 'items.product');

        foreach ($invoice->items as $item) {
            $options = $item->custom_options ?? [];

            if (empty($options['hosting_upgrade']) || empty($options['to_product_id'])) {
                continue;
            }

            $service = $item->service;
            $targetProduct = $item->product ?? Product::find($options['to_product_id']);

            if (! $service || ! $targetProduct) {
                continue;
            }

            try {
                $this->applyUpgrade($service, $targetProduct);
            } catch (\Throwable $e) {
                Log::error('Failed to apply hosting upgrade after payment', [
                    'invoice_id' => $invoice->id,
                    'service_id' => $service->id,
                    'target_product_id' => $targetProduct->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function applyUpgrade(Service $service, Product $targetProduct): void
    {
        $service->loadMissing('node', 'reseller', 'product.directAdminPackage');

        if (! $service->node || $service->node->type !== 'directadmin') {
            throw new \RuntimeException('Service is not on a DirectAdmin node.');
        }

        $package = $targetProduct->directAdminPackage;

        if (! $package) {
            throw new \RuntimeException('Target product has no DirectAdmin package.');
        }

        $meta = $service->service_meta ?? [];
        $username = $meta['username'] ?? null;

        if (! $username) {
            throw new \RuntimeException('Hosting username not found on service.');
        }

        $directAdmin = new DirectAdminService($service->node);

        $ownerReseller = $service->reseller?->directadmin_username;
        $this->directAdminSetup->ensurePackageLimitsOnServer($directAdmin, $service, $ownerReseller);

        $result = $directAdmin->changeUserPackage($username, $package->package_key);

        if (! $result['success']) {
            throw new \RuntimeException($result['message']);
        }

        $service->update([
            'product_id' => $targetProduct->id,
            'service_meta' => array_merge($meta, [
                'package' => $package->package_key,
                'package_name' => $package->name,
            ]),
        ]);
    }

    private function proratedUpgradePrice(Service $service, Product $targetProduct): float
    {
        $cycle = $service->billing_cycle ?? 'monthly';
        $current = match ($cycle) {
            'annual' => (float) ($service->product->yearly_price ?: $service->product->monthly_price * 12),
            default => (float) $service->product->monthly_price,
        };
        $target = match ($cycle) {
            'annual' => (float) ($targetProduct->yearly_price ?: $targetProduct->monthly_price * 12),
            default => (float) $targetProduct->monthly_price,
        };

        $diff = max(0, $target - $current);

        if ($diff <= 0) {
            return max(0, $target);
        }

        if (! $service->next_due_date || $service->next_due_date->isPast()) {
            return round($diff, 2);
        }

        $daysRemaining = max(1, now()->diffInDays($service->next_due_date));
        $cycleDays = $cycle === 'annual' ? 365 : 30;

        return round($diff * ($daysRemaining / $cycleDays), 2);
    }
}

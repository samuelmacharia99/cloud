<?php

namespace App\Services\Checkout;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminDomainValidator;
use App\Services\Provisioning\MailcowProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Bundle an email hosting service with a platform container product at checkout.
 */
class ContainerEmailBundleService
{
    public function __construct(
        private DirectAdminDomainValidator $domainValidator,
        private MailcowProvisioningService $mailcow,
    ) {}

    /**
     * @param  array<string, mixed>  $cart
     * @return list<array{key: string, product: Product, email_product: Product}>
     */
    public function bundledContainerItems(array $cart): array
    {
        $items = [];

        foreach ($cart as $key => $item) {
            if (($item['type'] ?? null) !== 'product') {
                continue;
            }

            $product = Product::query()->with('bundledEmailProduct')->find($item['product_id'] ?? null);
            if (! $product || ! $product->hasEmailBundle()) {
                continue;
            }

            $emailProduct = $product->bundledEmailProduct;
            if (! $emailProduct || $emailProduct->type !== 'email_hosting' || ! $emailProduct->is_active) {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'product' => $product,
                'email_product' => $emailProduct,
                'billing_cycle' => (string) ($item['billing_cycle'] ?? 'monthly'),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    public function validateCheckoutRequest(Request $request, array $cart): void
    {
        $bundled = $this->bundledContainerItems($cart);
        if ($bundled === []) {
            return;
        }

        $rules = [];
        $messages = [];

        foreach ($bundled as $entry) {
            $key = $entry['key'];
            $rules["bundle_primary_domain.{$key}"] = ['required', 'string', 'max:253'];
            $messages["bundle_primary_domain.{$key}.required"] = 'Enter the domain for your application and bundled email.';
        }

        $validated = $request->validate($rules, $messages);

        foreach ($bundled as $entry) {
            $key = $entry['key'];
            $raw = (string) ($validated['bundle_primary_domain'][$key] ?? '');
            try {
                $this->domainValidator->assertValid($raw);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    "bundle_primary_domain.{$key}" => $e->getMessage(),
                ]);
            }
        }
    }

    public function primaryDomainForCartKey(Request $request, string $cartKey): string
    {
        $raw = (string) $request->input("bundle_primary_domain.{$cartKey}", '');

        return $this->domainValidator->assertValid($raw);
    }

    /**
     * Amount to add to checkout subtotal for bundled email lines included on the invoice.
     *
     * @param  array<string, mixed>  $cart
     */
    public function estimateInvoiceAddonTotal(array $cart): float
    {
        $total = 0.0;

        foreach ($this->bundledContainerItems($cart) as $entry) {
            /** @var Product $containerProduct */
            $containerProduct = $entry['product'];
            if (! $containerProduct->bundle_email_include_in_invoice) {
                continue;
            }

            $cycle = $this->resolveBillingCycle($containerProduct, $entry['billing_cycle']);
            $total += $this->priceForCycle($entry['email_product'], $cycle);
        }

        return round($total, 2);
    }

    /**
     * Create the bundled email service (and optional invoice line) for a container service.
     *
     * @param  array<string, mixed>  $containerCartItem
     */
    public function attachToContainerService(
        Request $request,
        string $cartKey,
        User $user,
        Product $containerProduct,
        Service $containerService,
        Invoice $invoice,
        Order $order,
        array $containerCartItem,
    ): ?Service {
        $containerProduct->loadMissing('bundledEmailProduct');

        if (! $containerProduct->hasEmailBundle()) {
            return null;
        }

        $emailProduct = $containerProduct->bundledEmailProduct;
        if (! $emailProduct || $emailProduct->type !== 'email_hosting') {
            return null;
        }

        $fqdn = $this->primaryDomainForCartKey($request, $cartKey);
        $billingCycle = $this->resolveBillingCycle($containerProduct, (string) ($containerCartItem['billing_cycle'] ?? 'monthly'));
        $unitPrice = $this->priceForCycle($emailProduct, $billingCycle);
        $delayMonths = max(0, (int) ($containerProduct->bundle_email_billing_delay_months ?? 0));
        $cycleMonths = $this->billingCycleMonths($billingCycle);
        $limits = $this->mailcow->limitsForProduct($emailProduct);
        $mailNode = $this->mailcow->resolveNode();

        $serviceMeta = [
            'mailcow_domain' => $fqdn,
            'domain' => $fqdn,
            'email_domain_mode' => 'bundled_with_container',
            'mailbox_limit' => $limits['mailboxes'],
            'alias_limit' => $limits['aliases'],
            'mailbox_quota_mb' => $limits['mailbox_quota_mb'],
            'quota_mb' => $limits['quota_mb'],
            'msgs_per_day' => $limits['msgs_per_day'],
            'bundled_from_service_id' => $containerService->id,
            'bundled_from_product_id' => $containerProduct->id,
            'bundle_include_in_invoice' => (bool) $containerProduct->bundle_email_include_in_invoice,
        ];

        $emailService = Service::create([
            'user_id' => $user->id,
            'product_id' => $emailProduct->id,
            'order_item_id' => $containerService->order_item_id,
            'invoice_id' => $invoice->id,
            'reseller_id' => $containerService->reseller_id,
            'name' => $emailProduct->name.' ('.$fqdn.')',
            'status' => 'pending',
            'billing_cycle' => $billingCycle,
            'custom_price' => $unitPrice,
            'next_due_date' => now()->addMonths($delayMonths + $cycleMonths),
            'provisioning_driver_key' => $emailProduct->provisioning_driver_key ?: 'mailcow',
            'node_id' => $mailNode?->id,
            'service_meta' => $serviceMeta,
        ]);

        $meta = $containerService->service_meta ?? [];
        $meta['primary_domain'] = $fqdn;
        $meta['bundled_email_service_id'] = $emailService->id;
        $containerService->update(['service_meta' => $meta]);

        if ($containerProduct->bundle_email_include_in_invoice) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $emailService->id,
                'product_id' => $emailProduct->id,
                'description' => $emailProduct->name.' (bundled with '.$containerProduct->name.')',
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'amount' => $unitPrice,
            ]);
        }

        return $emailService;
    }

    public function resolveBillingCycle(Product $containerProduct, string $containerCycle): string
    {
        $override = $containerProduct->bundle_email_billing_cycle;

        if (in_array($override, ['monthly', 'annual'], true)) {
            return $override;
        }

        return in_array($containerCycle, ['monthly', 'quarterly', 'semi-annual', 'annual'], true)
            ? ($containerCycle === 'annual' ? 'annual' : (str_starts_with($containerCycle, 'month') ? 'monthly' : $containerCycle))
            : 'monthly';
    }

    public function priceForCycle(Product $emailProduct, string $billingCycle): float
    {
        if ($billingCycle === 'annual') {
            return (float) ($emailProduct->yearly_price ?? $emailProduct->monthly_price ?? $emailProduct->price ?? 0);
        }

        return (float) ($emailProduct->monthly_price ?? $emailProduct->price ?? 0);
    }

    public function billingCycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annual' => 6,
            'annual' => 12,
            default => 1,
        };
    }
}

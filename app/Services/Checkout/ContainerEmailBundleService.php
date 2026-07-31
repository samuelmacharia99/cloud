<?php

namespace App\Services\Checkout;

use App\Enums\BillingMode;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\UsageBillingProfileService;
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
        private UsageBillingProfileService $usageProfile,
    ) {}

    /**
     * @param  array<string, mixed>  $cart
     * @return list<array{key: string, product: Product, email_product: Product, usage_billing: bool, billing_cycle: string}>
     */
    public function bundledContainerItems(array $cart): array
    {
        $items = [];

        foreach ($cart as $key => $item) {
            if (($item['type'] ?? null) !== 'product') {
                continue;
            }

            $product = Product::query()->with('bundledEmailProduct')->find($item['product_id'] ?? null);
            if (! $product || $product->type !== 'container_hosting') {
                continue;
            }

            $usageBilling = ! empty($item['usage_billing']);
            $emailProduct = null;

            if ($usageBilling && $this->usageProfile->autoIncludeEmail()) {
                $emailProduct = $this->usageProfile->resolveEmailProduct();
            } elseif ($product->hasEmailBundle()) {
                $emailProduct = $product->bundledEmailProduct;
            }

            if (! $emailProduct || $emailProduct->type !== 'email_hosting' || ! $emailProduct->is_active) {
                continue;
            }

            $items[] = [
                'key' => (string) $key,
                'product' => $product,
                'email_product' => $emailProduct,
                'billing_cycle' => (string) ($item['billing_cycle'] ?? 'monthly'),
                'usage_billing' => $usageBilling,
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
            // Domain may already be on the cart item from simplified deploy.
            $cartDomain = (string) (session('cart.'.$key.'.primary_domain') ?? '');
            if ($cartDomain !== '' && ! $request->filled("bundle_primary_domain.{$key}")) {
                $request->merge([
                    "bundle_primary_domain" => array_merge(
                        (array) $request->input('bundle_primary_domain', []),
                        [$key => $cartDomain]
                    ),
                ]);
            }

            $rules["bundle_primary_domain.{$key}"] = ['required', 'string', 'max:253'];
            $messages["bundle_primary_domain.{$key}.required"] = 'Enter the domain for your application and email.';
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
        if ($raw === '') {
            $raw = (string) (session("cart.{$cartKey}.primary_domain") ?? '');
        }

        return $this->domainValidator->assertValid($raw);
    }

    /**
     * @param  array<string, mixed>  $cart
     */
    public function estimateInvoiceAddonTotal(array $cart): float
    {
        $total = 0.0;

        foreach ($this->bundledContainerItems($cart) as $entry) {
            /** @var Product $containerProduct */
            $containerProduct = $entry['product'];

            // Usage-mode email is included in the app floor — do not add a separate line at estimate.
            if (! empty($entry['usage_billing'])) {
                continue;
            }

            if (! $containerProduct->bundle_email_include_in_invoice) {
                continue;
            }

            $cycle = $this->resolveBillingCycle($containerProduct, $entry['billing_cycle']);
            $total += $this->priceForCycle($entry['email_product'], $cycle);
        }

        return round($total, 2);
    }

    /**
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

        $usageBilling = ! empty($containerCartItem['usage_billing'])
            || $this->usageProfile->serviceUsesUsageBilling($containerService);

        $emailProduct = null;
        if ($usageBilling && $this->usageProfile->autoIncludeEmail()) {
            $emailProduct = $this->usageProfile->resolveEmailProduct();
        } elseif ($containerProduct->hasEmailBundle()) {
            $emailProduct = $containerProduct->bundledEmailProduct;
        }

        if (! $emailProduct || $emailProduct->type !== 'email_hosting') {
            return null;
        }

        $fqdn = $this->primaryDomainForCartKey($request, $cartKey);
        $billingCycle = $usageBilling
            ? 'monthly'
            : $this->resolveBillingCycle($containerProduct, (string) ($containerCartItem['billing_cycle'] ?? 'monthly'));

        $included = $usageBilling
            ? $this->usageProfile->includedLimits()
            : $this->mailcow->limitsForProduct($emailProduct);

        $unitPrice = $usageBilling ? 0.0 : $this->priceForCycle($emailProduct, $billingCycle);
        $delayMonths = $usageBilling ? 0 : max(0, (int) ($containerProduct->bundle_email_billing_delay_months ?? 0));
        $cycleMonths = $this->billingCycleMonths($billingCycle);
        $mailNode = $this->mailcow->resolveNode();

        $serviceMeta = [
            'mailcow_domain' => $fqdn,
            'domain' => $fqdn,
            'email_domain_mode' => $usageBilling ? 'bundled_with_usage_hosting' : 'bundled_with_container',
            'mailbox_limit' => (int) ($included['mailboxes'] ?? 5),
            'alias_limit' => (int) ($included['aliases'] ?? 10),
            'mailbox_quota_mb' => (int) ($included['mailbox_quota_mb'] ?? 5120),
            'quota_mb' => (int) ($included['quota_mb'] ?? 25600),
            'msgs_per_day' => (int) ($included['msgs_per_day'] ?? 500),
            'bundled_from_service_id' => $containerService->id,
            'bundled_from_product_id' => $containerProduct->id,
            'bundle_include_in_invoice' => $usageBilling ? false : (bool) $containerProduct->bundle_email_include_in_invoice,
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
            'billing_mode' => $usageBilling ? BillingMode::Usage : BillingMode::Package,
            'custom_price' => $unitPrice,
            'included_limits' => $usageBilling ? $this->usageProfile->includedLimits() : null,
            'usage_rates' => $usageBilling ? $this->usageProfile->usageRates() : null,
            'next_due_date' => now()->addMonths($delayMonths + $cycleMonths),
            'provisioning_driver_key' => $emailProduct->provisioning_driver_key ?: 'mailcow',
            'node_id' => $mailNode?->id,
            'service_meta' => $serviceMeta,
        ]);

        $meta = $containerService->service_meta ?? [];
        $meta['primary_domain'] = $fqdn;
        $meta['bundled_email_service_id'] = $emailService->id;
        $containerService->update(['service_meta' => $meta]);

        if (! $usageBilling && $containerProduct->bundle_email_include_in_invoice) {
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

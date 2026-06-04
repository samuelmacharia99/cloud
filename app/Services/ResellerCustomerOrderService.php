<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ResellerDomainOrder;
use App\Models\ResellerDomainPricing;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResellerCustomerOrderService
{
    public function __construct(
        private ResellerScopeService $scope,
        private ResellerCustomerBillingService $billing,
        private ResellerEnforcementService $enforcement,
        private DomainPushService $domainPush,
        private ResellerHostingSetupService $hostingSetup,
    ) {}

    /**
     * Create a pending service and customer invoice from whitelabel catalog (full order flow).
     *
     * @return array{service: Service, invoice: Invoice}
     */
    public function orderHostingFromCatalog(
        User $reseller,
        User $customer,
        ResellerProduct $catalogProduct,
        string $billingCycle,
        array $options = [],
    ): array {
        $this->billing->ensureManagedCustomer($reseller, $customer);

        if ($reseller->isAtServiceLimit()) {
            throw new \InvalidArgumentException('You have reached your package service limit. Upgrade to add more services.');
        }

        $adminProduct = $catalogProduct->adminProduct;
        if (! $adminProduct instanceof Product) {
            throw new \InvalidArgumentException('This catalog item is custom and cannot be auto-provisioned. Create a manual invoice instead.');
        }

        $retailPrice = $catalogProduct->priceForBillingCycle($billingCycle);
        $description = "{$catalogProduct->name} ({$billingCycle})";
        $status = $options['invoice_status'] ?? 'unpaid';

        return DB::transaction(function () use (
            $reseller,
            $customer,
            $catalogProduct,
            $adminProduct,
            $billingCycle,
            $retailPrice,
            $description,
            $status,
            $options,
        ) {
            $service = $this->createPendingHostingService(
                $reseller,
                $customer,
                $catalogProduct,
                $adminProduct,
                $billingCycle,
                $retailPrice,
                $options,
            );

            $invoice = $this->billing->createCustomerInvoice($reseller, $customer, [
                'status' => $status,
                'due_date' => $options['due_date'] ?? null,
                'notes' => $options['invoice_notes'] ?? null,
                'tax_rate' => $options['tax_rate'] ?? 0,
                'items' => [[
                    'description' => $description,
                    'quantity' => 1,
                    'unit_price' => $retailPrice,
                    'product_id' => $adminProduct->id,
                    'service_id' => $service->id,
                ]],
            ]);

            $service->update(['invoice_id' => $invoice->id]);

            $lineItem = $invoice->items()->first();
            if ($lineItem) {
                $lineItem->update(['service_id' => $service->id]);
            }

            return ['service' => $service->fresh(), 'invoice' => $invoice->fresh(['items'])];
        });
    }

    /**
     * Create and provision hosting for a customer with no invoice (complimentary / internal).
     *
     * @return array{service: Service, provisioned: bool, skipped: bool}
     */
    public function provisionHostingForCustomerWithoutBilling(
        User $reseller,
        User $customer,
        ResellerProduct $catalogProduct,
        string $billingCycle,
        array $options = [],
    ): array {
        $this->billing->ensureManagedCustomer($reseller, $customer);

        if ($reseller->isAtServiceLimit()) {
            throw new \InvalidArgumentException('You have reached your package service limit. Upgrade to add more services.');
        }

        $adminProduct = $catalogProduct->adminProduct;
        if (! $adminProduct instanceof Product) {
            throw new \InvalidArgumentException('This catalog item cannot be auto-provisioned. Bill the customer or choose a linked platform product.');
        }

        return DB::transaction(function () use (
            $reseller,
            $customer,
            $catalogProduct,
            $adminProduct,
            $billingCycle,
            $options,
        ) {
            $service = $this->createPendingHostingService(
                $reseller,
                $customer,
                $catalogProduct,
                $adminProduct,
                $billingCycle,
                0.0,
                $options,
            );

            $provisioning = app(InvoiceProvisioningService::class);

            if (! $provisioning->shouldAutoProvision()) {
                return [
                    'service' => $service->fresh(),
                    'provisioned' => false,
                    'skipped' => true,
                ];
            }

            try {
                $this->enforcement->assertCanProvision($service);
                $service->update(['status' => 'provisioning']);
                app(ProvisioningService::class)->provision($service->fresh());

                return [
                    'service' => $service->fresh(),
                    'provisioned' => true,
                    'skipped' => false,
                ];
            } catch (\Throwable $e) {
                Log::error('Complimentary hosting provision failed', [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);

                throw new \InvalidArgumentException('Service was created but provisioning failed: '.$e->getMessage());
            }
        });
    }

    private function createPendingHostingService(
        User $reseller,
        User $customer,
        ResellerProduct $catalogProduct,
        Product $adminProduct,
        string $billingCycle,
        float $retailPrice,
        array $options = [],
    ): Service {
        $attributes = [
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'product_id' => $adminProduct->id,
            'name' => $options['service_name'] ?? $catalogProduct->name,
            'status' => 'pending',
            'billing_cycle' => $billingCycle,
            'custom_price' => $retailPrice,
            'next_due_date' => now()->addMonths($this->billingCycleMonths($billingCycle)),
            'provisioning_driver_key' => $adminProduct->provisioning_driver_key,
            'notes' => $options['notes'] ?? null,
        ];

        if ($this->isProvisionableHostingProduct($adminProduct)) {
            $context = $this->hostingSetup->buildProvisioningContext(
                $reseller,
                $customer,
                $adminProduct,
                $options['primary_domain'] ?? null,
            );

            $attributes['provisioning_driver_key'] = $context['provisioning_driver_key']
                ?? $attributes['provisioning_driver_key'];

            if (! empty($context['node_id'])) {
                $attributes['node_id'] = $context['node_id'];
            }

            if (! empty($context['service_meta'])) {
                $attributes['service_meta'] = $context['service_meta'];
            }
        }

        return Service::create($attributes);
    }

    private function isProvisionableHostingProduct(Product $adminProduct): bool
    {
        $driver = $adminProduct->provisioning_driver_key;

        return in_array($driver, ['directadmin', 'container'], true)
            || ($adminProduct->type === 'shared_hosting' && $adminProduct->direct_admin_package_id);
    }

    /**
     * Register a domain for a managed customer (creates customer invoice at retail; wholesale debited on push).
     *
     * @return array{invoice: Invoice, order: ResellerDomainOrder, domain: Domain}
     */
    public function orderDomainForCustomer(
        User $reseller,
        User $customer,
        string $domainName,
        DomainExtension $extension,
        int $years,
        ?string $expiresAt = null,
    ): array {
        $this->billing->ensureManagedCustomer($reseller, $customer);

        return DB::transaction(function () use ($reseller, $customer, $domainName, $extension, $years, $expiresAt) {
            $prepared = $this->prepareDomainRegistration($reseller, $customer, $domainName, $extension, $years, expiresAt: $expiresAt);

            $invoice = $this->billing->createCustomerInvoice($reseller, $customer, [
                'status' => InvoiceStatus::Unpaid->value,
                'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 7)),
                'notes' => 'Whitelabel domain registration',
                'tax_rate' => 0,
                'items' => [$prepared['line_item']],
            ]);

            $this->linkDomainOrderToInvoice($prepared['order'], $prepared['domain'], $invoice);

            return [
                'invoice' => $invoice->fresh(['items']),
                'order' => $prepared['order']->fresh(),
                'domain' => $prepared['domain']->fresh(),
            ];
        });
    }

    /**
     * Register a domain for a customer without billing them (retail 0). Pushes to registrar when wallet allows.
     *
     * @return array{domain: Domain, order: ResellerDomainOrder, pushed: bool}
     */
    public function provisionDomainForCustomerWithoutBilling(
        User $reseller,
        User $customer,
        string $domainName,
        DomainExtension $extension,
        int $years,
        ?string $expiresAt = null,
    ): array {
        $this->billing->ensureManagedCustomer($reseller, $customer);

        return DB::transaction(function () use ($reseller, $customer, $domainName, $extension, $years, $expiresAt) {
            $prepared = $this->prepareDomainRegistration(
                $reseller,
                $customer,
                $domainName,
                $extension,
                $years,
                retailAmount: 0.0,
                expiresAt: $expiresAt,
            );

            $order = $prepared['order'];
            $order->update([
                'retail_amount' => 0,
                'customer_invoice_id' => null,
            ]);

            $pushed = $this->domainPush->pushOrQueue($order->fresh(['reseller', 'customer']));

            return [
                'domain' => $prepared['domain']->fresh(),
                'order' => $order->fresh(),
                'pushed' => $pushed,
            ];
        });
    }

    /**
     * Checkout multiple domain registrations for one managed customer (single invoice).
     *
     * @param  array<int, array<string, mixed>>  $cartItems
     */
    public function checkoutDomainCartForCustomer(User $reseller, User $customer, array $cartItems): Invoice
    {
        $this->billing->ensureManagedCustomer($reseller, $customer);

        $registrations = array_values(array_filter(
            $cartItems,
            fn ($item) => ($item['type'] ?? 'domain') === 'domain',
        ));

        if ($registrations === []) {
            throw new \InvalidArgumentException('Your cart has no domain registrations for this customer.');
        }

        return DB::transaction(function () use ($reseller, $customer, $registrations) {
            $lineItems = [];

            foreach ($registrations as $item) {
                $extension = DomainExtension::query()
                    ->where('extension', $item['extension'])
                    ->where('enabled', true)
                    ->first();

                if (! $extension) {
                    throw new \InvalidArgumentException("Extension {$item['extension']} is not available.");
                }

                $prepared = $this->prepareDomainRegistration(
                    $reseller,
                    $customer,
                    (string) $item['domain'],
                    $extension,
                    (int) $item['years'],
                );

                $lineItems[] = $prepared['line_item'];
            }

            $invoice = $this->billing->createCustomerInvoice($reseller, $customer, [
                'status' => InvoiceStatus::Unpaid->value,
                'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 7)),
                'notes' => 'Whitelabel domain cart checkout',
                'tax_rate' => 0,
                'items' => $lineItems,
            ]);

            foreach ($invoice->items as $item) {
                $orderId = $item->custom_options['domain_order_id'] ?? null;
                if (! $orderId) {
                    continue;
                }

                $order = ResellerDomainOrder::find($orderId);
                if ($order) {
                    $order->update(['customer_invoice_id' => $invoice->id]);
                    $item->update([
                        'product_type' => 'Domain',
                        'domain_id' => $order->domain_id,
                    ]);
                }
            }

            return $invoice->fresh(['items', 'user']);
        });
    }

    /**
     * @return array{domain: Domain, order: ResellerDomainOrder, line_item: array<string, mixed>}
     */
    private function prepareDomainRegistration(
        User $reseller,
        User $customer,
        string $domainName,
        DomainExtension $extension,
        int $years,
        ?float $retailAmount = null,
        ?string $expiresAt = null,
    ): array {
        $wholesale = $extension->pricing()
            ->where('tier', 'wholesale')
            ->where('period_years', $years)
            ->first();

        if (! $wholesale) {
            throw new \InvalidArgumentException("No wholesale pricing for {$extension->extension} ({$years} years).");
        }

        $wholesaleAmount = (float) $wholesale->price * $years;
        $retailAmount ??= $this->retailAmountForExtension($reseller, $extension, $years, $wholesaleAmount);
        $domainName = strtolower($domainName);
        $domainExpiresAt = $this->resolveDomainExpiresAt($expiresAt, $years);

        $domain = Domain::create([
            'user_id' => $customer->id,
            'reseller_id' => $reseller->id,
            'name' => $domainName,
            'extension' => $extension->extension,
            'status' => 'pending',
            'type' => 'registration',
            'auto_renew' => false,
            'expires_at' => $domainExpiresAt,
        ]);

        $order = ResellerDomainOrder::create([
            'reseller_id' => $reseller->id,
            'customer_id' => $customer->id,
            'domain_id' => $domain->id,
            'domain_name' => $domainName,
            'extension' => $extension->extension,
            'years' => $years,
            'wholesale_amount' => $wholesaleAmount,
            'retail_amount' => $retailAmount,
            'status' => 'queued',
            'queued_at' => now(),
        ]);

        return [
            'domain' => $domain,
            'order' => $order,
            'line_item' => [
                'description' => $domainName.$extension->extension." ({$years} year".($years > 1 ? 's' : '').')',
                'quantity' => 1,
                'unit_price' => $retailAmount,
                'product_id' => null,
                'product_type' => 'Domain',
                'domain_id' => $domain->id,
                'custom_options' => ['domain_order_id' => $order->id],
            ],
        ];
    }

    private function resolveDomainExpiresAt(?string $expiresAt, int $years): ?Carbon
    {
        if (blank($expiresAt)) {
            return now()->addYears($years);
        }

        return Carbon::parse($expiresAt)->startOfDay();
    }

    private function linkDomainOrderToInvoice(ResellerDomainOrder $order, Domain $domain, Invoice $invoice): void
    {
        $order->update(['customer_invoice_id' => $invoice->id]);

        $item = $invoice->items()->first();
        if ($item) {
            $item->update([
                'product_type' => 'Domain',
                'domain_id' => $domain->id,
                'custom_options' => ['domain_order_id' => $order->id],
            ]);
        }
    }

    public function retailAmountForExtension(
        User $reseller,
        DomainExtension $extension,
        int $years,
        ?float $wholesaleFallback = null,
    ): float {
        $wholesale = $extension->pricing()
            ->where('tier', 'wholesale')
            ->where('period_years', $years)
            ->first();

        $wholesaleAmount = $wholesale
            ? (float) $wholesale->price * $years
            : ($wholesaleFallback ?? 0.0);

        $retailPricing = ResellerDomainPricing::query()
            ->where('reseller_id', $reseller->id)
            ->where('domain_extension_id', $extension->id)
            ->where('period_years', $years)
            ->where('enabled', true)
            ->first();

        return $retailPricing
            ? (float) $retailPricing->retail_price
            : $wholesaleAmount;
    }

    private function billingCycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'quarterly' => 3,
            'semi-annual' => 6,
            'annual' => 12,
            default => 1,
        };
    }
}

<?php

namespace App\Services\Customer;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\SSH\SSHService;
use App\Services\TaxService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-serve container plan resize (same template family, higher/lower resource limits).
 */
class CustomerContainerPlanChangeService
{
    public function __construct(
        private ContainerDeploymentService $deployments,
    ) {}

    public function emptyReason(Service $service, User $customer): ?string
    {
        if ($service->user_id !== $customer->id) {
            return 'You do not have access to change this service plan.';
        }

        $service->loadMissing('product.containerTemplate', 'containerDeployment');

        if (! $service->isContainerHosting()) {
            return 'Only app hosting (container) services can use this plan change.';
        }

        if (! $service->product?->containerTemplate) {
            return 'This service has no container template.';
        }

        if ($this->optionsForService($service)->isEmpty()) {
            return 'No alternative app hosting plans are available for this stack right now.';
        }

        return null;
    }

    /**
     * @return Collection<int, array{product: Product, name: string, change_type: string, display_price: float, cpu: float, memory_mb: int, disk_gb: float}>
     */
    public function optionsForService(Service $service): Collection
    {
        $service->loadMissing('product.containerTemplate');
        $current = $service->product;
        $templateId = $current?->container_template_id;

        if (! $current || $current->type !== 'container_hosting' || ! $templateId) {
            return collect();
        }

        $currentLimits = $current->getIncludedContainerLimits($current->containerTemplate, $service->containerDeployment);
        $currentScore = $this->resourceScore($currentLimits);

        return Product::query()
            ->where('type', 'container_hosting')
            ->where('is_active', true)
            ->where('container_template_id', $templateId)
            ->where('id', '!=', $current->id)
            ->orderBy('price')
            ->get()
            ->map(function (Product $product) use ($currentScore, $service) {
                $limits = $product->getIncludedContainerLimits($product->containerTemplate, $service->containerDeployment);
                $score = $this->resourceScore($limits);
                $changeType = $score > $currentScore ? 'upgrade' : ($score < $currentScore ? 'downgrade' : 'lateral');

                return [
                    'product' => $product,
                    'name' => $product->name,
                    'change_type' => $changeType,
                    'display_price' => (float) $product->price,
                    'cpu' => $limits['cpu'],
                    'memory_mb' => $limits['memory_mb'],
                    'disk_gb' => $limits['disk_gb'],
                ];
            })
            ->values();
    }

    public function createChangeInvoice(
        Service $service,
        User $customer,
        Product $target,
        string $billingCycle
    ): Invoice {
        if ($service->user_id !== $customer->id) {
            throw new \InvalidArgumentException('You can only change your own services.');
        }

        $options = $this->optionsForService($service);
        $match = $options->first(fn (array $o) => $o['product']->id === $target->id);
        if (! $match) {
            throw new \InvalidArgumentException('That plan is not available for this service.');
        }

        $currentPrice = (float) ($service->custom_price ?? $service->product->price ?? 0);
        $targetPrice = (float) $target->price;
        $cycleFactor = match ($billingCycle) {
            'quarterly' => 3,
            'semi-annual' => 6,
            'annual' => 12,
            default => 1,
        };
        $priceDiff = max(0, ($targetPrice - $currentPrice) * $cycleFactor);
        // Simple mid-cycle estimate: charge half a cycle of the difference for upgrades; free for down/lateral
        $prorated = $match['change_type'] === 'upgrade'
            ? round($priceDiff * 0.5, 2)
            : 0.0;

        $tax = TaxService::calculateForUser($prorated, $customer);

        return DB::transaction(function () use ($service, $customer, $target, $billingCycle, $prorated, $tax, $match) {
            $invoice = Invoice::create([
                'user_id' => $customer->id,
                'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
                'status' => $prorated <= 0 ? 'paid' : 'unpaid',
                'paid_date' => $prorated <= 0 ? now() : null,
                'due_date' => now()->addDays(3),
                'subtotal' => $tax['subtotal'],
                'tax' => $tax['tax'],
                'total' => $tax['total'],
                'notes' => sprintf(
                    'Container plan %s: %s → %s (%s) [container_plan_change:1] [service:%d] [product:%d]',
                    $match['change_type'],
                    $service->product->name,
                    $target->name,
                    $billingCycle,
                    $service->id,
                    $target->id
                ),
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'service_id' => $service->id,
                'product_id' => $target->id,
                'description' => 'App hosting plan '.$match['change_type'].' to '.$target->name,
                'quantity' => 1,
                'unit_price' => $prorated,
                'amount' => $prorated,
            ]);

            if ($prorated <= 0) {
                $this->applyPlanChange($service, $target, $billingCycle);
            }

            return $invoice->fresh(['items']);
        });
    }

    public function applyPlanChange(Service $service, Product $target, string $billingCycle): void
    {
        $service->loadMissing('product.containerTemplate', 'containerDeployment.node');
        $deployment = $service->containerDeployment;

        if (! $deployment?->node) {
            throw new \InvalidArgumentException('Container is not deployed.');
        }

        $limits = $target->getIncludedContainerLimits($target->containerTemplate, $deployment);

        $service->update([
            'product_id' => $target->id,
            'billing_cycle' => $billingCycle,
            'custom_price' => null,
        ]);

        $deployment->update([
            'cpu_limit' => $limits['cpu'],
            'memory_limit_mb' => $limits['memory_mb'],
        ]);

        $ssh = SSHService::forNode($deployment->node);
        try {
            $this->deployments->applyEnvironmentVariables($service->fresh(), $deployment->fresh());
        } finally {
            $ssh->disconnect();
        }
    }

    /**
     * Apply plan change when a container plan-change invoice is paid.
     */
    public function applyFromPaidInvoice(Invoice $invoice): void
    {
        if (! str_contains($invoice->notes ?? '', '[container_plan_change:1]')) {
            return;
        }

        if (! preg_match('/\[service:(\d+)\].*\[product:(\d+)\]/s', $invoice->notes ?? '', $m)
            && ! preg_match('/\[service:(\d+)\]/', $invoice->notes ?? '', $m)) {
            return;
        }

        $serviceId = (int) $m[1];
        $productId = isset($m[2]) ? (int) $m[2] : null;
        if (! $productId && preg_match('/\[product:(\d+)\]/', $invoice->notes ?? '', $pm)) {
            $productId = (int) $pm[1];
        }

        $service = Service::with('product.containerTemplate', 'containerDeployment.node')->find($serviceId);
        $product = Product::with('containerTemplate')->find($productId);
        if (! $service || ! $product) {
            return;
        }

        $cycle = $service->billing_cycle ?? 'monthly';
        if (preg_match('/\((monthly|quarterly|semi-annual|annual)\)/', $invoice->notes ?? '', $cm)) {
            $cycle = $cm[1];
        }

        $this->applyPlanChange($service, $product, $cycle);
    }

    /**
     * @param  array{cpu: float, memory_mb: int, disk_gb: float}  $limits
     */
    private function resourceScore(array $limits): float
    {
        return ((float) $limits['cpu'] * 1000)
            + ((float) $limits['memory_mb'])
            + ((float) $limits['disk_gb'] * 10);
    }
}

<?php

namespace App\Services;

use App\Models\ContainerDeployment;
use App\Models\ContainerMetric;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\ProjectRecipeService;
use Illuminate\Support\Collection;

/**
 * Bills project-wide bandwidth transfer on the billing-anchor renewal invoice.
 * Does not modify per-container CPU/RAM/disk overage math.
 */
class ProjectBandwidthBillingService
{
    public function __construct(
        private ContainerOverageBillingService $containerOverage,
        private ProjectRecipeService $projectRecipes,
    ) {}

    public function addBandwidthItemsToInvoice(Invoice $invoice, Service $service): void
    {
        $service->loadMissing(['product', 'project.services.containerDeployment', 'containerDeployment']);

        $product = $service->product;
        if (! $product || $product->type !== 'container_hosting') {
            return;
        }

        if (! $product->bandwidth_overage_enabled) {
            return;
        }

        $rate = (float) ($product->bandwidth_overage_rate ?? 0);
        $includedGb = $this->includedBandwidthGb($product);

        if ($rate <= 0) {
            return;
        }

        // Only bill on the project billing anchor (or a standalone container service).
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        if ($this->projectRecipes->isProjectRecipeServiceMeta($meta)
            && ! $this->projectRecipes->isBillingAnchor($meta)
        ) {
            return;
        }

        $period = $this->containerOverage->resolveBillingPeriod($service);
        if ($period === null) {
            return;
        }

        ['from' => $from, 'to' => $to] = $period;
        $deployments = $this->deploymentsForBilling($service);

        if ($deployments->isEmpty()) {
            return;
        }

        $usedBytes = 0;
        foreach ($deployments as $deployment) {
            $usedBytes += ContainerMetric::transferBytesForPeriod($deployment, $from, $to);
        }

        $usedGb = $usedBytes / (1024 ** 3);
        $billableGb = max(0, $usedGb - $includedGb);

        if ($billableGb <= 0 || $rate <= 0) {
            return;
        }

        $this->appendOverageItem(
            $invoice,
            $service,
            $product,
            sprintf(
                'Project Bandwidth Overage — %s GB above %s GB included (used: %s GB, %d container%s) @ KES %s/GB',
                $this->formatQuantity($billableGb),
                $this->formatQuantity($includedGb),
                $this->formatQuantity($usedGb),
                $deployments->count(),
                $deployments->count() === 1 ? '' : 's',
                $this->formatRate($rate)
            ),
            $billableGb,
            $rate,
        );
    }

    public function includedBandwidthGb(Product $product): float
    {
        $limits = $product->resource_limits ?? [];

        if (isset($limits['bandwidth_gb']) && $limits['bandwidth_gb'] !== '' && $limits['bandwidth_gb'] !== null) {
            return max(0, (float) $limits['bandwidth_gb']);
        }

        return 0.0;
    }

    /**
     * @return Collection<int, ContainerDeployment>
     */
    private function deploymentsForBilling(Service $service): Collection
    {
        $project = $service->project;

        if ($project) {
            $project->loadMissing('services.containerDeployment');

            return $project->services
                ->map(fn (Service $member) => $member->containerDeployment)
                ->filter()
                ->values();
        }

        return collect([$service->containerDeployment])->filter()->values();
    }

    private function appendOverageItem(
        Invoice $invoice,
        Service $service,
        Product $product,
        string $description,
        float $quantity,
        float $unitPrice,
    ): void {
        $amount = round($quantity * $unitPrice, 2);
        $invoice->loadMissing('user');
        $breakdown = TaxService::calculateForUser($amount, $invoice->user);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
            'product_id' => $product->id,
            'product_type' => 'project_bandwidth_overage',
            'description' => $description,
            'quantity' => round($quantity, 4),
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ]);

        $invoice->increment('subtotal', $breakdown['subtotal']);
        $invoice->increment('tax', $breakdown['tax']);
        $invoice->increment('total', $breakdown['total']);
    }

    private function formatQuantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatRate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}

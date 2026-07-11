<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\Customer\CustomerContainerPlanChangeService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\ServiceEnforcementInsightService;
use App\Services\TaxService;
use Illuminate\Http\Request;

class ServiceUpgradeController extends Controller
{
    public function show(
        Service $service,
        CustomerHostingUpgradeService $upgrades,
        CustomerContainerPlanChangeService $containerPlans
    ) {
        $this->authorize('view', $service);

        $customer = auth()->user();

        if ($service->isContainerHosting()) {
            return $this->showContainer($service, $containerPlans, $customer);
        }

        $planOptions = $upgrades->planChangeOptions($service, $customer);
        $billingCycles = CustomerHostingUpgradeService::BILLING_CYCLES;
        $planEstimates = $planOptions->mapWithKeys(function (array $option) use ($upgrades, $service, $customer, $billingCycles) {
            $key = $option['product']->id.':'.($option['reseller_product_id'] ?? 0);
            $cycles = [];
            foreach ($billingCycles as $cycle) {
                $pricing = $upgrades->estimatePlanChangePricing($service, $customer, $option, $cycle);
                $taxBreakdown = TaxService::calculateForUser($pricing['prorated_subtotal'], $customer);
                $pricing['estimated_tax'] = $taxBreakdown['tax'];
                $pricing['estimated_total'] = $taxBreakdown['total'];
                $cycles[$cycle] = $pricing;
            }

            return [$key => $cycles];
        })->all();
        $insight = app(ServiceEnforcementInsightService::class)->forService($service->load('product.directAdminPackage'));
        $recommendedOption = $upgrades->recommendedPlanOption(
            $service,
            $customer,
            $insight['primary_metric'] ?? null,
        );

        $billingCycle = $service->billing_cycle ?? 'monthly';

        return view('customer.services.upgrade', [
            'service' => $service->load('product.directAdminPackage'),
            'planOptions' => $planOptions,
            'planChangeEmptyReason' => $upgrades->planChangeEmptyReason($service, $customer),
            'packageUsageInsight' => $insight,
            'recommendedOption' => $recommendedOption,
            'billingCycle' => $billingCycle,
            'billingCycles' => $billingCycles,
            'planEstimates' => $planEstimates,
            'upgrades' => $upgrades,
            'isContainerPlanChange' => false,
        ]);
    }

    public function store(
        Request $request,
        Service $service,
        CustomerHostingUpgradeService $upgrades,
        CustomerContainerPlanChangeService $containerPlans,
        InvoiceSettlementService $settlement
    ) {
        $this->authorize('view', $service);

        if ($service->isContainerHosting()) {
            return $this->storeContainer($request, $service, $containerPlans, $settlement);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'reseller_product_id' => 'nullable|exists:reseller_products,id',
            'billing_cycle' => 'required|in:'.implode(',', CustomerHostingUpgradeService::BILLING_CYCLES),
        ]);

        $targetProduct = Product::findOrFail($validated['product_id']);
        $resellerProductId = isset($validated['reseller_product_id'])
            ? (int) $validated['reseller_product_id']
            : null;

        try {
            $invoice = $upgrades->createUpgradeInvoice(
                $service,
                auth()->user(),
                $targetProduct,
                $resellerProductId,
                $validated['billing_cycle'],
            );
            $settlement->applyAvailableCredits($invoice);

            if ($invoice->fresh()->isFullyPaid() && $settlement->settleFromCredits($invoice->fresh())) {
                return redirect()->route('customer.services.show', $service)
                    ->with('success', 'Hosting plan changed successfully using account credit.');
            }

            $message = $invoice->total > 0
                ? 'Plan change invoice created. Complete payment to apply your new plan.'
                : 'Confirm the free plan change by completing the invoice (no charge).';

            return redirect()->route('customer.payment.select-method', $invoice)
                ->with('success', $message);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    private function showContainer(Service $service, CustomerContainerPlanChangeService $containerPlans, $customer)
    {
        $planOptions = $containerPlans->optionsForService($service)->map(function (array $option) {
            return [
                'product' => $option['product'],
                'name' => $option['name'],
                'change_type' => $option['change_type'],
                'reseller_product_id' => null,
                'display_price' => $option['display_price'],
                'cpu' => $option['cpu'],
                'memory_mb' => $option['memory_mb'],
                'disk_gb' => $option['disk_gb'],
            ];
        });

        $billingCycles = CustomerHostingUpgradeService::BILLING_CYCLES;
        $billingCycle = $service->billing_cycle ?? 'monthly';

        $planEstimates = $planOptions->mapWithKeys(function (array $option) use ($service, $customer, $billingCycles) {
            $key = $option['product']->id.':0';
            $cycles = [];
            $currentPrice = (float) ($service->custom_price ?? $service->product->price ?? 0);
            foreach ($billingCycles as $cycle) {
                $factor = match ($cycle) {
                    'quarterly' => 3,
                    'semi-annual' => 6,
                    'annual' => 12,
                    default => 1,
                };
                $diff = max(0, ((float) $option['display_price'] - $currentPrice) * $factor);
                $prorated = $option['change_type'] === 'upgrade' ? round($diff * 0.5, 2) : 0.0;
                $tax = TaxService::calculateForUser($prorated, $customer);
                $cycles[$cycle] = [
                    'prorated_subtotal' => $prorated,
                    'estimated_tax' => $tax['tax'],
                    'estimated_total' => $tax['total'],
                    'remaining_days' => null,
                    'period_end' => null,
                ];
            }

            return [$key => $cycles];
        })->all();

        return view('customer.services.upgrade', [
            'service' => $service->load('product.containerTemplate'),
            'planOptions' => $planOptions,
            'planChangeEmptyReason' => $containerPlans->emptyReason($service, $customer),
            'packageUsageInsight' => null,
            'recommendedOption' => $planOptions->firstWhere('change_type', 'upgrade'),
            'billingCycle' => $billingCycle,
            'billingCycles' => $billingCycles,
            'planEstimates' => $planEstimates,
            'upgrades' => null,
            'isContainerPlanChange' => true,
        ]);
    }

    private function storeContainer(
        Request $request,
        Service $service,
        CustomerContainerPlanChangeService $containerPlans,
        InvoiceSettlementService $settlement
    ) {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'billing_cycle' => 'required|in:'.implode(',', CustomerHostingUpgradeService::BILLING_CYCLES),
        ]);

        try {
            $target = Product::findOrFail($validated['product_id']);
            $invoice = $containerPlans->createChangeInvoice(
                $service,
                auth()->user(),
                $target,
                $validated['billing_cycle'],
            );
            $settlement->applyAvailableCredits($invoice);

            if ($invoice->fresh()->isFullyPaid() || (float) $invoice->total <= 0) {
                if ((float) $invoice->total > 0) {
                    $settlement->settleFromCredits($invoice->fresh());
                }

                return redirect()->route('customer.services.container.show', $service)
                    ->with('success', 'App hosting plan updated.');
            }

            return redirect()->route('customer.payment.select-method', $invoice)
                ->with('success', 'Plan change invoice created. Complete payment to apply the new limits.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

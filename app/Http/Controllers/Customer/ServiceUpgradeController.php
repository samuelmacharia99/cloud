<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\ServiceEnforcementInsightService;
use App\Services\TaxService;
use Illuminate\Http\Request;

class ServiceUpgradeController extends Controller
{
    public function show(Service $service, CustomerHostingUpgradeService $upgrades)
    {
        $this->authorize('view', $service);

        $customer = auth()->user();
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
        ]);
    }

    public function store(Request $request, Service $service, CustomerHostingUpgradeService $upgrades, InvoiceSettlementService $settlement)
    {
        $this->authorize('view', $service);

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
}

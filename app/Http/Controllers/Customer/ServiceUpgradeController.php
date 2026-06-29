<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\InvoiceSettlementService;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\ServiceEnforcementInsightService;
use Illuminate\Http\Request;

class ServiceUpgradeController extends Controller
{
    public function show(Service $service, CustomerHostingUpgradeService $upgrades)
    {
        $this->authorize('view', $service);

        $customer = auth()->user();
        $planOptions = $upgrades->planChangeOptions($service, $customer);
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
            'billingCycles' => CustomerHostingUpgradeService::BILLING_CYCLES,
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

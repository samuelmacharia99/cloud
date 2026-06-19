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

        $options = $upgrades->upgradeOptions($service, auth()->user());
        $insight = app(ServiceEnforcementInsightService::class)->forService($service->load('product.directAdminPackage'));
        $recommendedUpgrade = $upgrades->recommendedUpgrade(
            $service,
            auth()->user(),
            $insight['primary_metric'] ?? null,
        );

        return view('customer.services.upgrade', [
            'service' => $service->load('product.directAdminPackage'),
            'upgradeOptions' => $options,
            'packageUsageInsight' => $insight,
            'recommendedUpgrade' => $recommendedUpgrade,
        ]);
    }

    public function store(Request $request, Service $service, CustomerHostingUpgradeService $upgrades, InvoiceSettlementService $settlement)
    {
        $this->authorize('view', $service);

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $targetProduct = Product::findOrFail($validated['product_id']);

        try {
            $invoice = $upgrades->createUpgradeInvoice($service, auth()->user(), $targetProduct);
            $settlement->applyAvailableCredits($invoice);

            if ($invoice->fresh()->isFullyPaid() && $settlement->settleFromCredits($invoice->fresh())) {
                return redirect()->route('customer.services.show', $service)
                    ->with('success', 'Plan upgraded successfully using account credit.');
            }

            return redirect()->route('customer.payment.select-method', $invoice)
                ->with('success', 'Upgrade invoice created. Complete payment to activate your new plan.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

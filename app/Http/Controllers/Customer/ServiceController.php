<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Customer\CustomerHostingUpgradeService;
use App\Services\Customer\CustomerServiceCancellationService;
use App\Services\Customer\CustomerServiceRenewalService;
use App\Services\Hosting\ServicePackageUsageService;
use App\Services\ServiceEnforcementInsightService;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index()
    {
        $services = auth()->user()->services()
            ->with(['product', 'invoice'])
            ->whereNotIn('status', ['cancelled', 'terminated'])
            ->whereHas('product', function ($q) {
                $q->where('type', '!=', 'domain');
            })
            ->latest()
            ->get();

        return view('customer.services.index', compact('services'));
    }

    public function show(Service $service)
    {
        $this->authorize('view', $service);

        if ($invoice = $service->unpaidActivationInvoice()) {
            return redirect()->route('customer.payment.select-method', $invoice)
                ->with('info', 'Complete payment to activate this service.');
        }

        // Redirect container services to their dedicated dashboard
        if ($service->product?->type === 'container_hosting') {
            return redirect()->route('customer.services.container.show', $service);
        }

        $service->load(['product.directAdminPackage', 'invoice', 'node']);

        $packageUsageInsight = null;
        $recommendedUpgrade = null;

        if ($service->isSharedHosting()) {
            $usageService = app(ServicePackageUsageService::class);

            if ($usageService->snapshotFromMeta($service) === null) {
                $liveUsage = $usageService->fetchLiveUsage($service);
                if ($liveUsage !== null) {
                    $usageService->persistSnapshot($service, $liveUsage, $usageService->lastDashboard());
                    $service->refresh();
                }
            }

            $packageUsageInsight = app(ServiceEnforcementInsightService::class)->forService($service);
            $recommendedUpgrade = app(CustomerHostingUpgradeService::class)->recommendedUpgrade(
                $service,
                auth()->user(),
                $packageUsageInsight['primary_metric'] ?? null,
            );
        }

        return view('customer.services.show', compact('service', 'packageUsageInsight', 'recommendedUpgrade'));
    }

    public function cancel(Request $request, Service $service, CustomerServiceCancellationService $cancellation)
    {
        $this->authorize('view', $service);

        $request->validate([
            'reason' => 'required|string|min:10|max:1000',
        ]);

        try {
            $result = $cancellation->cancel($service, auth()->user(), $request->reason);

            return redirect()->route('customer.services.index')
                ->with($result['deprovisioned'] ? 'success' : 'warning', $result['message']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function renewForm(Service $service, CustomerServiceRenewalService $renewals)
    {
        $this->authorize('view', $service);
        abort_if(
            ! in_array($service->status->value, ['active', 'suspended']),
            422,
            'Only active or suspended services can be renewed.'
        );

        $service->load('product.directAdminPackage');

        if ($existingInvoice = $renewals->findOutstandingRenewalInvoice($service)) {
            return redirect()->route('customer.payment.select-method', $existingInvoice)
                ->with('info', 'You already have an outstanding renewal invoice. Complete the payment below to extend your service.');
        }

        $renewalOptions = $renewals->renewalOptions($service, auth()->user());

        return view('customer.services.renew', [
            'service' => $service,
            'renewalOptions' => $renewalOptions,
            'renewals' => $renewals,
        ]);
    }

    public function renew(Request $request, Service $service, CustomerServiceRenewalService $renewals)
    {
        $this->authorize('view', $service);
        abort_if(
            ! in_array($service->status->value, ['active', 'suspended']),
            422,
            'Only active or suspended services can be renewed.'
        );

        $service->load('product');

        if ($existingInvoice = $renewals->findOutstandingRenewalInvoice($service)) {
            return redirect()->route('customer.payment.select-method', $existingInvoice)
                ->with('info', 'You already have an outstanding renewal invoice. Complete the payment below to extend your service.');
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'reseller_product_id' => 'nullable|exists:reseller_products,id',
        ]);

        $resellerProductId = isset($validated['reseller_product_id'])
            ? (int) $validated['reseller_product_id']
            : null;

        try {
            $invoice = $renewals->createRenewalInvoice(
                $service,
                auth()->user(),
                (int) $validated['product_id'],
                $resellerProductId,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('customer.payment.select-method', $invoice)
            ->with('success', 'Renewal invoice created. Choose a payment method below to extend your service.');
    }
}

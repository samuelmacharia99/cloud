<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\Product;
use App\Models\Service;
use App\Services\Billing\ServiceRenewalPricingService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DirectAdminContainerMigrationController extends Controller
{
    public function show(
        Service $service,
        DirectAdminToContainerConvertService $convert,
        ServiceRenewalPricingService $renewalPricing,
    ): View|RedirectResponse {
        if (! $service->isSharedHosting()) {
            return redirect()->route('admin.services.show', $service)
                ->withErrors(['error' => 'Only DirectAdmin shared hosting can be converted to App Hosting.']);
        }

        try {
            $preflight = $convert->preflight($service);
            $preflightError = null;
        } catch (\Throwable $e) {
            $preflight = null;
            $preflightError = $e->getMessage();
        }

        $currentDue = $service->next_due_date;
        $currentCycle = $service->billing_cycle ?? 'monthly';

        $productEstimates = [];
        foreach ($preflight['wordpress_products'] ?? [] as $product) {
            $probe = Service::make([
                'billing_cycle' => $service->billing_cycle,
                'custom_price' => null,
                'product_id' => $product->id,
                'user_id' => $service->user_id,
                'reseller_id' => $service->reseller_id,
            ]);
            $probe->setRelation('product', $product);
            $probe->setRelation('user', $service->user);
            $productEstimates[$product->id] = $renewalPricing->unitPrice($probe);
        }

        return view('admin.services.migrate-to-container', [
            'service' => $service->load('product', 'node', 'user'),
            'preflight' => $preflight,
            'preflightError' => $preflightError,
            'currentDue' => $currentDue,
            'currentCycle' => $currentCycle,
            'productEstimates' => $productEstimates,
            'convertMeta' => $service->service_meta['da_convert'] ?? null,
        ]);
    }

    public function store(
        Request $request,
        Service $service,
        DirectAdminToContainerConvertService $convert,
    ): RedirectResponse {
        if (! $service->isSharedHosting()) {
            return back()->withErrors(['error' => 'Invalid source service.']);
        }

        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'database_name' => 'nullable|string|max:64',
            'acknowledge_extra_mailboxes' => 'nullable|boolean',
            'confirm_silent' => 'accepted',
        ]);

        $product = Product::with('containerTemplate')->findOrFail($validated['product_id']);

        try {
            $preflight = $convert->preflight($service);
            if ($preflight['email']['has_extra_mailboxes'] && ! $request->boolean('acknowledge_extra_mailboxes')) {
                return back()->withErrors([
                    'acknowledge_extra_mailboxes' => 'Acknowledge that extra mailboxes stay on DirectAdmin.',
                ])->withInput();
            }
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['da_convert'] = [
            'status' => 'queued',
            'mode' => 'convert_in_place',
            'queued_at' => now()->toIso8601String(),
            'target_product_id' => $product->id,
            'quiet' => true,
            'no_invoice' => true,
        ];
        $service->update(['service_meta' => $meta]);

        ConvertDirectAdminServiceToContainerJob::dispatch(
            $service->id,
            $product->id,
            $request->boolean('acknowledge_extra_mailboxes'),
            $validated['database_name'] ?? null,
        );

        return redirect()
            ->route('admin.services.show', $service)
            ->with('success', 'Silent convert queued: same service, no invoice, no customer notification. Refresh this page for progress (da_convert status).');
    }
}

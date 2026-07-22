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
                ->withErrors(['error' => 'Only DirectAdmin shared hosting can be converted to Application Hosting.']);
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
        foreach ($preflight['container_products'] ?? $preflight['wordpress_products'] ?? [] as $product) {
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

        // Always surface catalog even if inventory/preflight partially failed
        if ($preflight === null) {
            $pick = $convert->availableProductsForStack('wordpress');
            $containerProductsStandalone = $pick['products'];
            $productsAreFallback = $pick['fallback'];
            foreach ($containerProductsStandalone as $product) {
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
        } else {
            $containerProductsStandalone = $preflight['container_products'] ?? $preflight['wordpress_products'] ?? [];
            $productsAreFallback = (bool) ($preflight['products_are_fallback'] ?? false);
        }

        return view('admin.services.migrate-to-container', [
            'service' => $service->load('product.directAdminPackage', 'node', 'user'),
            'preflight' => $preflight,
            'preflightError' => $preflightError,
            'currentDue' => $currentDue,
            'currentCycle' => $currentCycle,
            'productEstimates' => $productEstimates,
            'containerProducts' => $containerProductsStandalone,
            'wordpressProducts' => $containerProductsStandalone,
            'productsAreFallback' => $productsAreFallback,
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
            'acknowledge_addon_sites' => 'nullable|boolean',
            'confirm_silent' => 'accepted',
        ]);

        $product = Product::with('containerTemplate')->findOrFail($validated['product_id']);

        $preflight = null;
        try {
            $preflight = $convert->preflight($service);
            if ($preflight['email']['has_extra_mailboxes'] && ! $request->boolean('acknowledge_extra_mailboxes')) {
                return back()->withErrors([
                    'acknowledge_extra_mailboxes' => 'Acknowledge that extra mailboxes stay on DirectAdmin.',
                ])->withInput();
            }
            if (($preflight['has_addon_sites'] ?? false) && ! $request->boolean('acknowledge_addon_sites')) {
                return back()->withErrors([
                    'acknowledge_addon_sites' => 'Acknowledge that addon/extra domains need separate Application Hosting services.',
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
            'stack' => $preflight['detected_stack'] ?? null,
            'quiet' => true,
            'no_invoice' => true,
        ];
        $service->update(['service_meta' => $meta]);

        ConvertDirectAdminServiceToContainerJob::dispatch(
            $service->id,
            $product->id,
            $request->boolean('acknowledge_extra_mailboxes'),
            $validated['database_name'] ?? null,
            $request->boolean('acknowledge_addon_sites'),
        )->afterResponse();

        return redirect()
            ->route('admin.services.show', $service)
            ->with('success', 'Silent convert queued: same service, no invoice, no customer notification. Refresh this page for progress (da_convert status). Ensure a queue worker is running if QUEUE_CONNECTION is not sync.');
    }

    public function revert(
        Service $service,
        DirectAdminToContainerConvertService $convert,
    ): RedirectResponse {
        try {
            $reverted = $convert->revertToDirectAdmin($service);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $containerName = $reverted->service_meta['da_convert']['manual_container_cleanup'] ?? null;
        $message = 'Service restored to DirectAdmin (same billing dates).';
        if (is_string($containerName) && $containerName !== '') {
            $message .= ' Delete the container manually on the node if it exists: /opt/talksasa/containers/'.$containerName;
        } else {
            $message .= ' Delete any leftover container on the node manually if one was created.';
        }

        return redirect()
            ->route('admin.services.show', $reverted)
            ->with('success', $message);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertDirectAdminServiceToContainerRequest;
use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Models\Service;
use App\Services\Billing\ServiceRenewalPricingService;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Illuminate\Http\RedirectResponse;
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

        $catalog = $convert->applicationHostingCatalog((string) ($preflight['detected_stack'] ?? ''));
        $containerProductsStandalone = is_array($preflight)
            ? ($preflight['container_products'] ?? $catalog['products']->all())
            : $catalog['products']->all();
        $recommendedProducts = is_array($preflight)
            ? ($preflight['recommended_products'] ?? $catalog['recommended']->all())
            : $catalog['recommended']->all();
        $productsAreFallback = is_array($preflight)
            ? (bool) ($preflight['products_are_fallback'] ?? $catalog['fallback'])
            : (bool) $catalog['fallback'];

        $productEstimates = [];
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

        return view('admin.services.migrate-to-container', [
            'service' => $service->load('product.directAdminPackage', 'node', 'user'),
            'preflight' => $preflight,
            'preflightError' => $preflightError,
            'currentDue' => $currentDue,
            'currentCycle' => $currentCycle,
            'productEstimates' => $productEstimates,
            'containerProducts' => $containerProductsStandalone,
            'recommendedProducts' => $recommendedProducts,
            'wordpressProducts' => $containerProductsStandalone,
            'productsAreFallback' => $productsAreFallback,
            'convertMeta' => $service->service_meta['da_convert'] ?? null,
        ]);
    }

    public function store(
        ConvertDirectAdminServiceToContainerRequest $request,
        Service $service,
        DirectAdminToContainerConvertService $convert,
    ): RedirectResponse {
        if (! $service->isSharedHosting()) {
            return back()->withErrors(['error' => 'Invalid source service.']);
        }

        $validated = $request->validated();
        $product = $request->applicationHostingProduct();

        $preflight = null;
        try {
            $preflight = $convert->preflight($service);
            if (($preflight['must_pull_mail'] ?? false) && ! $request->acknowledgedMailPull()) {
                return back()->withErrors([
                    'acknowledge_mail_pull' => 'Acknowledge that mail is pulled to Mailcow so DirectAdmin can be decommissioned.',
                ])->withInput();
            }
            if (($preflight['has_addon_sites'] ?? false) && ! $request->boolean('acknowledge_addon_sites')) {
                return back()->withErrors([
                    'acknowledge_addon_sites' => 'Acknowledge that extra live sites launch as sibling containers on this same Application Hosting package.',
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
            'target_product_name' => $product->name,
            'renewal_due_date' => optional($service->next_due_date)->toDateString(),
            'stack' => $preflight['detected_stack'] ?? null,
            'quiet' => true,
            'no_invoice' => true,
        ];
        $service->update(['service_meta' => $meta]);

        $emailProductId = $request->emailHostingProduct()?->id;
        if (! $emailProductId && ($preflight['must_pull_mail'] ?? false)) {
            $resolvedEmail = $convert->resolveEmailProductForConvert($product, null);
            if (! $resolvedEmail) {
                return back()->withErrors([
                    'email_product_id' => 'Select an Email Hosting plan, or pick an Application Hosting tier that includes bundled email.',
                ])->withInput();
            }
            $emailProductId = $resolvedEmail->id;
        }

        ConvertDirectAdminServiceToContainerJob::dispatch(
            $service->id,
            $product->id,
            $request->acknowledgedMailPull(),
            $validated['database_name'] ?? null,
            $request->boolean('acknowledge_addon_sites'),
            $emailProductId,
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

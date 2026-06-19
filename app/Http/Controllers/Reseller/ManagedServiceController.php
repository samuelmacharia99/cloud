<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ResellerManagedServiceUpdateService;
use App\Services\ResellerScopeService;
use App\Services\ServiceEnforcementInsightService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ManagedServiceController extends Controller
{
    public function __construct(
        private ResellerScopeService $scope,
    ) {}

    public function index(Request $request)
    {
        $query = $this->scope->managedServicesQuery(auth()->user())
            ->with(['user', 'product'])
            ->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $services = $query->paginate(20)->withQueryString();

        return view('reseller.services.index', compact('services'));
    }

    public function show(Service $service)
    {
        $this->ensureManaged($service);
        $service->load(['user', 'product', 'containerDeployment', 'invoice']);

        $canSuspend = $service->status === ServiceStatus::Active;
        $canUnsuspend = $service->status === ServiceStatus::Suspended;
        $canTerminate = ! in_array($service->status->value ?? $service->status, ['terminated', 'cancelled'], true);

        $managementLinks = $this->managementLinks($service);
        $enforcementInsight = app(ServiceEnforcementInsightService::class)->forService($service);

        return view('reseller.services.show', compact(
            'service',
            'canSuspend',
            'canUnsuspend',
            'canTerminate',
            'managementLinks',
            'enforcementInsight',
        ));
    }

    /**
     * @return array<string, string|null>
     */
    private function managementLinks(Service $service): array
    {
        $meta = $service->service_meta ?? [];
        $driver = $service->provisioning_driver_key ?? $service->product?->provisioning_driver_key;

        return [
            'driver' => $driver,
            'username' => $meta['username'] ?? $service->credentials['username'] ?? null,
            'domain' => $meta['domain'] ?? null,
            'ip_address' => $meta['ip_address'] ?? null,
            'panel_url' => $service->getDirectAdminPanelUrl(),
            'container_deployment' => $service->containerDeployment?->id,
        ];
    }

    public function suspend(Service $service, ProvisioningService $provisioning): RedirectResponse
    {
        $this->ensureManaged($service);

        try {
            $provisioning->suspend($service);

            return back()->with('success', 'Service suspended successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Suspension failed: '.$e->getMessage());
        }
    }

    public function unsuspend(Service $service, ProvisioningService $provisioning): RedirectResponse
    {
        $this->ensureManaged($service);

        try {
            $provisioning->unsuspend($service);

            return back()->with('success', 'Service unsuspended successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Unsuspension failed: '.$e->getMessage());
        }
    }

    public function terminate(Service $service, ProvisioningService $provisioning): RedirectResponse
    {
        $this->ensureManaged($service);

        if (in_array($service->status->value ?? $service->status, ['terminated', 'cancelled'], true)) {
            return back()->with('error', 'Service is already terminated or cancelled.');
        }

        try {
            $provisioning->terminate($service);

            return back()->with('success', 'Service terminated successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Termination failed: '.$e->getMessage());
        }
    }

    public function update(Request $request, Service $service, ResellerManagedServiceUpdateService $updater): RedirectResponse
    {
        $this->ensureManaged($service);

        $validated = $request->validate([
            'reseller_product_id' => 'required|exists:reseller_products,id',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'custom_price' => 'nullable|numeric|min:0',
            'next_due_date' => 'required|date',
            'commenced_at' => 'nullable|date',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'primary_domain' => 'nullable|string|max:253|regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i',
            'return_to' => 'nullable|in:customer',
        ]);

        $returnToCustomer = ($validated['return_to'] ?? null) === 'customer';
        unset($validated['return_to']);

        $reseller = auth()->user();

        try {
            $result = $updater->update($reseller, $service, $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Failed to update service: '.$e->getMessage())->withInput();
        }

        if ($returnToCustomer && $service->user_id) {
            $flashKey = $result['warning_message'] ? 'warning' : 'success';
            $flashMessage = $result['warning_message'] ?? $result['success_message'];

            return redirect()
                ->route('reseller.customers.show', $service->user_id)
                ->with($flashKey, $flashMessage);
        }

        if ($result['warning_message']) {
            return back()->with('warning', $result['warning_message']);
        }

        return back()->with('success', $result['success_message']);
    }

    private function ensureManaged(Service $service): void
    {
        $reseller = auth()->user();
        $owned = $service->reseller_id === $reseller->id
            || ($service->user && $service->user->reseller_id === $reseller->id);

        if (! $owned) {
            abort(404);
        }
    }
}

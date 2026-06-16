<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
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

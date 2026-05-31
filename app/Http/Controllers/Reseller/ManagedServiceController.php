<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\ResellerScopeService;
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

        return view('reseller.services.show', compact('service'));
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

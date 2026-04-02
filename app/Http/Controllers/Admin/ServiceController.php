<?php

namespace App\Http\Controllers\Admin;

use App\Models\Service;
use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::with(['user', 'product']);

        // Search by customer name or service ID
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('id', 'like', "%{$request->search}%")
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', "%{$request->search}%");
                    });
            });
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by product type
        if ($request->filled('type') && $request->type !== 'all') {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

        // Filter by customer
        if ($request->filled('customer')) {
            $query->where('user_id', $request->customer);
        }

        $services = $query->latest()->paginate(15)->withQueryString();

        return view('admin.services.index', compact('services'));
    }

    public function show(Service $service)
    {
        $service->load(['user', 'product', 'invoice']);
        return view('admin.services.show', compact('service'));
    }

    // Placeholder action methods
    public function provision(Service $service)
    {
        $service->update(['status' => 'provisioning']);
        return back()->with('success', 'Service provisioning initiated.');
    }

    public function suspend(Service $service)
    {
        $service->update(['status' => 'suspended', 'suspend_date' => now()]);
        return back()->with('success', 'Service suspended successfully.');
    }

    public function unsuspend(Service $service)
    {
        $service->update(['status' => 'active', 'suspend_date' => null]);
        return back()->with('success', 'Service unsuspended successfully.');
    }

    public function terminate(Service $service)
    {
        $service->update(['status' => 'terminated', 'terminate_date' => now()]);
        return back()->with('success', 'Service terminated successfully.');
    }

    public function refreshStatus(Service $service)
    {
        // Placeholder for checking actual service status with provisioning driver
        return back()->with('success', 'Service status refreshed.');
    }
}

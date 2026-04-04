<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\Service;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ResellerController extends Controller
{
    public function index(Request $request)
    {
        $resellers = User::where('is_reseller', true)
            ->withCount(['services as managed_services_count' => function ($query) {
                $query->whereColumn('reseller_id', 'users.id');
            }])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Calculate total services managed by all resellers
        $totalServices = Service::whereIn('reseller_id',
            User::where('is_reseller', true)->pluck('id')
        )->count();

        // Calculate unique customers served by all resellers
        $totalCustomers = User::whereIn('id',
            Service::whereIn('reseller_id',
                User::where('is_reseller', true)->pluck('id')
            )->distinct()->pluck('user_id')
        )->count();

        return view('admin.resellers.index', compact('resellers', 'totalServices', 'totalCustomers'));
    }

    public function show(User $user)
    {
        abort_if(!$user->is_reseller, 404);

        $user->load('resellerPackage');

        $services = Service::where('reseller_id', $user->id)
            ->with('user', 'product')
            ->get();

        $customerIds = $services->pluck('user_id')->unique();
        $customers = User::whereIn('id', $customerIds)->get();

        return view('admin.resellers.show', compact('user', 'services', 'customerIds', 'customers'));
    }

    public function promote(User $user)
    {
        $this->authorize('promote', $user);

        $user->update(['is_reseller' => true]);
        return back()->with('success', 'User promoted to reseller.');
    }

    public function demote(User $user)
    {
        $this->authorize('demote', $user);

        $user->update(['is_reseller' => false]);
        return back()->with('success', 'Reseller status removed.');
    }

    public function impersonate(User $user)
    {
        abort_if(!$user->is_reseller, 404);

        // Store the admin ID in session for later exit
        session(['impersonating' => auth()->id(), 'impersonating_user_id' => $user->id]);

        // Log out the current admin and log in as the reseller
        auth()->logout();
        auth()->loginUsingId($user->id);

        return redirect()->route('dashboard')
            ->with('success', "You are now viewing the dashboard as {$user->name}.");
    }
}

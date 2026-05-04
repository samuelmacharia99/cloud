<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DomainRenewalOrder;
use App\Services\DomainRenewalService;
use Illuminate\Http\Request;

class DomainRenewalController extends Controller
{
    /**
     * Show all domain renewal orders
     */
    public function index(Request $request)
    {
        $query = DomainRenewalOrder::with('domain', 'user')
            ->orderByDesc('created_at');

        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Filter by domain
        if ($request->domain) {
            $query->whereHas('domain', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->domain . '%');
            });
        }

        // Filter by customer
        if ($request->customer) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->customer . '%')
                    ->orWhere('email', 'like', '%' . $request->customer . '%');
            });
        }

        $renewals = $query->paginate(20);

        return view('admin.domain-renewals.index', compact('renewals'));
    }

    /**
     * Show domain renewal details
     */
    public function show(DomainRenewalOrder $renewal)
    {
        $renewal->load('domain', 'user', 'invoice', 'adminOrder', 'adminInvoice');
        return view('admin.domain-renewals.show', compact('renewal'));
    }

    /**
     * Complete a domain renewal
     */
    public function complete(Request $request, DomainRenewalOrder $renewal)
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        try {
            $renewalService = new DomainRenewalService();
            $renewalService->completeRenewal($renewal, $validated['admin_notes'] ?? '');

            return back()->with('success', 'Domain renewal completed successfully');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Mark renewal as failed
     */
    public function fail(Request $request, DomainRenewalOrder $renewal)
    {
        $validated = $request->validate([
            'failure_reason' => 'required|string|max:500',
        ]);

        try {
            $renewalService = new DomainRenewalService();
            $renewalService->failRenewal($renewal, $validated['failure_reason']);

            return back()->with('success', 'Domain renewal marked as failed');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Expire a domain renewal order
     */
    public function expire(DomainRenewalOrder $renewal)
    {
        try {
            $renewalService = new DomainRenewalService();
            $renewalService->expireRenewal($renewal);

            return back()->with('success', 'Domain renewal expired');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

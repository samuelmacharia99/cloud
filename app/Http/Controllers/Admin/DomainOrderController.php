<?php

namespace App\Http\Controllers\Admin;

use App\Models\ResellerDomainOrder;
use App\Services\DomainPushService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DomainOrderController extends Controller
{
    public function __construct(
        protected DomainPushService $domainPushService,
    ) {}

    public function index(Request $request)
    {
        $query = ResellerDomainOrder::with('reseller', 'customer');

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('reseller_id')) {
            $query->where('reseller_id', $request->input('reseller_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('domain_name', 'like', "%{$search}%")
                    ->orWhereHas('reseller', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('customer', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $orders = $query->latest()->paginate(20);

        return view('admin.domain-orders.index', compact('orders'));
    }

    public function show(ResellerDomainOrder $order)
    {
        $order->load('reseller', 'customer', 'domain', 'adminOrder', 'adminInvoice');

        return view('admin.domain-orders.show', compact('order'));
    }

    public function complete(Request $request, ResellerDomainOrder $order)
    {
        $validated = $request->validate([
            'registrar' => 'required|string|max:255',
        ]);

        try {
            $this->domainPushService->completeOrder($order, $validated['registrar']);

            return redirect()->route('admin.domain-orders.show', $order)
                ->with('success', "Domain {$order->domain_name} marked as completed!");
        } catch (\Exception $e) {
            return redirect()->route('admin.domain-orders.show', $order)
                ->with('error', "Failed to complete order: {$e->getMessage()}");
        }
    }

    public function fail(Request $request, ResellerDomainOrder $order)
    {
        $request->validate([
            'failure_reason' => 'required|string|min:5|max:500',
        ]);

        try {
            $this->domainPushService->failOrder($order, $request->input('failure_reason'));

            return redirect()->route('admin.domain-orders.show', $order)
                ->with('success', "Domain {$order->domain_name} marked as failed!");
        } catch (\Exception $e) {
            return redirect()->route('admin.domain-orders.show', $order)
                ->with('error', "Failed to mark order as failed: {$e->getMessage()}");
        }
    }
}

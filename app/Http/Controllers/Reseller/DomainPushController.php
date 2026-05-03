<?php

namespace App\Http\Controllers\Reseller;

use App\Models\ResellerDomainOrder;
use App\Services\DomainPushService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DomainPushController extends Controller
{
    public function __construct(
        protected DomainPushService $domainPushService,
    ) {}

    public function index(Request $request)
    {
        $reseller = auth()->user();

        $query = $reseller->domainOrders();

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('domain_name', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->latest()->paginate(15);

        return view('reseller.domain-orders.index', compact('orders'));
    }

    public function push(ResellerDomainOrder $order)
    {
        abort_if($order->reseller_id !== auth()->id(), 403);

        try {
            $this->domainPushService->pushOrder($order);

            return redirect()->route('reseller.domain-orders.index')
                ->with('success', "Domain {$order->domain_name} pushed to admin successfully!");
        } catch (\Exception $e) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', "Failed to push domain: {$e->getMessage()}");
        }
    }

    public function retry(ResellerDomainOrder $order)
    {
        abort_if($order->reseller_id !== auth()->id(), 403);

        if (!$order->canRetry()) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', 'This order cannot be retried');
        }

        try {
            $this->domainPushService->pushOrder($order);

            return redirect()->route('reseller.domain-orders.index')
                ->with('success', "Domain {$order->domain_name} retry initiated!");
        } catch (\Exception $e) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', "Failed to retry domain: {$e->getMessage()}");
        }
    }
}

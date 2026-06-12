<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerDomainOrder;
use App\Services\DomainPushService;
use Illuminate\Http\Request;

class DomainPushController extends Controller
{
    public function __construct(
        protected DomainPushService $domainPushService,
    ) {}

    public function index(Request $request)
    {
        $reseller = auth()->user();

        $query = ResellerDomainOrder::query()
            ->forManagedCustomers($reseller)
            ->with(['customer', 'customerInvoice', 'domain']);

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->integer('customer_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('domain_name', 'like', "%{$search}%")
                    ->orWhere('extension', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->latest()->paginate(15)->withQueryString();

        $customers = $reseller->customers()
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('reseller.domain-orders.index', compact('orders', 'customers'));
    }

    public function push(ResellerDomainOrder $order)
    {
        abort_if($order->reseller_id !== auth()->id(), 403);

        if ($order->status !== 'queued') {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', 'Only queued orders can be pushed.');
        }

        try {
            $result = $this->domainPushService->resellerPushOrder($order);

            return redirect()->route('reseller.domain-orders.index')
                ->with($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', "Failed to push domain: {$e->getMessage()}");
        }
    }

    public function retry(ResellerDomainOrder $order)
    {
        abort_if($order->reseller_id !== auth()->id(), 403);

        if (! $order->canRetry()) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', 'This order cannot be retried');
        }

        try {
            $result = $this->domainPushService->resellerPushOrder($order);

            return redirect()->route('reseller.domain-orders.index')
                ->with($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', "Failed to retry domain: {$e->getMessage()}");
        }
    }

    public function cancel(ResellerDomainOrder $order)
    {
        abort_if($order->reseller_id !== auth()->id(), 403);

        if (! $order->canCancel()) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', 'This order cannot be cancelled.');
        }

        try {
            $this->domainPushService->cancelOrder($order);

            return redirect()->route('reseller.domain-orders.index')
                ->with('success', "Domain order {$order->domain_name}{$order->extension} has been cancelled.");
        } catch (\Exception $e) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', "Failed to cancel order: {$e->getMessage()}");
        }
    }

    public function destroy(ResellerDomainOrder $order)
    {
        abort_if($order->reseller_id !== auth()->id(), 403);

        if (! $order->canDelete()) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', 'This order cannot be deleted.');
        }

        $label = "{$order->domain_name}{$order->extension}";

        try {
            $this->domainPushService->deleteOrder($order);

            return redirect()->route('reseller.domain-orders.index')
                ->with('success', "Domain order {$label} has been removed.");
        } catch (\Exception $e) {
            return redirect()->route('reseller.domain-orders.index')
                ->with('error', "Failed to delete order: {$e->getMessage()}");
        }
    }
}

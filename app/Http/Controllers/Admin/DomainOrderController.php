<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResellerDomainOrder;
use App\Services\DomainPushService;
use Illuminate\Http\Request;

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

        $orders = $query->latest()->paginate(20)->withQueryString();

        return view('admin.domain-orders.index', compact('orders'));
    }

    public function show(ResellerDomainOrder $order)
    {
        $order->load('reseller', 'customer', 'domain', 'adminOrder', 'adminInvoice');

        return view('admin.domain-orders.show', compact('order'));
    }

    public function complete(Request $request, ResellerDomainOrder $order)
    {
        if (! $order->canAdminComplete()) {
            return $this->redirectBack($request)
                ->with('error', 'This order cannot be completed yet. Push it first, or ensure the reseller wholesale invoice is paid.');
        }

        $validated = $request->validate([
            'registrar' => 'required|string|max:255',
        ]);

        try {
            $this->domainPushService->prepareOrderForAdminCompletion($order);
            $this->domainPushService->completeOrder($order->fresh(), $validated['registrar']);

            return $this->redirectBack($request)
                ->with('success', "Domain {$order->fullDomainName()} marked as completed.");
        } catch (\Exception $e) {
            return $this->redirectBack($request)
                ->with('error', "Failed to complete order: {$e->getMessage()}");
        }
    }

    public function fail(Request $request, ResellerDomainOrder $order)
    {
        if ($order->status !== 'pushed') {
            return $this->redirectBack($request)
                ->with('error', 'Only pushed orders can be marked as failed.');
        }

        $request->validate([
            'failure_reason' => 'required|string|min:5|max:500',
        ]);

        try {
            $this->domainPushService->failOrder($order, $request->input('failure_reason'));

            return $this->redirectBack($request)
                ->with('success', "Domain {$order->fullDomainName()} marked as failed.");
        } catch (\Exception $e) {
            return $this->redirectBack($request)
                ->with('error', "Failed to mark order as failed: {$e->getMessage()}");
        }
    }

    public function push(Request $request, ResellerDomainOrder $order)
    {
        if (! $order->canAdminPush()) {
            return $this->redirectBack($request)
                ->with('error', 'Only queued orders can be pushed.');
        }

        try {
            $result = $this->domainPushService->adminPushOrder($order);

            if ($result['success']) {
                return $this->redirectBack($request)
                    ->with('success', "Domain {$order->fullDomainName()} pushed to admin for registration. {$result['message']}");
            }

            return $this->redirectBack($request)
                ->with('error', $result['message']);
        } catch (\Exception $e) {
            return $this->redirectBack($request)
                ->with('error', "Failed to push order: {$e->getMessage()}");
        }
    }

    public function cancel(Request $request, ResellerDomainOrder $order)
    {
        if (! $order->canCancel()) {
            return $this->redirectBack($request)
                ->with('error', 'This order cannot be cancelled.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->domainPushService->cancelOrder(
                $order,
                $validated['reason'] ?? 'Cancelled by administrator',
            );

            return $this->redirectBack($request)
                ->with('success', "Domain order {$order->fullDomainName()} has been cancelled.");
        } catch (\Exception $e) {
            return $this->redirectBack($request)
                ->with('error', "Failed to cancel order: {$e->getMessage()}");
        }
    }

    public function destroy(Request $request, ResellerDomainOrder $order)
    {
        if (! $order->canAdminDelete()) {
            return $this->redirectBack($request)
                ->with('error', 'This order cannot be deleted.');
        }

        $label = $order->fullDomainName();

        try {
            $this->domainPushService->deleteOrder($order);

            return $this->redirectBack($request)
                ->with('success', "Domain order {$label} has been removed.");
        } catch (\Exception $e) {
            return $this->redirectBack($request)
                ->with('error', "Failed to delete order: {$e->getMessage()}");
        }
    }

    private function redirectBack(Request $request)
    {
        if ($request->boolean('stay_on_detail')) {
            return redirect()->route('admin.domain-orders.show', $request->route('order'));
        }

        return redirect()->route('admin.domain-orders.index', $request->only([
            'search',
            'status',
            'reseller_id',
            'from_date',
            'to_date',
            'page',
        ]));
    }
}

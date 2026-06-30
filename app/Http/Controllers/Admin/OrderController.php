<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::query();

        // Search by order number or customer name/email
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('order_number', 'like', "%{$request->search}%")
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', "%{$request->search}%")
                            ->orWhere('email', 'like', "%{$request->search}%");
                    });
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Payment status filter
        if ($request->filled('payment_status') && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        $orders = $query->with('user')
            ->withCount('items')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $order->load([
            'user',
            'items.product',
            'invoice',
            'domainRenewalOrder.domain',
            'domainRenewalOrder.invoice',
            'domainRenewalOrder.adminInvoice',
        ]);

        return view('admin.orders.show', compact('order'));
    }

    public function markComplete(Order $order)
    {
        $this->authorize('update', $order);

        $order->update([
            'status' => 'paid',
            'payment_status' => 'paid',
        ]);

        return redirect()->route('admin.orders.show', $order)->with('success', 'Order marked as complete');
    }

    public function destroy(Request $request, Order $order): RedirectResponse
    {
        $this->authorize('delete', $order);

        if (! $order->canAdminDelete()) {
            return $this->redirectBack($request)
                ->with('error', 'Only pending unpaid orders can be deleted.');
        }

        $orderNumber = $order->order_number;

        $order->delete();

        return $this->redirectBack($request)
            ->with('success', "Order {$orderNumber} has been deleted.");
    }

    private function redirectBack(Request $request): RedirectResponse
    {
        return redirect()->route('admin.orders.index', $request->only([
            'search',
            'status',
            'payment_status',
            'page',
        ]));
    }
}

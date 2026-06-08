<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $query = Order::where('user_id', auth()->id())
            ->withCount('items')
            ->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status') && $request->payment_status !== 'all') {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where('order_number', 'like', "%{$search}%");
        }

        $orders = $query->paginate(10)->withQueryString();

        return view('customer.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);

        $order->load('items.product', 'invoice');

        return view('customer.orders.show', compact('order'));
    }

    public function cancel(Order $order)
    {
        $this->authorize('cancel', $order);

        $order->update(['status' => 'cancelled']);

        if ($order->invoice && $order->invoice->status->value !== 'paid') {
            $order->invoice->update(['status' => 'cancelled']);
        }

        return redirect()->route('customer.orders.index')
            ->with('success', 'Order cancelled successfully.');
    }
}

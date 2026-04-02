<?php

namespace App\Http\Controllers\Admin;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::query();

        // Search
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('id', 'like', "%{$request->search}%")
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

        // Gateway filter
        if ($request->filled('gateway') && $request->gateway !== 'all') {
            $query->where('gateway', $request->gateway);
        }

        $payments = $query->with('user')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.payments.index', compact('payments'));
    }

    public function create()
    {
        $customers = User::where('is_admin', false)->orderBy('name')->get();
        return view('admin.payments.create', compact('customers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'gateway' => 'required|in:stripe,paypal,bank_transfer,manual',
            'status' => 'required|in:pending,completed,failed,refunded',
            'transaction_id' => 'nullable|string|unique:payments,transaction_id',
            'notes' => 'nullable|string',
        ]);

        Payment::create($validated);

        return redirect()->route('admin.payments.index')
            ->with('success', 'Payment created successfully.');
    }

    public function show(Payment $payment)
    {
        $payment->load('user', 'invoice');
        return view('admin.payments.show', compact('payment'));
    }

    public function edit(Payment $payment)
    {
        $customers = User::where('is_admin', false)->orderBy('name')->get();
        return view('admin.payments.edit', compact('payment', 'customers'));
    }

    public function update(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount' => 'required|numeric|min:0',
            'gateway' => 'required|in:stripe,paypal,bank_transfer,manual',
            'status' => 'required|in:pending,completed,failed,refunded',
            'transaction_id' => 'nullable|string|unique:payments,transaction_id,' . $payment->id,
            'notes' => 'nullable|string',
        ]);

        $payment->update($validated);

        return redirect()->route('admin.payments.show', $payment)
            ->with('success', 'Payment updated successfully.');
    }
}

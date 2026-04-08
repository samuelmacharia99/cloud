<?php

namespace App\Http\Controllers\Admin;

use App\Models\Credit;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CreditController extends Controller
{
    /**
     * List all credits
     */
    public function index(Request $request)
    {
        $query = Credit::with('user', 'payment', 'invoice');

        // Search
        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        // Status filter
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Source filter
        if ($request->filled('source') && $request->source !== 'all') {
            $query->where('source', $request->source);
        }

        $credits = $query->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.credits.index', compact('credits'));
    }

    /**
     * Show credit details
     */
    public function show(Credit $credit)
    {
        $credit->load('user', 'payment', 'invoice', 'appliedToInvoices');

        return view('admin.credits.show', compact('credit'));
    }

    /**
     * Create manual credit form
     */
    public function create()
    {
        $customers = User::where('is_admin', false)->orderBy('name')->get();

        return view('admin.credits.create', compact('customers'));
    }

    /**
     * Store manual credit
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric|min:0.01',
            'source' => 'required|in:admin,promotion,refund',
            'notes' => 'nullable|string',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $credit = CreditService::createManualCredit(
            User::find($validated['user_id']),
            (float) $validated['amount'],
            $validated['notes'] ?? '',
            $validated['expires_at'] ? \Carbon\Carbon::parse($validated['expires_at']) : null
        );

        $credit->update(['source' => $validated['source']]);

        return redirect()->route('admin.credits.show', $credit)
            ->with('success', 'Credit created successfully.');
    }

    /**
     * Delete credit
     */
    public function destroy(Credit $credit)
    {
        $userId = $credit->user_id;
        $credit->delete();

        return redirect()->route('admin.credits.index')
            ->with('success', 'Credit deleted successfully.');
    }

    /**
     * Apply credit to invoice
     */
    public function apply(Request $request, Credit $credit)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $invoice = $credit->invoice()->findOrFail($validated['invoice_id']);

        if (CreditService::applyCredit($credit, $invoice, (float) $validated['amount'])) {
            return redirect()->back()
                ->with('success', 'Credit applied successfully.');
        }

        return redirect()->back()
            ->with('error', 'Failed to apply credit. Check available balance.');
    }

    /**
     * Remove credit from invoice
     */
    public function remove(Request $request, Credit $credit)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        $invoice = $credit->invoice()->findOrFail($validated['invoice_id']);

        CreditService::removeCredit($credit, $invoice);

        return redirect()->back()
            ->with('success', 'Credit removed from invoice.');
    }

    /**
     * Customer credits report
     */
    public function customerReport(User $user)
    {
        $user->load('credits');

        $credits = Credit::forUser($user)
            ->with('appliedToInvoices')
            ->latest()
            ->get();

        $totalCredits = $credits->sum('amount');
        $availableBalance = CreditService::getAvailableBalance($user);

        return view('admin.credits.customer-report', compact(
            'user',
            'credits',
            'totalCredits',
            'availableBalance'
        ));
    }
}

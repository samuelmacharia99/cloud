<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = request()->user();
        $invoices = $user->isAdmin()
            ? Invoice::with('user')->latest()->paginate(20)
            : $user->invoices()->latest()->paginate(20);

        return view('invoices.index', compact('invoices'));
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        return view('invoices.show', compact('invoice'));
    }

    public function create()
    {
        $users = User::where('is_admin', false)->get();

        return view('invoices.create', compact('users'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'invoice_number' => 'required|unique:invoices',
            'due_date' => 'required|date',
            'subtotal' => 'required|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $validated['total'] = ($validated['subtotal'] + ($validated['tax'] ?? 0));
        $validated['status'] = 'unpaid';

        Invoice::create($validated);

        return redirect()->route('invoices.index')
            ->with('success', 'Invoice created successfully.');
    }

    public function edit(Invoice $invoice)
    {
        $this->authorize('edit', $invoice);

        return view('invoices.edit', compact('invoice'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        $validated = $request->validate([
            'status' => 'required|in:unpaid,paid,overdue,cancelled',
            'due_date' => 'required|date',
            'notes' => 'nullable|string',
        ]);

        if ($request->input('status') === 'paid' && !$invoice->paid_date) {
            $validated['paid_date'] = now();
        }

        $invoice->update($validated);

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice updated successfully.');
    }

    public function destroy(Invoice $invoice)
    {
        $this->authorize('delete', $invoice);
        $invoice->delete();

        return redirect()->route('invoices.index')
            ->with('success', 'Invoice deleted successfully.');
    }
}

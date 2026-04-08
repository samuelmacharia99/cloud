<?php

namespace App\Http\Controllers\Customer;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\DomainTransferService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DomainController extends Controller
{
    /**
     * List all domains owned by the customer
     */
    public function index()
    {
        // Get all domains registered by the customer
        $domains = Domain::where('user_id', auth()->id())
            ->orderBy('name')
            ->get();

        // Get all domain services for this user
        $domainServices = Service::where('user_id', auth()->id())
            ->whereHas('product', function ($q) {
                $q->where('type', 'domain');
            })
            ->with('product')
            ->get();

        return view('customer.domains.index', [
            'domains' => $domains,
            'domainServices' => $domainServices,
        ]);
    }

    /**
     * Show domain transfer form
     */
    public function showTransferForm()
    {
        return view('customer.domains.transfer-form');
    }

    /**
     * Process domain transfer request
     */
    public function processTransfer(Request $request)
    {
        $validated = $request->validate([
            'domain_name' => 'required|string|regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i',
            'extension' => 'required|string|in:.com,.net,.org,.io,.co,.uk,.de,.fr,.ca',
            'epp_code' => 'required|string|min:5',
            'old_registrar' => 'required|string|min:2',
            'old_registrar_url' => 'nullable|url',
        ]);

        try {
            // Get extension and transfer price
            $extension = DomainExtension::where('extension', $validated['extension'])->firstOrFail();
            $transferPrice = (float) $extension->transfer_price ?? 0;

            // Create transfer request
            $domain = DomainTransferService::createTransferRequest(
                auth()->user(),
                $validated['domain_name'],
                $validated['extension'],
                $validated['epp_code'],
                $validated['old_registrar'],
                $validated['old_registrar_url'] ?? null
            );

            // Create invoice for the domain transfer
            $invoice = Invoice::create([
                'user_id' => auth()->id(),
                'invoice_number' => 'INV-' . strtoupper(uniqid()),
                'status' => 'pending',
                'due_date' => now()->addDays(7),
                'subtotal' => $transferPrice,
                'tax' => 0,
                'total' => $transferPrice,
            ]);

            // Add invoice item for domain transfer
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => "Domain Transfer: {$domain->name}{$domain->extension}",
                'quantity' => 1,
                'unit_price' => $transferPrice,
                'total' => $transferPrice,
            ]);

            // Redirect to checkout
            return response()->json([
                'success' => true,
                'message' => 'Domain transfer request created. Proceed to checkout.',
                'redirect' => route('customer.checkout.show', ['invoice_id' => $invoice->id]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show domain transfer details
     */
    public function showTransferDetails(Domain $domain)
    {
        // Verify ownership
        abort_if($domain->user_id !== auth()->id(), 403);
        abort_if(!$domain->isTransfer(), 404);

        $instructions = DomainTransferService::getTransferInstructions($domain);
        $estimatedCompletion = DomainTransferService::getEstimatedCompletionDate($domain);

        return view('customer.domains.transfer-details', compact(
            'domain',
            'instructions',
            'estimatedCompletion'
        ));
    }

    /**
     * Cancel a domain transfer
     */
    public function cancelTransfer(Request $request, Domain $domain)
    {
        // Verify ownership
        abort_if($domain->user_id !== auth()->id(), 403);
        abort_if(!$domain->isTransfer(), 404);

        // Can only cancel if transfer is pending or initiated
        if (!in_array($domain->transfer_status, ['pending', 'initiated'])) {
            return redirect()->back()
                ->with('error', 'Cannot cancel a ' . $domain->transfer_status . ' transfer');
        }

        $reason = $request->input('reason', 'Cancelled by user');

        if (DomainTransferService::cancelTransfer($domain, $reason)) {
            return redirect()->route('customer.domains.index')
                ->with('success', 'Domain transfer cancelled successfully');
        }

        return redirect()->back()
            ->with('error', 'Failed to cancel domain transfer');
    }
}

<?php

namespace App\Http\Controllers\Customer;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Currency;
use App\Services\DomainTransferService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
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
        $extensions = DomainExtension::where('enabled', true)
            ->select('id', 'extension', 'transfer_price', 'description')
            ->orderBy('extension')
            ->get();

        return view('customer.domains.transfer-form', compact('extensions'));
    }

    /**
     * Process domain transfer request
     */
    public function processTransfer(Request $request)
    {
        $validated = $request->validate([
            'domain_name' => 'required|string|regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i',
            'extension' => [
                'required',
                'string',
                Rule::in(DomainExtension::where('enabled', true)->pluck('extension')),
            ],
            'epp_code' => 'required|string|min:5',
            'old_registrar' => 'required|string|min:2',
            'old_registrar_url' => 'nullable|url',
        ]);

        try {
            // Get extension and transfer price
            $extension = DomainExtension::where('extension', $validated['extension'])->firstOrFail();
            $transferPrice = (float) $extension->transfer_price ?? 0;

            // Create transfer request (but don't create invoice yet)
            $domain = DomainTransferService::createTransferRequest(
                auth()->user(),
                $validated['domain_name'],
                $validated['extension'],
                $validated['epp_code'],
                $validated['old_registrar'],
                $validated['old_registrar_url'] ?? null
            );

            // Store transfer details in session for checkout confirmation
            session([
                'transfer_checkout' => [
                    'domain_id' => $domain->id,
                    'domain_name' => "{$domain->name}{$domain->extension}",
                    'transfer_price' => $transferPrice,
                    'extension_id' => $extension->id,
                ]
            ]);

            // Redirect to checkout confirmation page
            return response()->json([
                'success' => true,
                'message' => 'Domain transfer request created. Proceed to checkout.',
                'redirect' => route('customer.domains.transfer-checkout'),
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

    /**
     * Show domain transfer checkout page
     */
    public function showTransferCheckout()
    {
        $transferCheckout = session('transfer_checkout');

        abort_if(!$transferCheckout, 404, 'Transfer not found');

        // Get domain and extension for verification
        $domain = Domain::findOrFail($transferCheckout['domain_id']);
        abort_if($domain->user_id !== auth()->id(), 403);

        // Calculate totals
        $subtotal = $transferCheckout['transfer_price'];
        $taxEnabled = Setting::getValue('tax_enabled') == 'true';
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
        $total = $subtotal + $tax;

        // Get currency info
        $currencyCode = Setting::getValue('currency', 'KES');
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();

        return view('customer.domains.transfer-checkout', [
            'domain' => $domain,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'taxEnabled' => $taxEnabled,
            'taxRate' => $taxRate,
            'total' => $total,
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
    }

    /**
     * Confirm domain transfer checkout and create invoice
     */
    public function confirmTransferCheckout(Request $request)
    {
        $request->validate([
            'agree_terms' => 'required|accepted',
        ]);

        $transferCheckout = session('transfer_checkout');
        abort_if(!$transferCheckout, 404, 'Transfer not found');

        try {
            // Get domain and verify ownership
            $domain = Domain::findOrFail($transferCheckout['domain_id']);
            abort_if($domain->user_id !== auth()->id(), 403);

            // Create invoice and invoice item within a transaction
            $invoice = DB::transaction(function () use ($domain, $transferCheckout) {
                $transferPrice = $transferCheckout['transfer_price'];

                // Create invoice
                $invoice = Invoice::create([
                    'user_id' => auth()->id(),
                    'invoice_number' => 'INV-' . strtoupper(uniqid()),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays(7),
                    'subtotal' => $transferPrice,
                    'tax' => 0,
                    'total' => $transferPrice,
                ]);

                // Create invoice item
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => "Domain Transfer: {$domain->name}{$domain->extension}",
                    'quantity' => 1,
                    'unit_price' => $transferPrice,
                    'amount' => $transferPrice,
                ]);

                return $invoice;
            }, 2);

            // Clear session
            session()->forget('transfer_checkout');

            // Redirect to invoice payment page
            return redirect()->route('customer.checkout.show', ['invoice_id' => $invoice->id])
                ->with('success', 'Order confirmed. Please select a payment method.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}

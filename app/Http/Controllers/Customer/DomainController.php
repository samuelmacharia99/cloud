<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\DomainRenewalService;
use App\Services\DomainTransferService;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\ResellerCustomerCatalogService;
use App\Services\ResellerDomainOrderService;
use App\Services\TaxService;
use App\Services\UserCurrencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    /**
     * List all domains owned by the customer
     */
    public function index()
    {
        $this->authorize('viewAny', Domain::class);

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
            'cloudflareDnsAvailable' => app(DomainCloudflareDnsService::class)->isAvailable(),
        ]);
    }

    /**
     * Add an externally registered domain for DNS management (Cloudflare zone).
     */
    public function storeDnsDomain(Request $request)
    {
        $this->authorize('create', Domain::class);

        $dns = app(DomainCloudflareDnsService::class);

        if (! $dns->isAvailable()) {
            return back()->with('error', 'Managed DNS is not available right now. Contact support.')->withInput();
        }

        $validated = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
        ]);

        $parsed = $this->parseCustomerDnsDomain((string) $validated['domain']);
        if ($parsed === null) {
            return back()
                ->withErrors(['domain' => 'Enter a valid domain using a supported extension (e.g. example.co.ke).'])
                ->withInput();
        }

        $exists = Domain::query()
            ->whereRaw('LOWER(CONCAT(name, extension)) = ?', [$parsed['fqdn']])
            ->exists();

        if ($exists) {
            return back()
                ->withErrors(['domain' => 'This domain is already on the platform. Open it from your list to manage DNS.'])
                ->withInput();
        }

        $user = $request->user();

        $domain = Domain::create([
            'user_id' => $user->id,
            'reseller_id' => $user->reseller_id,
            'name' => $parsed['name'],
            'extension' => $parsed['extension'],
            'type' => 'dns',
            'status' => 'active',
            'registrar' => 'external',
            'registered_at' => null,
            'expires_at' => null,
            'auto_renew' => false,
            'cloudflare_dns_enabled' => true,
            'notes' => [
                'source' => 'customer_dns_add',
                'added_at' => now()->toIso8601String(),
            ],
        ]);

        $result = $dns->provisionZone($domain->fresh());

        if (! $result['success']) {
            $domain->delete();

            return back()
                ->with('error', 'Could not create DNS zone: '.$result['message'])
                ->withInput();
        }

        $domain->refresh();

        return redirect()
            ->route('customer.domains.dns.index', $domain)
            ->with('success', 'Domain added. Point your registrar nameservers to the NS records shown below, then manage DNS here.');
    }

    /**
     * @return array{name: string, extension: string, fqdn: string}|null
     */
    private function parseCustomerDnsDomain(string $input): ?array
    {
        $fqdn = strtolower(trim($input));
        $fqdn = preg_replace('#^https?://#', '', $fqdn) ?? $fqdn;
        $fqdn = explode('/', $fqdn)[0] ?? $fqdn;
        $fqdn = explode('?', $fqdn)[0] ?? $fqdn;
        $fqdn = rtrim($fqdn, '.');

        if ($fqdn === '' || ! str_contains($fqdn, '.')) {
            return null;
        }

        $extensions = DomainExtension::query()
            ->where('enabled', true)
            ->pluck('extension')
            ->map(function (string $ext) {
                $ext = strtolower(trim($ext));

                return str_starts_with($ext, '.') ? $ext : '.'.$ext;
            })
            ->sortByDesc(fn (string $ext) => strlen($ext))
            ->values();

        foreach ($extensions as $extension) {
            if (! str_ends_with($fqdn, $extension)) {
                continue;
            }

            $name = substr($fqdn, 0, -strlen($extension));
            if ($name === '' || str_contains($name, '.') || ! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $name)) {
                return null;
            }

            return [
                'name' => strtolower($name),
                'extension' => $extension,
                'fqdn' => strtolower($name).$extension,
            ];
        }

        return null;
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
            $transferPrice = app(ResellerCustomerCatalogService::class)
                ->domainTransferPrice(auth()->user(), $extension);

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
                ],
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
        $this->authorize('view', $domain);
        abort_if(! $domain->isTransfer(), 404);

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
        $this->authorize('view', $domain);
        abort_if(! $domain->isTransfer(), 404);

        // Can only cancel if transfer is pending or initiated
        if (! in_array($domain->transfer_status, ['pending', 'initiated'])) {
            return redirect()->back()
                ->with('error', 'Cannot cancel a '.$domain->transfer_status.' transfer');
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

        abort_if(! $transferCheckout, 404, 'Transfer not found');

        // Get domain and extension for verification
        $domain = Domain::findOrFail($transferCheckout['domain_id']);
        $this->authorize('view', $domain);

        $taxBreakdown = TaxService::calculateForUser((float) $transferCheckout['transfer_price'], auth()->user());

        $currency = app(UserCurrencyService::class)->model(auth()->user());
        $currencyCode = $currency->code;

        return view('customer.domains.transfer-checkout', [
            'domain' => $domain,
            'subtotal' => $taxBreakdown['subtotal'],
            'tax' => $taxBreakdown['tax'],
            'taxEnabled' => $taxBreakdown['enabled'],
            'taxRate' => $taxBreakdown['rate'],
            'taxName' => $taxBreakdown['name'],
            'total' => $taxBreakdown['total'],
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
        abort_if(! $transferCheckout, 404, 'Transfer not found');

        try {
            // Get domain and verify ownership
            $domain = Domain::findOrFail($transferCheckout['domain_id']);
            $this->authorize('view', $domain);

            $user = auth()->user();

            // Create invoice and invoice item within a transaction
            $invoice = DB::transaction(function () use ($domain, $transferCheckout, $user) {
                $transferPrice = $transferCheckout['transfer_price'];
                $taxBreakdown = TaxService::calculateForUser((float) $transferPrice, $user);

                // Create invoice
                $invoice = Invoice::create([
                    'user_id' => $user->id,
                    'invoice_number' => 'INV-'.strtoupper(uniqid()),
                    'status' => 'unpaid',
                    'due_date' => now()->addDays(7),
                    'subtotal' => $taxBreakdown['subtotal'],
                    'tax' => $taxBreakdown['tax'],
                    'total' => $taxBreakdown['total'],
                ]);

                $domainOrder = null;
                if ($user->reseller_id) {
                    $domainOrder = app(ResellerDomainOrderService::class)->createForTransferCheckout(
                        $user,
                        $domain,
                        $invoice,
                        $domain->name,
                        $domain->extension,
                        (float) $transferPrice,
                    );
                }

                $invoiceItemData = [
                    'invoice_id' => $invoice->id,
                    'domain_id' => $domain->id,
                    'product_type' => 'Domain',
                    'description' => "Domain Transfer: {$domain->name}{$domain->extension}",
                    'quantity' => 1,
                    'unit_price' => $transferPrice,
                    'amount' => $transferPrice,
                    'custom_options' => [
                        'type' => 'domain_transfer',
                        'domain_id' => $domain->id,
                    ],
                ];

                if ($domainOrder) {
                    $invoiceItemData = array_merge(
                        $invoiceItemData,
                        app(ResellerDomainOrderService::class)->invoiceItemAttributes($domainOrder),
                    );
                }

                InvoiceItem::create($invoiceItemData);

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

    /**
     * Initiate domain renewal
     */
    public function initiateRenewal(Request $request, Domain $domain)
    {
        $this->authorize('update', $domain);

        $validated = $request->validate([
            'years' => 'required|integer|min:1|max:10',
        ]);

        try {
            $renewalService = new DomainRenewalService;
            $renewalOrder = $renewalService->initiateRenewal($domain, auth()->user(), $validated['years']);

            // Redirect to renewal checkout
            session(['renewal_checkout' => [
                'domain_id' => $domain->id,
                'renewal_order_id' => $renewalOrder->id,
                'years' => $validated['years'],
                'amount' => $renewalOrder->amount,
                'domain_name' => "{$domain->name}{$domain->extension}",
            ]]);

            return response()->json([
                'success' => true,
                'message' => 'Domain renewal initiated. Proceed to checkout.',
                'redirect' => route('customer.domains.renewal-checkout'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show renewal checkout page
     */
    public function showRenewalCheckout()
    {
        $renewalCheckout = session('renewal_checkout');
        abort_if(! $renewalCheckout, 404, 'Renewal not found');

        $domain = Domain::findOrFail($renewalCheckout['domain_id']);
        $this->authorize('view', $domain);

        $taxBreakdown = TaxService::calculateForUser((float) $renewalCheckout['amount'], auth()->user());

        $currency = app(UserCurrencyService::class)->model(auth()->user());
        $currencyCode = $currency->code;

        return view('customer.domains.renewal-checkout', [
            'domain' => $domain,
            'years' => $renewalCheckout['years'],
            'subtotal' => $taxBreakdown['subtotal'],
            'tax' => $taxBreakdown['tax'],
            'taxEnabled' => $taxBreakdown['enabled'],
            'taxRate' => $taxBreakdown['rate'],
            'taxName' => $taxBreakdown['name'],
            'total' => $taxBreakdown['total'],
            'currency' => $currency,
            'currencyCode' => $currencyCode,
        ]);
    }

    /**
     * Confirm renewal checkout and create invoice
     */
    public function confirmRenewalCheckout(Request $request)
    {
        $request->validate([
            'agree_terms' => 'required|accepted',
        ]);

        $renewalCheckout = session('renewal_checkout');
        abort_if(! $renewalCheckout, 404, 'Renewal not found');

        try {
            $renewalService = new DomainRenewalService;
            $renewalOrder = DomainRenewalOrder::findOrFail($renewalCheckout['renewal_order_id']);

            abort_if($renewalOrder->user_id !== auth()->id(), 403);

            // Create invoice
            $invoice = $renewalService->createInvoice($renewalOrder);

            session()->forget('renewal_checkout');

            return redirect()->route('customer.checkout.show', ['invoice_id' => $invoice->id])
                ->with('success', 'Renewal order confirmed. Please select a payment method.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}

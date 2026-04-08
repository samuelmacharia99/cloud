<?php

namespace App\Http\Controllers\Admin;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::with(['user', 'product']);

        // Search by customer name or service ID
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('id', 'like', "%{$request->search}%")
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('name', 'like', "%{$request->search}%");
                    });
            });
        }

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by product type
        if ($request->filled('type') && $request->type !== 'all') {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('type', $request->type);
            });
        }

        // Filter by customer
        if ($request->filled('customer')) {
            $query->where('user_id', $request->customer);
        }

        $services = $query->latest()->paginate(15)->withQueryString();

        return view('admin.services.index', compact('services'));
    }

    public function create()
    {
        $this->authorize('create', Service::class);

        $customers = User::where('is_admin', false)->orderBy('name')->get(['id', 'name', 'email']);
        $products = Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'monthly_price', 'yearly_price', 'billing_cycle']);

        return view('admin.services.create', compact('customers', 'products'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Service::class);

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'billing_cycle' => 'required|in:monthly,quarterly,semi-annual,annual',
            'next_due_date' => 'required|date',
            'custom_domain' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'generate_invoice' => 'boolean',
            'provision_now' => 'boolean',
        ]);

        $product = Product::findOrFail($request->product_id);
        $user = User::findOrFail($request->user_id);

        try {
            \DB::transaction(function () use ($request, $product, $user) {
                // Create service
                $service = Service::create([
                    'user_id' => $request->user_id,
                    'product_id' => $request->product_id,
                    'name' => $request->name,
                    'billing_cycle' => $request->billing_cycle,
                    'next_due_date' => $request->next_due_date,
                    'status' => 'pending',
                    'provisioning_driver_key' => $product->provisioning_driver_key,
                    'notes' => $request->notes,
                ]);

                // If domain product, create domain record
                if ($product->type === 'domain') {
                    $domain = $request->custom_domain ?? $service->name;
                    // Extract extension and name
                    if (strpos($domain, '.') !== false) {
                        [$name, $ext] = explode('.', $domain, 2);
                        $ext = '.' . $ext;
                    } else {
                        $name = $domain;
                        $ext = '.com';
                    }

                    Domain::create([
                        'user_id' => $user->id,
                        'name' => $name,
                        'extension' => $ext,
                        'status' => 'pending',
                    ]);
                }

                // Generate invoice if requested
                if ($request->boolean('generate_invoice')) {
                    $price = $this->getServicePrice($product, $request->billing_cycle);
                    $taxEnabled = Setting::getValue('tax_enabled') == 'true';
                    $taxRate = (float) Setting::getValue('tax_rate', 0);

                    $subtotal = $price;
                    $tax = $taxEnabled ? ($subtotal * $taxRate / 100) : 0;
                    $total = $subtotal + $tax;

                    $invoice = Invoice::create([
                        'user_id' => $user->id,
                        'invoice_number' => $this->generateInvoiceNumber(),
                        'status' => 'unpaid',
                        'due_date' => now()->addDays((int) Setting::getValue('invoice_due_days', 30)),
                        'subtotal' => $subtotal,
                        'tax' => $tax,
                        'total' => $total,
                    ]);

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'service_id' => $service->id,
                        'product_id' => $product->id,
                        'description' => $service->name,
                        'quantity' => 1,
                        'unit_price' => $price,
                        'amount' => $price,
                    ]);

                    $service->update(['invoice_id' => $invoice->id]);
                }

                // Provision immediately if requested
                if ($request->boolean('provision_now')) {
                    $provisioningService = new ProvisioningService();
                    $provisioningService->provision($service);
                }
            });

            return redirect()->route('admin.services.show', $service)->with('success', 'Service created successfully.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Failed to create service: ' . $e->getMessage());
        }
    }

    public function show(Service $service)
    {
        $service->load(['user', 'product', 'invoice']);
        return view('admin.services.show', compact('service'));
    }

    public function provision(Service $service)
    {
        try {
            $provisioningService = new ProvisioningService();
            $provisioningService->provision($service);
            return back()->with('success', 'Service provisioned successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Provisioning failed: ' . $e->getMessage());
        }
    }

    public function suspend(Service $service)
    {
        try {
            $provisioningService = new ProvisioningService();
            $provisioningService->suspend($service);
            return back()->with('success', 'Service suspended successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Suspension failed: ' . $e->getMessage());
        }
    }

    public function unsuspend(Service $service)
    {
        try {
            $provisioningService = new ProvisioningService();
            $provisioningService->unsuspend($service);
            return back()->with('success', 'Service unsuspended successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Unsuspension failed: ' . $e->getMessage());
        }
    }

    public function terminate(Service $service)
    {
        try {
            $provisioningService = new ProvisioningService();
            $provisioningService->terminate($service);
            return back()->with('success', 'Service terminated successfully.');
        } catch (\Exception $e) {
            return back()->with('error', 'Termination failed: ' . $e->getMessage());
        }
    }

    public function refreshStatus(Service $service)
    {
        // Placeholder for checking actual service status with provisioning driver
        return back()->with('success', 'Service status refreshed.');
    }

    public function resendCredentials(Service $service)
    {
        // Only allow for VPS and Dedicated Server products
        if (!Product::isServerType($service->product->type)) {
            return back()->with('error', 'Credentials can only be resent for VPS and Dedicated Server services.');
        }

        // Check if service has credentials
        if (!$service->credentials) {
            return back()->with('error', 'No credentials found for this service.');
        }

        try {
            // Send credentials to customer
            \Mail::to($service->user->email)->send(new \App\Mail\ServerCredentialsMail($service));

            // Send order notification to admin
            $adminEmail = Setting::getValue('admin_email');
            if ($adminEmail) {
                \Mail::to($adminEmail)->send(new \App\Mail\AdminServerOrderMail($service));
            }

            return back()->with('success', 'Credentials have been resent to the customer and admin.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to send credentials: ' . $e->getMessage());
        }
    }

    /**
     * Get service price based on billing cycle
     */
    private function getServicePrice(Product $product, string $billingCycle): float
    {
        return match ($billingCycle) {
            'monthly' => (float) $product->monthly_price,
            'quarterly' => ((float) $product->monthly_price * 3),
            'semi-annual' => ((float) $product->monthly_price * 6),
            'annual' => (float) ($product->yearly_price ?? ((float) $product->monthly_price * 12)),
            default => 0,
        };
    }

    /**
     * Generate unique invoice number
     */
    private function generateInvoiceNumber(): string
    {
        $prefix = Setting::getValue('invoice_prefix', 'INV');
        $date = now()->format('Ymd');
        $count = Invoice::whereDate('created_at', now())->count() + 1;

        return "{$prefix}-{$date}-" . str_pad($count, 5, '0', STR_PAD_LEFT);
    }
}

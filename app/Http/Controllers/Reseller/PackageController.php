<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerPackage;
use App\Models\Invoice;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    /**
     * Display available packages and current plan.
     * GET /my/packages
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $user->load('resellerPackage');

        $billingCycle = $request->get('cycle', $user->resellerPackage?->billing_cycle ?? 'monthly');

        $packages = ResellerPackage::where('active', true)
            ->where('billing_cycle', $billingCycle)
            ->orderBy('price', 'asc')
            ->get();

        // Usage stats for current plan
        $currentServices = $user->getManagedActiveServicesCount();
        $currentCustomers = $user->getManagedCustomersCount();

        return view('reseller.packages.index', compact(
            'user',
            'packages',
            'billingCycle',
            'currentServices',
            'currentCustomers'
        ));
    }

    /**
     * Subscribe to a package or upgrade to a higher tier.
     * POST /my/packages/{package}/subscribe
     */
    public function subscribe(ResellerPackage $package)
    {
        $user = auth()->user();

        // Guard: must be a reseller
        if (!$user->isReseller()) {
            abort(403, 'Only resellers can subscribe to packages.');
        }

        // Guard: package must be active
        if (!$package->active) {
            return back()->with('error', 'This package is not available.');
        }

        // Guard: downgrade prevention
        if ($user->resellerPackage && $package->price < $user->resellerPackage->price) {
            return back()->with('error', 'You cannot downgrade to a lower-tier package.');
        }

        // Guard: same package re-subscription
        if ($user->reseller_package_id === $package->id) {
            return back()->with('info', 'You are already subscribed to this package.');
        }

        // Create an invoice for the subscription
        $invoice = $this->createPackageInvoice($user, $package);

        // Assign the package (active immediately regardless of payment)
        $user->update([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => now(),
        ]);

        return redirect()
            ->route('reseller.packages.index')
            ->with('success', "Successfully subscribed to {$package->name}. Invoice #{$invoice->invoice_number} has been generated for payment.");
    }

    /**
     * Creates an unpaid invoice for the package subscription.
     */
    private function createPackageInvoice($user, ResellerPackage $package): Invoice
    {
        $invoiceNumber = 'INV-' . strtoupper(uniqid());

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'invoice_number' => $invoiceNumber,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => $package->price,
            'tax' => 0,
            'total' => $package->price,
            'notes' => "Reseller Package: {$package->name} ({$package->billing_cycle})",
        ]);

        return $invoice;
    }
}

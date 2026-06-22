<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerPackage;
use App\Services\ResellerPackageSubscriptionService;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function __construct(
        private ResellerPackageSubscriptionService $subscriptions,
    ) {}

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

        $currentServices = $user->getManagedActiveServicesCount();
        $currentCustomers = $user->getResellerUserCountForLimits();
        $hostedUserCountSource = $user->getResellerUserCountBreakdown()['source'];
        $pendingInvoice = $this->subscriptions->pendingSubscriptionInvoice($user);
        $pendingRenewalInvoice = $user->resellerPackage
            ? $this->subscriptions->pendingRenewalSubscriptionInvoice($user)
            : null;
        $renewalTotal = null;
        $renewalExtendsTo = null;
        if ($user->resellerPackage) {
            $renewalTotal = $this->subscriptions->calculateAmounts((float) $user->resellerPackage->price)['total'];
            $renewalExtendsTo = $this->subscriptions->previewRenewalExpiry($user);
        }

        $upgradeQuotes = [];
        if ($user->resellerPackage) {
            foreach ($packages as $pkg) {
                if ($this->subscriptions->isPackageUpgrade($user, $pkg)) {
                    $upgradeQuotes[$pkg->id] = $this->subscriptions->upgradeQuote($user, $pkg);
                }
            }
        }

        return view('reseller.packages.index', compact(
            'user',
            'packages',
            'billingCycle',
            'currentServices',
            'currentCustomers',
            'hostedUserCountSource',
            'pendingInvoice',
            'pendingRenewalInvoice',
            'renewalTotal',
            'renewalExtendsTo',
            'upgradeQuotes',
        ));
    }

    /**
     * Renew the current package (early or in-window). POST /my/packages/renew
     */
    public function renew()
    {
        $user = auth()->user();

        if (! $user->isReseller()) {
            abort(403, 'Only resellers can renew packages.');
        }

        if (! $user->resellerPackage) {
            return back()->with('error', 'You do not have an active plan to renew.');
        }

        $pending = $this->subscriptions->pendingRenewalSubscriptionInvoice($user);
        if ($pending) {
            return redirect()
                ->route('reseller.payment.select-method', $pending)
                ->with('info', 'Complete payment for renewal invoice #'.$pending->invoice_number.'.');
        }

        try {
            $invoice = $this->subscriptions->createRenewalInvoiceIfNeeded($user, force: true);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($invoice->isPaid()) {
            return back()->with('success', 'Your plan was renewed using your wallet balance.');
        }

        return redirect()
            ->route('reseller.payment.select-method', $invoice)
            ->with('success', 'Renewal invoice #'.$invoice->invoice_number.' created. Complete payment to extend your plan.');
    }

    /**
     * Start checkout for a package subscription or upgrade.
     * POST /my/packages/{package}/subscribe
     */
    public function subscribe(ResellerPackage $package)
    {
        $user = auth()->user();

        if (! $user->isReseller()) {
            abort(403, 'Only resellers can subscribe to packages.');
        }

        if (! $package->active) {
            return back()->with('error', 'This package is not available.');
        }

        if ($user->resellerPackage && $package->price < $user->resellerPackage->price) {
            return back()->with('error', 'You cannot downgrade to a lower-tier package.');
        }

        if ($user->reseller_package_id === $package->id) {
            return back()->with('info', 'You are already subscribed to this package.');
        }

        $existingInvoice = $this->subscriptions->pendingSubscriptionInvoice($user, $package);
        if ($existingInvoice) {
            return redirect()
                ->route('reseller.payment.select-method', $existingInvoice)
                ->with('info', 'Complete payment for invoice #'.$existingInvoice->invoice_number.' to activate this plan.');
        }

        $invoice = $this->subscriptions->createSubscriptionInvoice($user, $package);

        return redirect()
            ->route('reseller.payment.select-method', $invoice)
            ->with('success', 'Invoice #'.$invoice->invoice_number.' created. Complete payment to activate your plan.');
    }
}

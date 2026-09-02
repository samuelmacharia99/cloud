<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DomainRenewalOrder;
use App\Services\DomainRenewalService;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Http\Request;

class DomainRenewalController extends Controller
{
    /**
     * Show all domain renewal orders
     */
    public function index(Request $request)
    {
        $query = DomainRenewalOrder::with('domain', 'user')
            ->orderByDesc('created_at');

        // Filter by status
        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Filter by domain
        if ($request->domain) {
            $query->whereHas('domain', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->domain.'%');
            });
        }

        // Filter by customer
        if ($request->customer) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->customer.'%')
                    ->orWhere('email', 'like', '%'.$request->customer.'%');
            });
        }

        $renewals = $query->paginate(20);

        return view('admin.domain-renewals.index', compact('renewals'));
    }

    /**
     * Show domain renewal details
     */
    public function show(DomainRenewalOrder $renewal)
    {
        $renewal->load('domain', 'user', 'invoice', 'adminOrder', 'adminInvoice');

        $renewalService = app(DomainRenewalService::class);
        $notificationRecipient = $renewalService->renewalNotificationRecipient($renewal);
        $availableYears = range(1, 10);

        return view('admin.domain-renewals.show', compact(
            'renewal',
            'notificationRecipient',
            'availableYears',
            'renewalService',
        ));
    }

    /**
     * Submit or retry this renewal at the API registrar.
     */
    public function pushToRegistrar(Request $request, DomainRenewalOrder $renewal)
    {
        if (! $renewal->canPushToRegistrar()) {
            return back()->with('error', 'This renewal cannot be submitted to the registrar yet. It must be paid and pushed to admin first.');
        }

        try {
            $result = app(RegistrarFulfillmentService::class)->fulfillRenewalManually($renewal);

            return back()->with($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Complete a domain renewal via registrar API (when available).
     */
    public function complete(Request $request, DomainRenewalOrder $renewal)
    {
        $request->validate([
            'admin_notes' => 'nullable|string|max:500',
        ]);

        return $this->pushToRegistrar($request, $renewal);
    }

    /**
     * Mark a domain renewal complete after manual registrar work.
     */
    public function completeManually(Request $request, DomainRenewalOrder $renewal)
    {
        $validated = $request->validate([
            'years' => 'required|integer|min:1|max:10',
            'admin_notes' => 'nullable|string|max:500',
            'send_notification' => 'sometimes|boolean',
        ]);

        try {
            $renewalService = app(DomainRenewalService::class);
            $domain = $renewalService->completeRenewalManually(
                $renewal,
                (int) $validated['years'],
                $validated['admin_notes'] ?? '',
                $request->boolean('send_notification', true),
            );

            return back()->with('success', 'Domain renewed until '.$domain->expires_at->format('M d, Y').'. Notification sent to account owner.');
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Mark renewal as failed
     */
    public function fail(Request $request, DomainRenewalOrder $renewal)
    {
        $validated = $request->validate([
            'failure_reason' => 'required|string|max:500',
        ]);

        try {
            $renewalService = new DomainRenewalService;
            $renewalService->failRenewal($renewal, $validated['failure_reason']);

            return back()->with('success', 'Domain renewal marked as failed');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Expire a domain renewal order
     */
    public function expire(DomainRenewalOrder $renewal)
    {
        try {
            $renewalService = new DomainRenewalService;
            $renewalService->expireRenewal($renewal);

            return back()->with('success', 'Domain renewal expired');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

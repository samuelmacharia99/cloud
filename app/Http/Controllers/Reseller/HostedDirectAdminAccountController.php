<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerHostedAccountLinkService;
use App\Services\ResellerScopeService;
use Illuminate\Http\Request;

class HostedDirectAdminAccountController extends Controller
{
    public function __construct(
        private ResellerHostedAccountLinkService $linkService,
        private ResellerScopeService $scope,
    ) {}

    public function link(Request $request)
    {
        $reseller = auth()->user();

        $validated = $request->validate([
            'da_username' => 'required|string|max:48',
            'customer_id' => 'nullable|exists:users,id',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email',
            'phone' => 'nullable|string',
            'country' => 'nullable|string|size:2',
            'reseller_product_id' => 'nullable|exists:reseller_products,id',
            'billing_cycle' => 'nullable|in:monthly,quarterly,semi-annual,annual',
            'custom_price' => 'nullable|numeric|min:0',
            'next_due_date' => 'nullable|date',
        ]);

        if (! empty($validated['customer_id'])) {
            $customer = User::findOrFail($validated['customer_id']);
            abort_unless($customer->reseller_id === $reseller->id, 403);
        }

        try {
            $result = $this->linkService->linkAccount($reseller, $validated['da_username'], $validated);

            return redirect()
                ->route('reseller.customers.show', $result['customer'])
                ->with('success', "DirectAdmin account {$validated['da_username']} linked to the platform.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function bulkLink(Request $request)
    {
        $reseller = auth()->user();

        $validated = $request->validate([
            'da_usernames' => 'required|array|min:1|max:50',
            'da_usernames.*' => 'required|string|max:48',
            'reseller_product_id' => 'nullable|exists:reseller_products,id',
            'billing_cycle' => 'nullable|in:monthly,quarterly,semi-annual,annual',
            'country' => 'nullable|string|size:2',
        ]);

        $defaults = array_filter([
            'reseller_product_id' => $validated['reseller_product_id'] ?? null,
            'billing_cycle' => $validated['billing_cycle'] ?? 'annual',
            'country' => $validated['country'] ?? 'KE',
        ]);

        $result = $this->linkService->bulkLink($reseller, $validated['da_usernames'], $defaults);

        $message = "{$result['linked']} account(s) linked.";
        if ($result['failed'] !== []) {
            $message .= ' '.count($result['failed']).' failed.';
        }

        return redirect()
            ->route('reseller.customers.index', ['link' => 'unlinked'])
            ->with($result['failed'] === [] ? 'success' : 'info', $message);
    }

    public function connectBilling(Request $request, Service $service)
    {
        $reseller = auth()->user();
        $this->ensureManagedService($reseller, $service);

        $validated = $request->validate([
            'reseller_product_id' => 'required|exists:reseller_products,id',
            'billing_cycle' => 'nullable|in:monthly,quarterly,semi-annual,annual',
            'custom_price' => 'nullable|numeric|min:0',
            'next_due_date' => 'nullable|date',
        ]);

        try {
            $this->linkService->connectBilling($reseller, $service, $validated);

            return back()->with('success', 'Catalog package connected for auto-billing.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function catalogOptions()
    {
        $listings = ResellerProduct::query()
            ->where('reseller_id', auth()->id())
            ->where('is_active', true)
            ->where('type', 'shared_hosting')
            ->orderBy('name')
            ->get(['id', 'name', 'direct_admin_package_name', 'monthly_price', 'yearly_price']);

        return response()->json([
            'listings' => $listings,
            'customers' => User::query()
                ->where('reseller_id', auth()->id())
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    private function ensureManagedService(User $reseller, Service $service): void
    {
        abort_unless(
            (int) $service->reseller_id === (int) $reseller->id
            || ($service->user && $this->scope->ownsCustomer($reseller, $service->user)),
            403,
        );
    }
}

<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Controller;
use App\Models\ResellerProduct;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerHostedAccountLinkService;
use App\Services\ResellerScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
            return $this->linkFailureRedirect($request, $e);
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

        $redirect = redirect()->route('reseller.customers.index', $this->customersIndexQuery($request));

        if ($result['failed'] === []) {
            return $redirect->with('success', "{$result['linked']} account(s) linked.");
        }

        if ($result['linked'] > 0) {
            return $redirect
                ->with('warning', "{$result['linked']} account(s) linked. ".count($result['failed']).' failed.')
                ->with('link_failures', $result['failed']);
        }

        return $redirect
            ->with('error', 'No accounts were linked.')
            ->with('link_failures', $result['failed']);
    }

    private function linkFailureRedirect(Request $request, \Throwable $e): RedirectResponse
    {
        return redirect()
            ->route('reseller.customers.index', $this->customersIndexQuery($request))
            ->withInput()
            ->with('error', $this->linkErrorMessage($e))
            ->with('open_da_link', $request->input('da_username'));
    }

    /**
     * @return array<string, mixed>
     */
    private function customersIndexQuery(Request $request): array
    {
        return array_filter([
            'link' => $request->input('link', 'unlinked'),
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'billing' => $request->input('billing'),
        ], fn ($value) => filled($value) && $value !== 'all');
    }

    private function linkErrorMessage(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->implode(' ');
        }

        return $e->getMessage();
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

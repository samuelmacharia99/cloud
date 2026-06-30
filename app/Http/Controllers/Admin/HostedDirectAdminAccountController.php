<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use App\Services\ResellerHostedAccountLinkService;
use Illuminate\Http\Request;

class HostedDirectAdminAccountController extends Controller
{
    public function __construct(
        private ResellerHostedAccountLinkService $linkService,
    ) {}

    public function link(Request $request)
    {
        $validated = $request->validate([
            'reseller_id' => 'required|exists:users,id',
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

        $reseller = User::query()->where('is_reseller', true)->findOrFail($validated['reseller_id']);

        try {
            $result = $this->linkService->linkAccount($reseller, $validated['da_username'], $validated);

            return redirect()
                ->route('admin.customers.show', $result['customer'])
                ->with('success', "DirectAdmin account {$validated['da_username']} linked for {$reseller->name}.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function connectBilling(Request $request, Service $service)
    {
        $validated = $request->validate([
            'reseller_id' => 'required|exists:users,id',
            'reseller_product_id' => 'required|exists:reseller_products,id',
            'billing_cycle' => 'nullable|in:monthly,quarterly,semi-annual,annual',
            'custom_price' => 'nullable|numeric|min:0',
            'next_due_date' => 'nullable|date',
        ]);

        $reseller = User::query()->where('is_reseller', true)->findOrFail($validated['reseller_id']);

        try {
            $this->linkService->connectBilling($reseller, $service, $validated);

            return back()->with('success', 'Catalog package connected for auto-billing.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

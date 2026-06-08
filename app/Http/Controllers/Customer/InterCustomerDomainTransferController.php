<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\ResellerDomainTransferService;
use Illuminate\Http\Request;

class InterCustomerDomainTransferController extends Controller
{
    public function show(string $token)
    {
        $domain = Domain::query()
            ->with(['user', 'pendingTransferRecipient'])
            ->where('transfer_token', $token)
            ->firstOrFail();

        abort_unless(auth()->check(), 401);
        abort_unless(
            auth()->id() === $domain->pending_transfer_to_user_id,
            403,
            'You are not authorized to review this transfer request.'
        );

        return view('customer.domains.inter-transfer-approval', compact('domain', 'token'));
    }

    public function approve(string $token, ResellerDomainTransferService $transferService)
    {
        $domain = Domain::where('transfer_token', $token)->firstOrFail();

        abort_unless(auth()->id() === $domain->pending_transfer_to_user_id, 403);

        try {
            $transferService->approve($token, auth()->user());

            return redirect()->route('customer.domains.index')
                ->with('success', "Domain {$domain->name}{$domain->extension} has been transferred to your account.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, string $token, ResellerDomainTransferService $transferService)
    {
        $domain = Domain::where('transfer_token', $token)->firstOrFail();

        abort_unless(auth()->id() === $domain->pending_transfer_to_user_id, 403);

        try {
            $transferService->reject($token, auth()->user());

            return redirect()->route('customer.domains.index')
                ->with('success', 'Domain transfer request rejected.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

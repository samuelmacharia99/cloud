<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustWalletBalanceRequest;
use App\Models\User;
use App\Services\AdminActivityService;
use App\Services\ResellerWalletService;
use Illuminate\Http\Request;

class ResellerWalletController extends Controller
{
    public function __construct(
        protected ResellerWalletService $walletService,
    ) {}

    public function index(Request $request)
    {
        $query = User::where('is_reseller', true)->with('wallet');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%");
        }

        $resellers = $query->latest()->paginate(20);

        return view('admin.reseller-wallets.index', compact('resellers'));
    }

    public function show(User $reseller)
    {
        abort_if(! $reseller->is_reseller, 404);

        $wallet = $this->walletService->getOrCreate($reseller);
        $transactions = $wallet->transactions()->latest()->paginate(15);
        $domainOrders = $reseller->domainOrders()->latest()->paginate(10);

        return view('admin.reseller-wallets.show', compact('reseller', 'wallet', 'transactions', 'domainOrders'));
    }

    public function adjust(AdjustWalletBalanceRequest $request, User $reseller)
    {
        abort_if(! $reseller->is_reseller, 404);

        $validated = $request->validated();

        try {
            $this->walletService->adjust(
                $reseller,
                $validated['amount'],
                $validated['reason'],
                auth()->user()
            );

            AdminActivityService::log(
                'reseller.wallet_adjust',
                "Adjusted wallet for {$reseller->name} by KES {$validated['amount']}",
                $reseller,
                [
                    'amount' => $validated['amount'],
                    'reason' => $validated['reason'],
                ],
            );

            return redirect()->route('admin.reseller-wallets.show', $reseller)
                ->with('success', "Wallet adjusted successfully! Amount: KES {$validated['amount']}");
        } catch (\Exception $e) {
            return redirect()->route('admin.reseller-wallets.show', $reseller)
                ->with('error', "Failed to adjust wallet: {$e->getMessage()}");
        }
    }

    public function exportPdf(User $reseller)
    {
        abort_if(! $reseller->is_reseller, 404);

        $wallet = $this->walletService->getOrCreate($reseller);
        $transactions = $wallet->transactions()->latest()->get();

        $pdf = \PDF::loadView('admin.reseller-wallets.pdf-statement', [
            'reseller' => $reseller,
            'wallet' => $wallet,
            'transactions' => $transactions,
        ]);

        return $pdf->download("wallet-statement-{$reseller->id}-".now()->format('Y-m-d').'.pdf');
    }
}

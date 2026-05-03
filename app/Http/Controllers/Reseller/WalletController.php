<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Requests\InitiateWalletTopupRequest;
use App\Models\Invoice;
use App\Services\ResellerWalletService;
use App\Services\PaymentGateway\MpesaService;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class WalletController extends Controller
{
    public function __construct(
        protected ResellerWalletService $walletService,
        protected MpesaService $mpesaService,
    ) {}

    public function index()
    {
        $reseller = auth()->user();
        $wallet = $this->walletService->getOrCreate($reseller);
        $recentTransactions = $wallet->transactions()->latest()->limit(5)->get();
        $queuedOrdersCount = $reseller->domainOrders()
            ->where('status', 'queued')
            ->where('expires_at', '>', now())
            ->count();

        return view('reseller.wallet.index', compact('wallet', 'recentTransactions', 'queuedOrdersCount'));
    }

    public function initiateTopup(InitiateWalletTopupRequest $request)
    {
        $reseller = auth()->user();
        $validated = $request->validated();

        // Create stub invoice for wallet top-up
        $topupInvoice = Invoice::create([
            'user_id' => $reseller->id,
            'status' => 'draft',
            'total' => $validated['amount'],
            'notes' => "Wallet top-up: {$validated['amount']} KES",
        ]);

        // Initiate M-Pesa STK push
        $result = $this->mpesaService->initiateTopup(
            $reseller,
            $validated['amount'],
            $validated['phone'],
            $topupInvoice
        );

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'checkout_request_id' => $result['checkout_request_id'],
                'invoice_id' => $topupInvoice->id,
                'message' => $result['message'],
            ]);
        }

        $topupInvoice->delete();

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }

    public function checkTopupStatus(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $payment = $invoice->payments()->where('payment_purpose', 'wallet_topup')->first();

        if (!$payment) {
            return response()->json(['status' => 'pending', 'message' => 'Payment not found']);
        }

        if ($payment->status === 'completed') {
            return response()->json(['status' => 'completed', 'message' => 'Payment successful']);
        }

        if ($payment->status === 'failed') {
            return response()->json(['status' => 'failed', 'message' => 'Payment failed']);
        }

        // Check payment status via M-Pesa
        $result = $this->mpesaService->verify($payment->transaction_reference);

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
        ]);
    }

    public function transactions(Request $request)
    {
        $reseller = auth()->user();
        $filters = [
            'type' => $request->input('type', 'all'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
        ];

        $transactions = $this->walletService->getTransactions($reseller, $filters, 20);

        return view('reseller.wallet.transactions', compact('transactions'));
    }

    public function exportPdf(Request $request)
    {
        $reseller = auth()->user();
        $filters = [
            'type' => $request->input('type', 'all'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
        ];

        $transactions = $this->walletService->getTransactions($reseller, $filters, 10000)->items();
        $wallet = $this->walletService->getOrCreate($reseller);

        $pdf = \PDF::loadView('reseller.wallet.pdf-statement', [
            'wallet' => $wallet,
            'transactions' => $transactions,
            'fromDate' => $filters['from_date'],
            'toDate' => $filters['to_date'],
        ]);

        return $pdf->download("wallet-statement-{$reseller->id}-" . now()->format('Y-m-d') . '.pdf');
    }
}

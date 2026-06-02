<?php

namespace App\Http\Controllers\Reseller;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Requests\InitiateWalletTopupRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentGateway\MpesaService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Http\Controllers\Controller;
use App\Services\ResellerWalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        protected ResellerWalletService $walletService,
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

    public function initiateTopup(Request $request)
    {
        $reseller = auth()->user();

        $validated = $request->validate([
            'amount' => 'required|numeric|min:5|max:50000',
            'payment_method' => 'required|string|in:mpesa,stripe,paypal',
            'phone' => 'required_if:payment_method,mpesa|nullable|string',
        ], [
            'amount.min' => 'Minimum top-up amount is KES 5',
            'amount.max' => 'Maximum top-up amount is KES 50,000',
        ]);

        try {
            // Create stub invoice for wallet top-up
            $topupInvoice = Invoice::create([
                'user_id' => $reseller->id,
                'invoice_number' => 'TOPUP-' . strtoupper(uniqid()),
                'status' => 'unpaid',
                'due_date' => now()->addDays(7),
                'subtotal' => $validated['amount'],
                'tax' => 0,
                'total' => $validated['amount'],
                'notes' => "Wallet top-up: {$validated['amount']} KES",
            ]);

            // Get payment gateway
            $gateway = PaymentGatewayFactory::make($validated['payment_method']);

            // Prepare customer data
            $customerData = [
                'phone' => $validated['phone'] ?? $reseller->phone,
                'email' => $reseller->email,
            ];

            // Initiate payment based on method
            if ($validated['payment_method'] === 'mpesa') {
                $mpesa = new MpesaService($reseller);
                $result = $mpesa->initiateTopup(
                    $reseller,
                    $validated['amount'],
                    $validated['phone'],
                    $topupInvoice
                );
            } else {
                // For other payment methods, use the gateway
                $result = $gateway->initiate($topupInvoice, $customerData);
            }

            if (!$result['success']) {
                $topupInvoice->delete();
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Payment initiation failed',
                ], 400);
            }

            $response = [
                'success' => true,
                'invoice_id' => $topupInvoice->id,
                'message' => $result['message'],
            ];

            // M-Pesa: return checkout request ID for STK push
            if ($validated['payment_method'] === 'mpesa') {
                $response['checkout_request_id'] = $result['checkout_request_id'];
                return response()->json($response);
            }

            // Stripe: return checkout URL
            if ($validated['payment_method'] === 'stripe') {
                $response['checkout_url'] = $result['checkout_url'];
                return response()->json($response);
            }

            // PayPal: return approval URL
            if ($validated['payment_method'] === 'paypal') {
                $response['approval_url'] = $result['approval_url'];
                return response()->json($response);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            \Log::error('Wallet topup initiation failed', [
                'reseller_id' => $reseller->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment initiation failed. Please try again.',
            ], 400);
        }
    }

    public function checkTopupStatus(Invoice $invoice)
    {
        abort_if($invoice->user_id !== auth()->id(), 403);

        $payment = $invoice->payments()->where('payment_purpose', 'wallet_topup')->first();

        if (!$payment) {
            return response()->json(['status' => 'pending', 'message' => 'Payment not found']);
        }

        if ($payment->status === PaymentStatus::Completed) {
            return response()->json(['status' => 'completed', 'message' => 'Payment successful']);
        }

        if ($payment->status === PaymentStatus::Failed) {
            return response()->json(['status' => 'failed', 'message' => 'Payment failed']);
        }

        // Check payment status via M-Pesa
        $mpesa = new MpesaService(auth()->user());
        $result = $mpesa->verify($payment->transaction_reference);

        // If M-Pesa says payment succeeded, update our records and credit the wallet
        if ($result['status'] === 'completed' && $payment->status !== PaymentStatus::Completed) {
            $payment->update(['status' => PaymentStatus::Completed->value, 'paid_at' => now()]);

            if ($payment->invoice) {
                $payment->invoice->update(['status' => InvoiceStatus::Paid->value]);
            }

            // Credit the wallet
            app('wallet-service')->processTopupPayment($payment);
        }

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

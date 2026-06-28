<?php

namespace App\Http\Controllers\Customer;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Credit;
use App\Models\Invoice;
use App\Services\CreditService;
use App\Services\CustomerCreditTopupService;
use App\Services\NotificationService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CreditController extends Controller
{
    public function __construct(
        protected CustomerCreditTopupService $topupService,
    ) {}

    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Credit::forUser($user)->with('payment', 'invoice')->latest();

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $credits = $query->paginate(15)->withQueryString();

        return view('customer.credits.index', [
            'credits' => $credits,
            'availableBalance' => CreditService::getAvailableBalance($user),
            'activeCredits' => CreditService::getActiveCredits($user),
            'availableGateways' => PaymentGatewayFactory::getAvailableGatewaysForUser($user),
        ]);
    }

    public function initiateTopup(Request $request)
    {
        $customer = auth()->user();

        $availableMethods = array_keys(PaymentGatewayFactory::getAvailableGatewaysForUser($customer));

        $validated = $request->validate([
            'amount' => 'required|numeric|min:5|max:50000',
            'payment_method' => ['required', 'string', Rule::in($availableMethods)],
            'phone' => 'required_if:payment_method,mpesa|nullable|string',
        ], [
            'amount.min' => 'Minimum top-up amount is KES 5',
            'amount.max' => 'Maximum top-up amount is KES 50,000',
        ]);

        try {
            if ($validated['payment_method'] === 'mpesa') {
                $mpesa = PaymentGatewayFactory::makeMpesaForUser($customer);
                $existing = $mpesa->findReusablePendingTopup(
                    $customer,
                    'credit_topup',
                    (float) $validated['amount'],
                    (string) ($validated['phone'] ?? $customer->phone),
                );

                if ($existing?->invoice_id) {
                    return response()->json([
                        'success' => true,
                        'invoice_id' => $existing->invoice_id,
                        'checkout_request_id' => $existing->transaction_reference,
                        'message' => 'An M-Pesa prompt is already on your phone. Enter your PIN to complete payment.',
                        'reused_session' => true,
                    ]);
                }
            }

            $topupInvoice = $this->topupService->createTopupInvoice($customer, (float) $validated['amount']);

            $customerData = [
                'phone' => $validated['phone'] ?? $customer->phone,
                'email' => $customer->email,
            ];

            if ($validated['payment_method'] === 'mpesa') {
                $mpesa = PaymentGatewayFactory::makeMpesaForUser($customer);
                $result = $mpesa->initiateTopup(
                    $customer,
                    (float) $validated['amount'],
                    $validated['phone'],
                    $topupInvoice,
                    'credit_topup'
                );
            } else {
                $gateway = PaymentGatewayFactory::make($validated['payment_method']);
                $result = $gateway->initiate($topupInvoice, $customerData);

                if ($result['success']) {
                    $payment = $topupInvoice->payments()->latest()->first();
                    if ($payment) {
                        $this->topupService->markPendingPaymentPurpose($payment);
                    }
                }
            }

            if (! $result['success']) {
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

            if ($validated['payment_method'] === 'mpesa') {
                $response['checkout_request_id'] = $result['checkout_request_id'];

                return response()->json($response);
            }

            if ($validated['payment_method'] === 'stripe') {
                $response['checkout_url'] = $result['checkout_url'];

                return response()->json($response);
            }

            if ($validated['payment_method'] === 'paypal') {
                $response['approval_url'] = $result['approval_url'];

                return response()->json($response);
            }

            return response()->json($response);
        } catch (\Exception $e) {
            \Log::error('Credit top-up initiation failed', [
                'user_id' => $customer->id,
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

        $payment = $invoice->payments()->where('payment_purpose', 'credit_topup')->first();

        if (! $payment) {
            return response()->json(['status' => 'pending', 'message' => 'Payment not found']);
        }

        if ($payment->status === PaymentStatus::Completed) {
            return response()->json(['status' => 'completed', 'message' => 'Payment successful']);
        }

        if ($payment->status === PaymentStatus::Failed) {
            $notes = json_decode((string) $payment->notes, true) ?: [];

            return response()->json([
                'status' => 'failed',
                'message' => $notes['result_desc'] ?? 'Payment failed',
            ]);
        }

        $mpesa = PaymentGatewayFactory::makeMpesaForPayment($payment);
        $result = $mpesa->verify($payment->transaction_reference);

        if ($result['status'] === 'completed' && $payment->status !== PaymentStatus::Completed) {
            $payment->update(['status' => PaymentStatus::Completed->value, 'paid_at' => now()]);

            if ($payment->invoice) {
                $payment->invoice->update(['status' => InvoiceStatus::Paid->value]);
            }

            $this->topupService->processTopupPayment($payment);
        }

        if ($result['status'] === 'failed' && $payment->status !== PaymentStatus::Failed) {
            $payment->update([
                'status' => PaymentStatus::Failed->value,
                'notes' => json_encode([
                    'result_desc' => $result['message'] ?? 'Payment failed',
                    'result_code' => $result['response_code'] ?? null,
                ]),
            ]);

            app(NotificationService::class)->notifyPaymentFailed(
                $payment->fresh(['invoice.user']),
                $result['message'] ?? 'Payment failed.',
            );
        }

        return response()->json([
            'status' => $result['status'],
            'message' => $result['message'],
        ]);
    }
}

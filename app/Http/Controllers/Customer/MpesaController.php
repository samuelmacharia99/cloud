<?php

namespace App\Http\Controllers\Customer;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\MpesaService;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class MpesaController extends Controller
{
    public function __construct(private MpesaService $mpesaService)
    {
    }

    public function initiate(Request $request, Invoice $invoice): JsonResponse
    {
        // Verify ownership
        abort_if($invoice->user_id !== auth()->id(), 403);

        // Verify not already paid
        abort_if($invoice->status->value === 'paid', 422);

        // Validate phone number (Kenyan format)
        $validated = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(\+?254|0)[17]\d{8}$/'],
        ], [
            'phone.regex' => 'Please enter a valid Kenyan phone number (e.g., 0712345678 or +254712345678)',
        ]);

        $amount = $invoice->getAmountRemaining();

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This invoice has no outstanding balance.',
            ], 422);
        }

        $result = $this->mpesaService->stkPush($invoice, $validated['phone'], $amount);

        if ($result['success']) {
            // Create pending payment record
            Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'status' => PaymentStatus::Pending,
                'payment_method' => PaymentMethod::Mpesa,
                'transaction_reference' => $result['checkoutRequestId'],
                'notes' => 'STK Push initiated. Phone: ' . $validated['phone'],
            ]);

            return response()->json([
                'success' => true,
                'message' => $result['message'],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'],
        ], 422);
    }

    public function callback(Request $request)
    {
        try {
            $this->mpesaService->processCallback($request->all());
        } catch (\Exception $e) {
            Log::error('M-Pesa callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Always return 200 to Safaricom
        return response('', 200);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Setting;
use App\Services\DomainPushService;
use App\Services\NotificationService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\ResellerInvoicePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function mpesaCallback(Request $request)
    {
        if (! $this->isValidMpesaCallback($request)) {
            Log::warning('M-Pesa callback rejected: invalid callback token', [
                'ip' => $request->ip(),
            ]);

            return response('', 403);
        }

        try {
            $gateway = PaymentGatewayFactory::make('mpesa');
            $result = $gateway->handleCallback($request->all());

            if ($result['success'] && isset($result['payment_id'])) {
                $payment = Payment::with('invoice.user')->find($result['payment_id']);

                if ($payment && $payment->invoice) {
                    $this->handleCompletedInvoicePayment($payment);
                }
            }

            return response('', 200);
        } catch (\Exception $e) {
            Log::error('M-Pesa callback error', ['error' => $e->getMessage()]);

            return response('', 200);
        }
    }

    private function isValidMpesaCallback(Request $request): bool
    {
        $token = Setting::getValue('mpesa_callback_token', '');

        if ($token === '') {
            if (Setting::getValue('mpesa_environment', 'sandbox') === 'production') {
                Log::error('M-Pesa callback token is not configured in production');

                return false;
            }

            return true;
        }

        return hash_equals($token, (string) $request->query('token', ''));
    }

    private function handleCompletedInvoicePayment(Payment $payment): void
    {
        $invoice = $payment->invoice;
        $user = $invoice->user;

        if ($user->is_reseller) {
            $this->handleResellerInvoicePayment($payment);

            return;
        }

        $this->provisionCustomerServices($payment);

        if ($user->reseller_id !== null) {
            $this->processResellerCustomerDomainOrders($invoice);
        }
    }

    private function handleResellerInvoicePayment(Payment $payment): void
    {
        try {
            $invoice = $payment->invoice;
            $invoicePaymentService = app(ResellerInvoicePaymentService::class);

            $invoicePaymentService->completeInvoiceIfFullyPaid($invoice, $payment);
            app(NotificationService::class)->notifyPaymentReceived($payment);
            app(DomainPushService::class)->handlePaidResellerInvoice($invoice->fresh(['items']));

            Log::info('Reseller invoice payment handled via M-Pesa callback', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Reseller invoice payment completion failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processResellerCustomerDomainOrders($invoice): void
    {
        try {
            app('domain-push-service')->handlePaidDomainInvoice($invoice);
        } catch (\Exception $e) {
            Log::error('Reseller customer domain order processing failed from webhook', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function provisionCustomerServices(Payment $payment): void
    {
        try {
            app(InvoiceProvisioningService::class)
                ->provisionPendingServicesForInvoice($payment->invoice);
        } catch (\Exception $e) {
            Log::error('Customer service provisioning failed from webhook', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

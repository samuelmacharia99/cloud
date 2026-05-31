<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Services\DomainPushService;
use App\Services\PaymentGateway\PaymentGatewayFactory;
use App\Services\Provisioning\InvoiceProvisioningService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Handle M-Pesa callback for both customers and resellers
     */
    public function mpesaCallback(Request $request)
    {
        try {
            $gateway = PaymentGatewayFactory::make('mpesa');
            $result = $gateway->handleCallback($request->all());

            // If payment was successful, handle completion based on payment type
            if ($result['success'] && isset($result['payment_id'])) {
                $payment = Payment::find($result['payment_id']);
                if ($payment && $payment->invoice) {
                    // Check if this is a reseller payment (domain registration)
                    if ($this->isResellerDomainPayment($payment->invoice)) {
                        $this->handleResellerDomainPayment($payment);
                    } else {
                        // Customer payment - provision services
                        $this->provisionCustomerServices($payment);
                    }
                }
            }

            return response('', 200);
        } catch (\Exception $e) {
            Log::error('M-Pesa callback error', ['error' => $e->getMessage()]);

            return response('', 200);
        }
    }

    /**
     * Check if payment is for reseller domain order
     */
    private function isResellerDomainPayment($invoice): bool
    {
        if ($invoice->type !== 'service' && $invoice->type !== null) {
            return false;
        }

        foreach ($invoice->items as $item) {
            if ($item->product_type === 'Domain') {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle reseller domain payment completion
     */
    private function handleResellerDomainPayment(Payment $payment)
    {
        try {
            $invoice = $payment->invoice;

            // Push all queued domain orders linked to this invoice
            foreach ($invoice->items as $item) {
                if ($item->product_type === 'Domain' && isset($item->custom_options['domain_order_id'])) {
                    $domainOrderId = $item->custom_options['domain_order_id'];
                    $order = ResellerDomainOrder::find($domainOrderId);

                    if ($order && $order->status === 'queued') {
                        $domainPushService = app(DomainPushService::class);
                        $domainPushService->pushOrderWithDirectPayment($order);

                        Log::info('Reseller domain order pushed via M-Pesa callback', [
                            'payment_id' => $payment->id,
                            'domain_order_id' => $order->id,
                            'domain_name' => $order->domain_name,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Reseller domain payment completion failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle customer payment completion - provision services
     */
    private function provisionCustomerServices(Payment $payment)
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

<?php

namespace Database\Seeders;

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Seeder;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $paymentMethods = ['mpesa', 'mpesa', 'mpesa', 'mpesa', 'mpesa', 'mpesa', 'card', 'card', 'bank_transfer', 'bank_transfer'];
        $methodIndex = 0;
        // Get all paid invoices
        $paidInvoices = Invoice::where('status', 'paid')->get();
        $refIndex = 10000;

        foreach ($paidInvoices as $invoiceIndex => $invoice) {
            // Create 1-2 payments per paid invoice
            $paymentCount = ($invoiceIndex % 2) + 1;
            $remainingAmount = $invoice->total;

            for ($i = 0; $i < $paymentCount; $i++) {
                // If last payment, pay remaining amount; otherwise split
                if ($i === $paymentCount - 1) {
                    $amount = $remainingAmount;
                } else {
                    $amount = round(($remainingAmount * 0.3) + (($invoiceIndex + $i) % 1000) / 1000 * ($remainingAmount * 0.4), 2);
                    $remainingAmount -= $amount;
                }

                if ($amount <= 0) {
                    continue;
                }

                $paymentMethod = $paymentMethods[($methodIndex++) % count($paymentMethods)];

                $ref = 'TXN' . now()->format('YmdHis') . str_pad($refIndex++, 5, '0', STR_PAD_LEFT);
                Payment::create([
                    'user_id' => $invoice->user_id,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'currency' => 'KES',
                    'payment_method' => $paymentMethod,
                    'transaction_reference' => $ref,
                    'status' => 'completed',
                    'paid_at' => $invoice->paid_date ?? now()->subDays(($invoiceIndex % 30) + 1),
                    'notes' => 'Payment for ' . $invoice->invoice_number,
                ]);
            }
        }

        // Create some standalone payments (not linked to invoices)
        $customers = User::where('is_admin', false)
            ->where('is_reseller', false)
            ->get();

        $standalonePaymentStatuses = ['completed', 'pending', 'failed'];

        foreach ($customers as $customerId => $customer) {
            // 50% chance of standalone payment
            if ($customerId % 2 === 0) {
                $paymentMethod = $paymentMethods[($methodIndex++) % count($paymentMethods)];
                $status = $standalonePaymentStatuses[$customerId % count($standalonePaymentStatuses)];

                $ref = 'TXN' . now()->format('YmdHis') . str_pad($refIndex++, 5, '0', STR_PAD_LEFT);
                Payment::create([
                    'user_id' => $customer->id,
                    'invoice_id' => null,
                    'amount' => (50 + ($customerId % 450)),
                    'currency' => 'KES',
                    'payment_method' => $paymentMethod,
                    'transaction_reference' => $ref,
                    'status' => $status,
                    'paid_at' => $status === 'completed' ? now()->subDays(($customerId % 30) + 1) : null,
                    'notes' => 'Standalone payment / deposit',
                ]);
            }
        }
    }
}

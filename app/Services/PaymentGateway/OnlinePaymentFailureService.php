<?php

namespace App\Services\PaymentGateway;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\NotificationService;

class OnlinePaymentFailureService
{
    public function __construct(
        protected NotificationService $notificationService,
    ) {}

    public function recordAndNotify(
        Invoice $invoice,
        string $paymentMethod,
        string $reason,
        ?string $transactionReference = null,
    ): void {
        $payment = $this->resolvePendingPayment($invoice, $paymentMethod, $transactionReference);

        if ($payment === null) {
            return;
        }

        $this->markFailedAndNotify($payment, $reason);
    }

    public function recordAndNotifyByReference(string $transactionReference, string $reason): void
    {
        $payment = Payment::query()
            ->where('transaction_reference', $transactionReference)
            ->first();

        if ($payment === null || ! $this->canMarkFailed($payment)) {
            return;
        }

        $this->markFailedAndNotify($payment, $reason);
    }

    private function resolvePendingPayment(
        Invoice $invoice,
        string $paymentMethod,
        ?string $transactionReference,
    ): ?Payment {
        if ($transactionReference !== null) {
            $payment = Payment::query()
                ->where('transaction_reference', $transactionReference)
                ->first();

            if ($payment !== null) {
                if ((int) $payment->invoice_id !== (int) $invoice->id) {
                    return null;
                }

                return $this->canMarkFailed($payment) ? $payment : null;
            }
        }

        $payment = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('payment_method', $paymentMethod)
            ->where('status', PaymentStatus::Pending)
            ->latest()
            ->first();

        return $payment !== null && $this->canMarkFailed($payment) ? $payment : null;
    }

    private function canMarkFailed(Payment $payment): bool
    {
        return ! $payment->isCompleted() && ! $payment->isFailed();
    }

    private function markFailedAndNotify(Payment $payment, string $reason): void
    {
        $existingNotes = json_decode($payment->notes ?? '{}', true) ?: [];

        $payment->update([
            'status' => PaymentStatus::Failed,
            'notes' => json_encode(array_merge($existingNotes, [
                'failure_reason' => $reason,
                'failed_at' => now()->toIso8601String(),
            ])),
        ]);

        $this->notificationService->notifyPaymentFailed(
            $payment->fresh(['invoice.user']),
            $reason,
        );
    }
}

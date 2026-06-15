<?php

namespace App\Services\Telegram;

use App\Enums\PaymentMethod;
use App\Enums\TelegramMonitorCategory;
use App\Models\CronJob;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;

class TelegramMonitorBridge
{
    public function __construct(
        private TelegramMonitorService $monitor,
    ) {}

    public function paymentReceived(Payment $payment): void
    {
        $payment->loadMissing('invoice.user', 'user');

        $invoice = $payment->invoice;
        $customer = $invoice?->user ?? $payment->user;

        $method = $payment->payment_method instanceof PaymentMethod
            ? $payment->payment_method->label()
            : ucfirst(str_replace('_', ' ', (string) $payment->payment_method));

        $this->monitor->alert(
            TelegramMonitorCategory::Payments,
            'Payment received',
            array_merge($this->monitor->userContext($customer), [
                'Amount' => 'KES '.number_format((float) $payment->amount, 2),
                'Method' => $method,
                'Invoice' => $invoice?->invoice_number ?? '—',
                'Reference' => $payment->transaction_reference ?: '—',
                'Payment ID' => (string) $payment->id,
            ]),
        );
    }

    public function paymentFailed(Payment $payment, string $reason): void
    {
        $payment->loadMissing('invoice.user', 'user');
        $customer = $payment->invoice?->user ?? $payment->user;

        $this->monitor->alert(
            TelegramMonitorCategory::Payments,
            'Payment failed',
            array_merge($this->monitor->userContext($customer), [
                'Amount' => 'KES '.number_format((float) $payment->amount, 2),
                'Invoice' => $payment->invoice?->invoice_number ?? '—',
                'Reason' => Str::limit($reason, 500),
            ]),
        );
    }

    public function manualPaymentSubmitted(Payment $payment): void
    {
        $payment->loadMissing('invoice.user');
        $this->monitor->alert(
            TelegramMonitorCategory::Payments,
            'Manual payment submitted (pending approval)',
            array_merge($this->monitor->userContext($payment->invoice?->user), [
                'Amount' => 'KES '.number_format((float) $payment->amount, 2),
                'Invoice' => $payment->invoice?->invoice_number ?? '—',
            ]),
        );
    }

    public function manualPaymentRejected(Payment $payment, string $reason): void
    {
        $payment->loadMissing('invoice.user');
        $this->monitor->alert(
            TelegramMonitorCategory::Payments,
            'Manual payment rejected',
            array_merge($this->monitor->userContext($payment->invoice?->user), [
                'Amount' => 'KES '.number_format((float) $payment->amount, 2),
                'Invoice' => $payment->invoice?->invoice_number ?? '—',
                'Reason' => Str::limit($reason, 500),
            ]),
        );
    }

    public function invoiceGenerated(Invoice $invoice): void
    {
        $invoice->loadMissing('user');
        $this->monitor->alert(
            TelegramMonitorCategory::Payments,
            'Invoice generated',
            array_merge($this->monitor->userContext($invoice->user), [
                'Invoice' => $invoice->invoice_number,
                'Total' => 'KES '.number_format((float) $invoice->total, 2),
                'Status' => is_object($invoice->status) ? $invoice->status->value : (string) $invoice->status,
                'Due date' => $invoice->due_date?->format('Y-m-d') ?? '—',
            ]),
        );
    }

    public function serviceLifecycle(Service $service, string $action, ?string $reason = null): void
    {
        $service->loadMissing('user', 'product');

        $fields = array_merge($this->monitor->userContext($service->user), [
            'Service' => $service->name,
            'Service ID' => (string) $service->id,
            'Product' => $service->product?->name ?? '—',
            'Status' => is_object($service->status) ? $service->status->value : (string) $service->status,
        ]);

        if ($reason) {
            $fields['Reason'] = Str::limit($reason, 500);
        }

        if ($service->service_meta['suspension_reason'] ?? null) {
            $fields['Suspension reason'] = (string) $service->service_meta['suspension_reason'];
        }

        $this->monitor->alert(
            TelegramMonitorCategory::Services,
            'Service '.$action,
            $fields,
        );
    }

    public function orderPlaced(Order $order, Invoice $invoice, string $paymentMethod = 'unknown'): void
    {
        $order->loadMissing('user', 'items');
        $invoice->loadMissing('user');

        $this->monitor->alert(
            TelegramMonitorCategory::Orders,
            'New order placed',
            array_merge($this->monitor->userContext($order->user ?? $invoice->user), [
                'Order ID' => (string) $order->id,
                'Invoice' => $invoice->invoice_number,
                'Total' => 'KES '.number_format((float) $invoice->total, 2),
                'Items' => (string) $order->items->count(),
                'Payment method' => $paymentMethod,
            ]),
        );
    }

    public function userRegistered(User $user): void
    {
        $this->monitor->alert(
            TelegramMonitorCategory::Registrations,
            'New account registered',
            array_merge($this->monitor->userContext($user), [
                'Country' => $user->country ?? '—',
                'Company' => $user->company ?: '—',
                'Reseller owned' => $user->reseller_id ? 'Yes (#'.$user->reseller_id.')' : 'No',
            ]),
        );
    }

    public function ticketEvent(Ticket $ticket, string $action): void
    {
        $ticket->loadMissing('user');

        $this->monitor->alert(
            TelegramMonitorCategory::Tickets,
            'Support ticket '.$action,
            array_merge($this->monitor->userContext($ticket->user), [
                'Ticket' => '#'.$ticket->id,
                'Subject' => Str::limit($ticket->title, 120),
                'Priority' => (string) $ticket->priority,
                'Status' => (string) $ticket->status,
            ]),
        );
    }

    public function resellerEvent(User $reseller, string $title, array $extra = []): void
    {
        $this->monitor->alert(
            TelegramMonitorCategory::Resellers,
            $title,
            array_merge([
                'Reseller' => $reseller->name,
                'Email' => $reseller->email,
                'Reseller ID' => (string) $reseller->id,
            ], $extra),
        );
    }

    public function cronJobRun(
        CronJob $job,
        string $status,
        string $trigger,
        ?string $output = null,
        ?string $error = null,
        ?int $durationMs = null,
    ): void {
        $title = $status === 'failed' ? 'Cron job failed' : 'Cron job completed';

        $fields = [
            'Job' => $job->name,
            'Command' => $job->command,
            'Status' => $status,
            'Trigger' => $trigger === 'manual' ? 'Manual (admin panel)' : 'Scheduled',
        ];

        if ($durationMs !== null) {
            $fields['Duration'] = $durationMs.' ms';
        }

        if ($output) {
            $fields['Output'] = Str::limit($output, 800);
        }

        if ($error) {
            $fields['Error'] = Str::limit($error, 800);
        }

        if ($trigger === 'manual' && auth()->check()) {
            $fields['Run by'] = auth()->user()->name;
        }

        $this->monitor->alert(TelegramMonitorCategory::System, $title, $fields);
    }

    public function systemAlert(string $title, array $fields = []): void
    {
        $this->monitor->alert(
            TelegramMonitorCategory::System,
            $title,
            $fields,
        );
    }

    public function logError(string $level, string $message, ?string $context = null): void
    {
        $fields = [
            'Level' => strtoupper($level),
            'Message' => Str::limit($message, 1200),
        ];

        if ($context) {
            $fields['Context'] = Str::limit($context, 800);
        }

        $this->monitor->alert(
            TelegramMonitorCategory::Errors,
            'Application log alert',
            $fields,
        );
    }

    public function resellerDomainOrder(ResellerDomainOrder $order, string $stage, string $paymentMethod = 'awaiting payment'): void
    {
        $order->loadMissing('reseller', 'customer');

        $this->monitor->alert(
            TelegramMonitorCategory::Resellers,
            'Reseller domain order '.$stage,
            [
                'Domain' => $order->fullDomainName(),
                'Reseller' => $order->reseller?->name ?? '—',
                'Customer' => $order->customer?->name ?? '—',
                'Wholesale' => 'KES '.number_format((float) $order->wholesale_amount, 2),
                'Retail' => 'KES '.number_format((float) $order->retail_amount, 2),
                'Payment' => $paymentMethod,
                'Order ID' => (string) $order->id,
            ],
        );
    }

    public function walletTopup(User $reseller, float $amount, float $balanceAfter, string $source = 'top-up'): void
    {
        $this->monitor->alert(
            TelegramMonitorCategory::Resellers,
            'Reseller wallet '.$source,
            [
                'Reseller' => $reseller->name,
                'Email' => $reseller->email,
                'Amount' => 'KES '.number_format($amount, 2),
                'New balance' => 'KES '.number_format($balanceAfter, 2),
            ],
        );
    }

    public function walletAdjustment(User $reseller, float $signedAmount, float $balanceAfter, ?string $reason = null): void
    {
        $action = $signedAmount >= 0 ? 'credited' : 'debited';

        $fields = [
            'Reseller' => $reseller->name,
            'Email' => $reseller->email,
            'Action' => ucfirst($action),
            'Amount' => 'KES '.number_format(abs($signedAmount), 2),
            'New balance' => 'KES '.number_format($balanceAfter, 2),
        ];

        if ($reason) {
            $fields['Reason'] = Str::limit($reason, 500);
        }

        $this->monitor->alert(
            TelegramMonitorCategory::Resellers,
            'Reseller wallet adjustment',
            $fields,
        );
    }

    public function walletLowBalance(User $reseller, string $formattedBalance): void
    {
        $this->monitor->alert(
            TelegramMonitorCategory::Resellers,
            'Reseller wallet low balance',
            [
                'Reseller' => $reseller->name,
                'Email' => $reseller->email,
                'Balance' => $formattedBalance,
            ],
        );
    }
}

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
        $order->loadMissing('user', 'items.product');
        $invoice->loadMissing('user', 'items');

        $customer = $order->user ?? $invoice->user;
        $itemSummary = $invoice->items
            ->take(3)
            ->map(fn ($item) => $item->description ?: ($item->product?->name ?? 'Item'))
            ->implode('; ');

        if ($invoice->items->count() > 3) {
            $itemSummary .= ' (+'.($invoice->items->count() - 3).' more)';
        }

        $isResellerCustomer = (bool) $customer?->reseller_id;
        $reseller = $isResellerCustomer ? $customer->reseller()->first() : null;

        $summary = $isResellerCustomer
            ? "A customer of reseller {$reseller?->name} placed an order. The reseller manages billing and provisioning — no admin SMS was sent."
            : 'A direct platform customer placed an order. Review payment and provision services as needed.';

        $nextStep = match (true) {
            $paymentMethod === 'manual' => 'Customer will submit bank transfer proof. Approve manual payment when received.',
            $paymentMethod === 'awaiting payment' => 'Waiting for customer payment before provisioning.',
            default => 'Payment is being processed. Service will provision automatically when payment completes.',
        };

        $this->monitor->alert(
            TelegramMonitorCategory::Orders,
            $isResellerCustomer ? 'Reseller customer order placed' : 'Platform customer order placed',
            array_merge($this->monitor->userContext($customer), [
                'Order' => $order->order_number,
                'Invoice' => $invoice->invoice_number,
                'Total' => 'KES '.number_format((float) $invoice->total, 2),
                'Payment' => ucfirst(str_replace('_', ' ', $paymentMethod)),
                'Items' => $itemSummary ?: (string) $order->items->count().' item(s)',
                'Summary' => $summary,
                'Next step' => $nextStep,
            ]),
        );
    }

    public function resellerCustomerOrderPlaced(
        User $reseller,
        User $customer,
        Invoice $invoice,
        string $summary,
        string $paymentMethod = 'awaiting payment',
    ): void {
        $this->monitor->alert(
            TelegramMonitorCategory::Resellers,
            'Reseller placed order for customer',
            [
                'Reseller' => $reseller->name,
                'Reseller email' => $reseller->email,
                'Customer' => $customer->name,
                'Customer email' => $customer->email,
                'Invoice' => $invoice->invoice_number,
                'Total' => 'KES '.number_format((float) $invoice->total, 2),
                'Service' => Str::limit($summary, 200),
                'Payment' => ucfirst(str_replace('_', ' ', $paymentMethod)),
                'Summary' => 'The reseller created or billed a service for their customer. The reseller handles customer support and billing — admin action is usually not required.',
                'Next step' => $paymentMethod === 'paid'
                    ? 'Reseller has paid or marked paid. Provisioning runs under the reseller account.'
                    : 'Awaiting reseller or customer payment on invoice '.$invoice->invoice_number.'.',
            ],
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
        $order->loadMissing('reseller', 'customer', 'domain');

        $paymentLabel = ucfirst(str_replace('_', ' ', $paymentMethod));
        $typeLabel = $order->isTransfer() ? 'Transfer' : 'Registration';
        $years = $order->years.' year(s)';

        if ($order->isPlatformOrder()) {
            [$summary, $nextStep] = $this->platformDomainOrderContext($order, $stage, $paymentLabel);

            $this->monitor->alert(
                TelegramMonitorCategory::Orders,
                'Platform domain order: '.$this->domainOrderStageLabel($stage),
                [
                    'Domain' => $order->fullDomainName(),
                    'Type' => $typeLabel,
                    'Period' => $years,
                    'Customer' => $order->customer?->name ?? '—',
                    'Customer email' => $order->customer?->email ?? '—',
                    'Amount' => 'KES '.number_format($order->displayAmount(), 2),
                    'Payment' => $paymentLabel,
                    'Order status' => $order->statusDisplayLabel(),
                    'Order ID' => (string) $order->id,
                    'Summary' => $summary,
                    'Next step' => $nextStep,
                ],
            );

            return;
        }

        [$summary, $nextStep] = $this->resellerDomainOrderContext($order, $stage, $paymentLabel);

        $this->monitor->alert(
            TelegramMonitorCategory::Resellers,
            'Reseller domain order: '.$this->domainOrderStageLabel($stage),
            [
                'Domain' => $order->fullDomainName(),
                'Type' => $typeLabel,
                'Period' => $years,
                'Reseller' => $order->reseller?->name ?? '—',
                'Reseller email' => $order->reseller?->email ?? '—',
                'Customer' => $order->customer?->name ?? '—',
                'Wholesale' => 'KES '.number_format((float) $order->wholesale_amount, 2),
                'Retail' => 'KES '.number_format((float) $order->retail_amount, 2),
                'Payment' => $paymentLabel,
                'Order status' => ucfirst($order->status),
                'Order ID' => (string) $order->id,
                'Summary' => $summary,
                'Next step' => $nextStep,
            ],
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function platformDomainOrderContext(ResellerDomainOrder $order, string $stage, string $paymentLabel): array
    {
        return match ($stage) {
            'placed' => [
                'A platform customer started a domain '.($order->order_type?->value ?? 'registration').' for '.$order->fullDomainName().'.',
                $paymentLabel === 'Awaiting payment'
                    ? 'Customer must pay their invoice. Registration at Openprovider runs automatically after payment.'
                    : 'Payment received — registering at Openprovider automatically.',
            ],
            'customer_paid', 'pushed' => [
                'Customer paid Talksasa directly for '.$order->fullDomainName().'. No reseller wallet is involved.',
                $order->hasPendingRegistrarSubmission()
                    ? 'Submitted to Openprovider — awaiting registry activation (no admin action needed).'
                    : 'Registering at Openprovider automatically. Admin action is only needed if this fails.',
            ],
            'provisioned', 'completed' => [
                'Domain '.$order->fullDomainName().' was registered successfully for platform customer '.$order->customer?->name.'.',
                'No further action unless the customer reports DNS or transfer issues.',
            ],
            'failed' => [
                'Registrar rejected or failed registration for '.$order->fullDomainName().'.',
                'Top up Openprovider balance or fix settings, then retry Push to registrar in Admin → Domain orders.',
            ],
            default => [
                'Platform domain order update for '.$order->fullDomainName().' (stage: '.$stage.').',
                'Review the order in Admin → Domain orders.',
            ],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resellerDomainOrderContext(ResellerDomainOrder $order, string $stage, string $paymentLabel): array
    {
        if ($order->isSelfOrder()) {
            return match ($stage) {
                'placed' => [
                    'Reseller '.$order->reseller?->name.' ordered '.$order->fullDomainName().' for their own account.',
                    $paymentLabel === 'Awaiting payment'
                        ? 'Reseller must pay wholesale — Openprovider registration runs automatically after payment.'
                        : 'Payment received — registering at Openprovider automatically.',
                ],
                'pushed' => [
                    'Reseller paid wholesale for '.$order->fullDomainName().'.',
                    $order->hasPendingRegistrarSubmission()
                        ? 'Submitted to Openprovider — awaiting registry activation (no admin action needed).'
                        : 'Registering at Openprovider automatically. Admin action is only needed if this fails.',
                ],
                default => [
                    'Reseller self-order update for '.$order->fullDomainName().'.',
                    'Review in Admin → Domain orders.',
                ],
            };
        }

        return match ($stage) {
            'placed' => [
                'Customer '.$order->customer?->name.' (under reseller '.$order->reseller?->name.') placed a domain order. Admin SMS is not sent for reseller customer orders.',
                'Reseller collects retail payment, pays wholesale, then pushes to admin when funded.',
            ],
            'customer_paid' => [
                'Reseller customer paid for '.$order->fullDomainName().'. The reseller must fund wholesale and push the order.',
                'No admin action until the reseller pushes — watch for a pushed notification.',
            ],
            'pushed' => [
                'Reseller '.$order->reseller?->name.' paid wholesale for '.$order->fullDomainName().' (customer '.$order->customer?->name.').',
                $order->hasPendingRegistrarSubmission()
                    ? 'Submitted to Openprovider — awaiting registry activation (no admin action needed).'
                    : 'Registering at Openprovider automatically. Admin action is only needed if this fails.',
            ],
            'provisioned', 'completed' => [
                'Domain '.$order->fullDomainName().' completed for reseller customer '.$order->customer?->name.'.',
                'Reseller handles customer communication. No admin action required.',
            ],
            'failed' => [
                'Registrar failed for '.$order->fullDomainName().' (reseller '.$order->reseller?->name.').',
                'Top up Openprovider balance or fix settings, then retry Push to registrar in Admin → Domain orders.',
            ],
            default => [
                'Reseller domain order update for '.$order->fullDomainName().'.',
                'Review in Admin → Domain orders.',
            ],
        };
    }

    private function domainOrderStageLabel(string $stage): string
    {
        return match ($stage) {
            'placed' => 'placed',
            'customer_paid' => 'customer paid',
            'pushed' => 'ready for registrar',
            'provisioned', 'completed' => 'completed',
            'failed' => 'failed',
            default => $stage,
        };
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

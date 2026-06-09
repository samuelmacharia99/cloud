<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'event_key',
        'name',
        'subject',
        'body',
        'recipient_type',
        'description',
        'available_variables',
        'enabled',
    ];

    protected $casts = [
        'available_variables' => 'array',
        'enabled' => 'boolean',
    ];

    public function renderSubject(array $data = []): string
    {
        return $this->replacePlaceholders($this->subject, $data);
    }

    public function renderBody(array $data = []): string
    {
        return $this->replacePlaceholders($this->body, $data);
    }

    protected function replacePlaceholders(string $text, array $data): string
    {
        foreach ($data as $key => $value) {
            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }

    public static function forEvent(string $eventKey): ?self
    {
        return static::where('event_key', $eventKey)->where('enabled', true)->first();
    }

    public static function defaultTemplates(): array
    {
        return [
            [
                'event_key' => 'new_order',
                'name' => 'New Order',
                'subject' => 'Order Confirmation - {order_number}',
                'body' => "Hi {customer_name},\n\nThank you for your order #{order_number}.\n\nTotal: {amount}\n\nWe will notify you when payment is confirmed.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when a customer places a new order',
                'available_variables' => ['customer_name', 'order_number', 'amount', 'site_name'],
            ],
            [
                'event_key' => 'payment_received',
                'name' => 'Payment Received',
                'subject' => 'Payment Received - Invoice {invoice_number}',
                'body' => "Hi {customer_name},\n\nWe received your payment of {amount} for invoice {invoice_number}.\n\nThank you!\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when a payment is confirmed',
                'available_variables' => ['customer_name', 'amount', 'invoice_number', 'site_name'],
            ],
            [
                'event_key' => 'invoice_generated',
                'name' => 'Invoice Generated',
                'subject' => 'Invoice {invoice_number} Generated',
                'body' => "Hi {customer_name},\n\nInvoice {invoice_number} for {amount} is ready.\nDue date: {due_date}\n\n— {site_name}",
                'recipient_type' => 'customer',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'due_date', 'site_name'],
            ],
            [
                'event_key' => 'invoice_reminder',
                'name' => 'Invoice Reminder',
                'subject' => 'Payment Reminder - Invoice {invoice_number}',
                'body' => "Reminder: Invoice {invoice_number} for {amount} is due in {days_before} day(s).\n\n— {site_name}",
                'recipient_type' => 'customer',
                'available_variables' => ['invoice_number', 'amount', 'days_before', 'due_date', 'site_name'],
            ],
            [
                'event_key' => 'invoice_overdue',
                'name' => 'Invoice Overdue',
                'subject' => 'URGENT: Invoice {invoice_number} is Overdue',
                'body' => "Hi {customer_name},\n\nInvoice {invoice_number} for {amount} is now overdue. Please pay immediately to avoid service interruption.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'site_name'],
            ],
            [
                'event_key' => 'service_suspended',
                'name' => 'Service Suspended',
                'subject' => 'Service Suspended - {service_name}',
                'body' => "Hi {customer_name},\n\nYour service \"{service_name}\" has been suspended due to non-payment.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'service_unsuspended',
                'name' => 'Service Restored',
                'subject' => 'Service Restored - {service_name}',
                'body' => "Hi {customer_name},\n\nYour service \"{service_name}\" has been restored.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'reseller_new_customer_order',
                'name' => 'Reseller: New Customer Order',
                'subject' => 'New customer order - {domain_name}',
                'body' => "Hi {reseller_name},\n\nYour customer {customer_name} paid for {domain_name}.\n\nWholesale cost: {wholesale_amount}\n\nTop up your wallet if needed: {wallet_url}\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'available_variables' => ['reseller_name', 'customer_name', 'domain_name', 'wholesale_amount', 'wallet_url', 'site_name'],
            ],
            [
                'event_key' => 'reseller_domain_queued',
                'name' => 'Reseller: Domain Queued',
                'subject' => 'Domain order queued - top up wallet',
                'body' => "Hi {reseller_name},\n\nDomain {domain_name} for {customer_name} is queued. Required wallet balance: {wholesale_amount}.\n\nTop up: {wallet_url}\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'available_variables' => ['reseller_name', 'customer_name', 'domain_name', 'wholesale_amount', 'wallet_url', 'site_name'],
            ],
            [
                'event_key' => 'reseller_domain_pushed',
                'name' => 'Reseller: Domain Pushed',
                'subject' => 'Domain pushed to admin - {domain_name}',
                'body' => "Hi {reseller_name},\n\nDomain {domain_name} for {customer_name} has been pushed to admin for registration.\n\nAmount debited: {wholesale_amount}\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'available_variables' => ['reseller_name', 'customer_name', 'domain_name', 'wholesale_amount', 'site_name'],
            ],
            [
                'event_key' => 'reseller_wallet_low',
                'name' => 'Reseller: Low Wallet Balance',
                'subject' => 'Wallet balance is low',
                'body' => "Hi {reseller_name},\n\nYour wallet balance is {balance}. Top up to process pending domain orders.\n\n{wallet_url}\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'available_variables' => ['reseller_name', 'balance', 'wallet_url', 'site_name'],
            ],
            [
                'event_key' => 'reseller_wallet_topup',
                'name' => 'Reseller: Wallet Top-up',
                'subject' => 'Wallet top-up confirmed',
                'body' => "Hi {reseller_name},\n\nYour wallet was credited {amount}. New balance: {balance}.\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'available_variables' => ['reseller_name', 'amount', 'balance', 'site_name'],
            ],
            [
                'event_key' => 'reseller_wallet_adjustment',
                'name' => 'Reseller: Wallet Manual Adjustment',
                'subject' => 'Wallet balance updated — {adjustment_type}',
                'body' => "Hi {reseller_name},\n\nYour wallet was updated by an administrator.\n\nType: {adjustment_type}\nAmount: {amount}\nPrevious balance: {previous_balance}\nNew balance: {new_balance}\nReason: {reason}\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'available_variables' => ['reseller_name', 'amount', 'previous_balance', 'new_balance', 'adjustment_type', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'admin_new_order',
                'name' => 'Admin: New Order',
                'subject' => 'New order #{order_number}',
                'body' => "New order #{order_number} from {customer_name}.\nPayment method: {payment_method}\nAmount: {amount}",
                'recipient_type' => 'admin',
                'available_variables' => ['order_number', 'customer_name', 'payment_method', 'amount'],
            ],
            [
                'event_key' => 'manual_payment_submitted',
                'name' => 'Admin: Manual Payment Submitted',
                'subject' => 'Manual payment submitted - Invoice {invoice_number}',
                'body' => "Customer {customer_name} submitted manual payment proof for invoice {invoice_number}.\nAmount: {amount}\n\nReview in admin panel.",
                'recipient_type' => 'admin',
                'available_variables' => ['customer_name', 'invoice_number', 'amount'],
            ],
            [
                'event_key' => 'admin_reseller_domain_push',
                'name' => 'Admin: Reseller Domain Push',
                'subject' => 'Reseller domain order - {domain_name}',
                'body' => "Reseller {reseller_name} pushed domain {domain_name} for customer {customer_name}.\nWholesale: {wholesale_amount}",
                'recipient_type' => 'admin',
                'available_variables' => ['reseller_name', 'customer_name', 'domain_name', 'wholesale_amount'],
            ],
            [
                'event_key' => 'payment_failed',
                'name' => 'Payment Failed',
                'subject' => 'Payment failed — Invoice {invoice_number}',
                'body' => "Hi {customer_name},\n\nYour payment for invoice {invoice_number} ({amount}) could not be completed.\n\nReason: {reason}\n\nPlease retry from your dashboard.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when an online payment fails',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'manual_payment_rejected',
                'name' => 'Manual Payment Rejected',
                'subject' => 'Manual payment rejected — Invoice {invoice_number}',
                'body' => "Hi {customer_name},\n\nYour manual payment submission for invoice {invoice_number} ({amount}) was rejected.\n\nReason: {rejection_reason}\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when admin rejects a manual payment proof',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'rejection_reason', 'site_name'],
            ],
            [
                'event_key' => 'service_provision_failed',
                'name' => 'Service Provision Failed',
                'subject' => 'Service setup failed — {service_name}',
                'body' => "Hi {customer_name},\n\nWe could not automatically set up your service \"{service_name}\".\n\nReason: {reason}\n\nOur team has been notified.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when auto-provisioning fails',
                'available_variables' => ['customer_name', 'service_name', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'password_changed',
                'name' => 'Password Changed',
                'subject' => 'Password changed — {site_name}',
                'body' => "Hi {customer_name},\n\nYour account password was changed successfully.\n\nIf you did not make this change, contact support immediately.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when a user changes their password',
                'available_variables' => ['customer_name', 'site_name'],
            ],
            [
                'event_key' => 'reseller_suspended',
                'name' => 'Reseller: Account Suspended',
                'subject' => 'Reseller account suspended — {site_name}',
                'body' => "Hi {reseller_name},\n\nYour reseller account has been suspended.\n\nReason: {reason}\n\nPay your package subscription invoice to restore access.\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'description' => 'Sent when a reseller is suspended for overdue billing',
                'available_variables' => ['reseller_name', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'reseller_disk_pool_warning',
                'name' => 'Reseller: Disk Pool Warning',
                'subject' => 'Disk pool usage exceeded',
                'body' => "Hi {reseller_name},\n\nYour disk pool usage ({used_gb} GB) exceeds your allocated pool ({pool_gb} GB).\n\nReview customer usage or upgrade your package.\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'description' => 'Sent when reseller disk pool is over limit',
                'available_variables' => ['reseller_name', 'used_gb', 'pool_gb', 'site_name'],
            ],
            [
                'event_key' => 'reseller_domain_order_expired',
                'name' => 'Reseller: Domain Order Expired',
                'subject' => 'Queued domain orders expired',
                'body' => "Hi {reseller_name},\n\n{count} queued domain order(s) expired because they were not processed in time.\n\nTop up your wallet and re-order if needed.\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'description' => 'Sent when queued domain orders expire',
                'available_variables' => ['reseller_name', 'count', 'site_name'],
            ],
            [
                'event_key' => 'reseller_ssl_provision_failed',
                'name' => 'Reseller: SSL Provisioning Failed',
                'subject' => 'SSL provisioning failed — {domain}',
                'body' => "Hi {reseller_name},\n\nSSL certificate provisioning failed for {domain}.\n\nReason: {reason}\n\nCheck DNS settings and retry from reseller settings.\n\n— {site_name}",
                'recipient_type' => 'reseller',
                'description' => 'Sent when custom domain SSL provisioning fails',
                'available_variables' => ['reseller_name', 'domain', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'domain_transfer_completed',
                'name' => 'Domain Transfer Completed',
                'subject' => 'Domain transfer completed — {domain_name}',
                'body' => "Hi {customer_name},\n\nYour domain transfer for {domain_name} has completed successfully.\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when a domain transfer completes',
                'available_variables' => ['customer_name', 'domain_name', 'site_name'],
            ],
            [
                'event_key' => 'domain_transfer_failed',
                'name' => 'Domain Transfer Failed',
                'subject' => 'Domain transfer failed — {domain_name}',
                'body' => "Hi {customer_name},\n\nYour domain transfer for {domain_name} could not be completed.\n\nReason: {reason}\n\n— {site_name}",
                'recipient_type' => 'customer',
                'description' => 'Sent when a domain transfer fails',
                'available_variables' => ['customer_name', 'domain_name', 'reason', 'site_name'],
            ],
        ];
    }
}

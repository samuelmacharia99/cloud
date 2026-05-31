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
        ];
    }
}

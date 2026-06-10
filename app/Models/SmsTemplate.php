<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_key',
        'name',
        'body',
        'recipient_type',
        'description',
        'available_variables',
    ];

    protected $casts = [
        'available_variables' => 'array',
    ];

    public function scopeForEvent($query, string $eventKey)
    {
        return $query->where('event_key', $eventKey)->first();
    }

    public function render(array $data = []): string
    {
        $body = $this->body;

        foreach ($data as $key => $value) {
            $body = str_replace('{'.$key.'}', (string) $value, $body);
        }

        return $body;
    }

    public static function defaultTemplates(): array
    {
        return [
            [
                'event_key' => 'new_order',
                'name' => 'New Order',
                'body' => 'Hi {customer_name}, your order #{order_id} for {amount} has been received. Thank you! -{site_name}',
                'recipient_type' => 'both',
                'description' => 'Sent when a customer places a new order',
                'available_variables' => ['customer_name', 'order_id', 'amount', 'site_name'],
            ],
            [
                'event_key' => 'admin_new_order',
                'name' => 'Admin: New Order',
                'body' => 'ALERT: New order #{order_number} from {customer_name}. Payment: {payment_method}. Amount: {amount}.',
                'recipient_type' => 'admin',
                'description' => 'SMS alert to admins when any order is placed',
                'available_variables' => ['order_number', 'customer_name', 'payment_method', 'amount'],
            ],
            [
                'event_key' => 'payment_received',
                'name' => 'Payment Received',
                'body' => 'Hi {customer_name}, we received your payment of {amount} for invoice #{invoice_number}. Thank you! -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when a payment is confirmed',
                'available_variables' => ['customer_name', 'amount', 'invoice_number', 'site_name'],
            ],
            [
                'event_key' => 'invoice_generated',
                'name' => 'Invoice Generated',
                'body' => 'Hi {customer_name}, invoice #{invoice_number} for {amount} is ready. Due date: {due_date}. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when a new invoice is created',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'due_date', 'site_name'],
            ],
            [
                'event_key' => 'invoice_reminder',
                'name' => 'Invoice Reminder',
                'body' => 'Reminder: Invoice #{invoice_number} for {amount} is due in {days_before} day(s). -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent as a reminder before invoice due date',
                'available_variables' => ['invoice_number', 'amount', 'days_before', 'due_date', 'site_name'],
            ],
            [
                'event_key' => 'invoice_overdue',
                'name' => 'Invoice Overdue',
                'body' => 'Hi {customer_name}, invoice #{invoice_number} for {amount} is now overdue. Please settle immediately. -{site_name}',
                'recipient_type' => 'both',
                'description' => 'Sent when an invoice becomes overdue',
                'available_variables' => ['customer_name', 'invoice_number', 'amount', 'site_name'],
            ],
            [
                'event_key' => 'service_activated',
                'name' => 'Service Activated',
                'body' => 'Hi {customer_name}, your {service_name} service is now active and ready to use. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when a service is activated',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'service_suspended',
                'name' => 'Service Suspended',
                'body' => 'Alert: Your {service_name} service has been suspended due to non-payment. Please settle outstanding invoices. -{site_name}',
                'recipient_type' => 'both',
                'description' => 'Sent when a service is suspended',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'service_terminated',
                'name' => 'Service Terminated',
                'body' => 'Your {service_name} service has been terminated. For details, contact support. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when a service is terminated',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'domain_expiry',
                'name' => 'Domain Expiry',
                'body' => 'Reminder: Your domain {domain_name} expires in {days_until_expiry} day(s). Renew now to avoid service interruption. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent before a domain expires',
                'available_variables' => ['customer_name', 'domain_name', 'days_until_expiry', 'site_name'],
            ],
            [
                'event_key' => 'ticket_created',
                'name' => 'Support Ticket Created',
                'body' => 'Hi {customer_name}, we received your support ticket #{ticket_id}: "{subject}". Our team will respond shortly. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when a support ticket is created',
                'available_variables' => ['customer_name', 'ticket_id', 'subject', 'site_name'],
            ],
            [
                'event_key' => 'payment_failed',
                'name' => 'Payment Failed',
                'body' => 'Payment for invoice {invoice_number} ({amount}) failed. Please retry from your dashboard. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when an online payment fails',
                'available_variables' => ['invoice_number', 'amount', 'site_name'],
            ],
            [
                'event_key' => 'manual_payment_rejected',
                'name' => 'Manual Payment Rejected',
                'body' => 'Your manual payment for invoice {invoice_number} was rejected. Reason: {rejection_reason}. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when admin rejects manual payment proof',
                'available_variables' => ['invoice_number', 'rejection_reason', 'site_name'],
            ],
            [
                'event_key' => 'service_provision_failed',
                'name' => 'Service Provision Failed',
                'body' => 'Setup failed for {service_name}. Our team has been notified. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when auto-provisioning fails',
                'available_variables' => ['service_name', 'site_name'],
            ],
            [
                'event_key' => 'service_unsuspended',
                'name' => 'Service Restored',
                'body' => 'Hi {customer_name}, your {service_name} service has been restored. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when a suspended service is restored',
                'available_variables' => ['customer_name', 'service_name', 'site_name'],
            ],
            [
                'event_key' => 'reseller_suspended',
                'name' => 'Reseller: Account Suspended',
                'body' => 'Your reseller account has been suspended. Reason: {reason}. Pay your package invoice to restore access. -{site_name}',
                'recipient_type' => 'reseller',
                'description' => 'Sent when reseller is suspended',
                'available_variables' => ['reason', 'site_name'],
            ],
            [
                'event_key' => 'reseller_disk_pool_warning',
                'name' => 'Reseller: Disk Pool Warning',
                'body' => 'Disk pool usage ({used_gb} GB) exceeds your allocation ({pool_gb} GB). Review usage or upgrade. -{site_name}',
                'recipient_type' => 'reseller',
                'description' => 'Sent when disk pool is over limit',
                'available_variables' => ['used_gb', 'pool_gb', 'site_name'],
            ],
            [
                'event_key' => 'reseller_domain_order_expired',
                'name' => 'Reseller: Domain Order Expired',
                'body' => '{count} queued domain order(s) expired. Top up wallet and re-order if needed. -{site_name}',
                'recipient_type' => 'reseller',
                'description' => 'Sent when queued domain orders expire',
                'available_variables' => ['count', 'site_name'],
            ],
            [
                'event_key' => 'reseller_ssl_provision_failed',
                'name' => 'Reseller: SSL Failed',
                'body' => 'SSL provisioning failed for {domain}. {reason} -{site_name}',
                'recipient_type' => 'reseller',
                'description' => 'Sent when SSL provisioning fails',
                'available_variables' => ['domain', 'reason', 'site_name'],
            ],
            [
                'event_key' => 'domain_transfer_completed',
                'name' => 'Domain Transfer Completed',
                'body' => 'Domain transfer completed: {domain_name} is now active on your account. -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when domain transfer completes',
                'available_variables' => ['domain_name', 'site_name'],
            ],
            [
                'event_key' => 'domain_transfer_failed',
                'name' => 'Domain Transfer Failed',
                'body' => 'Domain transfer failed for {domain_name}. {reason} -{site_name}',
                'recipient_type' => 'customer',
                'description' => 'Sent when domain transfer fails',
                'available_variables' => ['domain_name', 'reason', 'site_name'],
            ],
        ];
    }
}

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
            $body = str_replace('{' . $key . '}', (string) $value, $body);
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
        ];
    }
}

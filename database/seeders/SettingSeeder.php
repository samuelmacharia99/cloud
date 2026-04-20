<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // General settings
            ['key' => 'site_name', 'value' => 'Talksasa Cloud', 'description' => 'Application name'],
            ['key' => 'site_url', 'value' => 'https://talksasa.cloud', 'description' => 'Application URL'],
            ['key' => 'site_email', 'value' => 'noreply@talksasa.cloud', 'description' => 'No-reply email address'],
            ['key' => 'support_email', 'value' => 'support@talksasa.cloud', 'description' => 'Support email address'],
            ['key' => 'timezone', 'value' => 'Africa/Nairobi', 'description' => 'Default timezone'],
            ['key' => 'date_format', 'value' => 'M d, Y', 'description' => 'Date display format'],
            ['key' => 'currency', 'value' => 'KES', 'description' => 'Default currency code'],
            ['key' => 'currency_symbol', 'value' => 'Ksh', 'description' => 'Currency symbol for display'],

            // Billing settings
            ['key' => 'billing_company', 'value' => 'Talksasa Cloud Limited', 'description' => 'Company name on invoices'],
            ['key' => 'billing_address', 'value' => '123 Tech Street', 'description' => 'Company billing address'],
            ['key' => 'billing_city', 'value' => 'Nairobi', 'description' => 'Company city'],
            ['key' => 'billing_country', 'value' => 'Kenya', 'description' => 'Company country'],
            ['key' => 'billing_vat_number', 'value' => 'P051234567Y', 'description' => 'VAT registration number'],
            ['key' => 'invoice_prefix', 'value' => 'INV', 'description' => 'Invoice number prefix'],
            ['key' => 'invoice_due_days', 'value' => '30', 'description' => 'Days until invoice is due'],
            ['key' => 'grace_period_days', 'value' => '5', 'description' => 'Grace period after due date'],

            // Tax settings
            ['key' => 'tax_enabled', 'value' => 'true', 'description' => 'Enable tax calculations'],
            ['key' => 'tax_rate', 'value' => '16', 'description' => 'Tax rate percentage (VAT)'],
            ['key' => 'tax_name', 'value' => 'VAT', 'description' => 'Tax name/label'],
            ['key' => 'tax_inclusive', 'value' => 'false', 'description' => 'Tax inclusive or exclusive'],
            ['key' => 'tax_number', 'value' => 'P051234567Y', 'description' => 'Tax registration number'],

            // Payment method settings
            ['key' => 'mpesa_enabled', 'value' => '1', 'description' => 'Enable M-Pesa payments'],
            ['key' => 'mpesa_shortcode', 'value' => '123456', 'description' => 'M-Pesa merchant shortcode'],
            ['key' => 'mpesa_passkey', 'value' => 'bfb279f9aa9bdbcf158e97dd1a2c6f6d', 'description' => 'M-Pesa API passkey'],
            ['key' => 'mpesa_consumer_key', 'value' => '', 'description' => 'M-Pesa Daraja API consumer key'],
            ['key' => 'mpesa_consumer_secret', 'value' => '', 'description' => 'M-Pesa Daraja API consumer secret'],
            ['key' => 'mpesa_environment', 'value' => 'sandbox', 'description' => 'M-Pesa API environment (sandbox or production)'],
            ['key' => 'card_enabled', 'value' => 'true', 'description' => 'Enable card payments'],
            ['key' => 'stripe_key', 'value' => 'sk_test_', 'description' => 'Stripe secret key'],
            ['key' => 'bank_transfer_enabled', 'value' => 'true', 'description' => 'Enable bank transfer payments'],
            ['key' => 'bank_name', 'value' => 'Kenya Commercial Bank', 'description' => 'Bank name'],
            ['key' => 'bank_account_name', 'value' => 'Talksasa Cloud Limited', 'description' => 'Bank account name'],
            ['key' => 'bank_account_number', 'value' => '1234567890', 'description' => 'Bank account number'],
            ['key' => 'bank_branch', 'value' => '', 'description' => 'Bank branch name'],
            ['key' => 'bank_swift_code', 'value' => '', 'description' => 'Bank SWIFT/BIC code'],
            ['key' => 'manual_enabled', 'value' => 'true', 'description' => 'Enable manual payment entry'],

            // Provisioning settings
            ['key' => 'provisioning_mode', 'value' => 'automatic', 'description' => 'Provisioning mode (automatic/manual)'],
            ['key' => 'auto_provision', 'value' => 'true', 'description' => 'Auto-provision services'],
            ['key' => 'suspend_on_overdue', 'value' => 'true', 'description' => 'Suspend services when overdue'],
            ['key' => 'terminate_after_days', 'value' => '30', 'description' => 'Days before terminating suspended service'],

            // Cron settings
            ['key' => 'cron_timezone', 'value' => 'Africa/Nairobi', 'description' => 'Timezone for cron job scheduling'],
            ['key' => 'cron_retention_days', 'value' => '30', 'description' => 'Days to retain cron logs and monitoring data'],
            ['key' => 'max_execution_time', 'value' => '120', 'description' => 'Maximum cron job execution time in seconds'],

            // Branding settings
            ['key' => 'logo_url', 'value' => '/images/logo.png', 'description' => 'Logo image URL'],
            ['key' => 'favicon_url', 'value' => '/images/favicon.ico', 'description' => 'Favicon URL'],
            ['key' => 'primary_color', 'value' => '#2563eb', 'description' => 'Primary brand color'],
            ['key' => 'company_name', 'value' => 'Talksasa Cloud', 'description' => 'Company display name'],
            ['key' => 'footer_text', 'value' => '© 2026 Talksasa Cloud Limited. All rights reserved.', 'description' => 'Footer text'],

            // Email settings
            ['key' => 'smtp_host', 'value' => 'smtp.mailtrap.io', 'description' => 'SMTP server host'],
            ['key' => 'smtp_port', 'value' => '2525', 'description' => 'SMTP server port'],
            ['key' => 'smtp_user', 'value' => 'user@mailtrap.io', 'description' => 'SMTP username'],
            ['key' => 'smtp_password', 'value' => 'password', 'description' => 'SMTP password'],
            ['key' => 'mail_from_name', 'value' => 'Talksasa Cloud', 'description' => 'Email from name'],
            ['key' => 'mail_from_address', 'value' => 'noreply@talksasa.cloud', 'description' => 'Email from address'],

            // Notification settings
            ['key' => 'notify_new_order', 'value' => 'true', 'description' => 'Notify on new orders'],
            ['key' => 'notify_payment', 'value' => 'true', 'description' => 'Notify on payments received'],
            ['key' => 'notify_service_suspend', 'value' => 'true', 'description' => 'Notify on service suspension'],
            ['key' => 'notify_ticket', 'value' => 'true', 'description' => 'Notify on new support tickets'],
            ['key' => 'notify_invoice_generated', 'value' => 'true', 'description' => 'Notify on invoice generation'],
            ['key' => 'notify_invoice_reminder', 'value' => 'true', 'description' => 'Notify with invoice payment reminders'],
            ['key' => 'notify_invoice_overdue', 'value' => 'true', 'description' => 'Notify when invoice becomes overdue'],
            ['key' => 'notify_service_activated', 'value' => 'true', 'description' => 'Notify when service is activated'],
            ['key' => 'notify_service_terminated', 'value' => 'true', 'description' => 'Notify when service is terminated'],
            ['key' => 'notify_domain_expiry', 'value' => 'true', 'description' => 'Notify on domain expiry warnings'],

            // SMS settings
            ['key' => 'sms_enabled', 'value' => 'false', 'description' => 'Enable SMS notifications'],
            ['key' => 'sms_api_token', 'value' => '', 'description' => 'Talksasa SMS API Bearer token'],
            ['key' => 'sms_sender_id', 'value' => 'TalksasaCloud', 'description' => 'SMS sender ID (max 11 chars)'],

            // DirectAdmin settings
            ['key' => 'directadmin_api_url', 'value' => '', 'description' => 'DirectAdmin API URL (e.g., https://da.example.com:2222)'],
            ['key' => 'directadmin_api_user', 'value' => 'admin', 'description' => 'DirectAdmin admin username'],
            ['key' => 'directadmin_api_password', 'value' => '', 'description' => 'DirectAdmin admin password'],
            ['key' => 'directadmin_default_package', 'value' => 'default', 'description' => 'Default DirectAdmin package for hosting accounts'],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'], 'description' => $setting['description']]
            );
        }
    }
}

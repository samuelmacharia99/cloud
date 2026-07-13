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
            ['key' => 'site_url', 'value' => 'https://servers.talksasa.com', 'description' => 'Application URL'],
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
            ['key' => 'service_monthly_invoice_advance_days', 'value' => '10', 'description' => 'Generate monthly service renewal invoices this many days before next due date'],
            ['key' => 'service_renewal_invoice_advance_days', 'value' => '30', 'description' => 'Generate quarterly/semi-annual/annual service renewal invoices this many days before next due date'],
            ['key' => 'domain_renewal_advance_days', 'value' => '30', 'description' => 'Generate domain renewal invoices this many days before expiry'],
            ['key' => 'domain_renewal_payment_days', 'value' => '10', 'description' => 'Days customer has to pay a domain renewal invoice'],
            ['key' => 'domain_renewal_years', 'value' => '1', 'description' => 'Default years for automated domain renewal invoices'],
            ['key' => 'reseller_package_invoice_advance_days', 'value' => '10', 'description' => 'Generate reseller package renewal invoices this many days before package expiry'],

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
            ['key' => 'mpesa_callback_token', 'value' => '', 'description' => 'Secret token for M-Pesa callback URL (required in production)'],
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
            ['key' => 'reseller_auto_provision_hosting', 'value' => 'true', 'description' => 'Auto-provision shared and container hosting for reseller customers when their invoice is paid'],
            ['key' => 'suspend_on_overdue', 'value' => 'true', 'description' => 'Suspend services when overdue'],
            ['key' => 'terminate_after_unpaid_months', 'value' => '4', 'description' => 'Months an invoice can remain unpaid before service termination'],
            ['key' => 'reseller_suspend_on_overdue', 'value' => 'true', 'description' => 'Suspend resellers when package subscription is overdue or expired'],
            ['key' => 'reseller_cascade_suspend_on_overdue', 'value' => 'true', 'description' => 'Cascade suspend managed services when reseller is suspended'],
            ['key' => 'reseller_suspend_excess_services', 'value' => 'true', 'description' => 'Suspend services beyond reseller package service slot limit'],
            ['key' => 'reseller_disk_overage_rate', 'value' => '50', 'description' => 'Default KES per GB/month charged to resellers over their disk pool'],
            ['key' => 'reseller_enforce_limits_on_provision', 'value' => 'true', 'description' => 'Block provisioning when reseller is at limit or suspended'],
            ['key' => 'reseller_suspend_on_disk_pool_overquota', 'value' => 'true', 'description' => 'Automatically suspend resellers when total managed disk usage exceeds package pool'],
            ['key' => 'reseller_suspend_on_user_overquota', 'value' => 'true', 'description' => 'Automatically suspend resellers when hosted user count exceeds package max_users'],
            ['key' => 'suspend_on_disk_overquota', 'value' => 'true', 'description' => 'Suspend DirectAdmin hosting when disk quota is exceeded (legacy toggle; see suspend_on_package_overquota)'],
            ['key' => 'suspend_on_package_overquota', 'value' => 'true', 'description' => 'Automatically suspend shared hosting when disk, bandwidth, or database limits are exceeded'],
            ['key' => 'disk_overquota_threshold_percent', 'value' => '100', 'description' => 'Disk usage percentage of quota before auto-suspension (100 = at limit)'],
            ['key' => 'package_overquota_threshold_percent', 'value' => '100', 'description' => 'Shared hosting usage percentage before auto-suspension for disk, bandwidth, and databases'],
            ['key' => 'hosting_package_usage_warning_percent', 'value' => '90', 'description' => 'Shared hosting usage percentage that triggers upgrade warnings (storage, bandwidth, databases)'],
            ['key' => 'hosting_package_usage_clear_percent', 'value' => '85', 'description' => 'Usage percentage below which upgrade warnings reset after an upgrade or usage drop'],

            // Cron settings
            ['key' => 'cron_timezone', 'value' => 'Africa/Nairobi', 'description' => 'Timezone for cron job scheduling'],
            ['key' => 'cron_retention_days', 'value' => '30', 'description' => 'Days to retain cron logs and monitoring data'],
            ['key' => 'max_execution_time', 'value' => '120', 'description' => 'Maximum cron job execution time in seconds'],

            // Branding settings
            ['key' => 'logo_url', 'value' => '', 'description' => 'Logo image URL (upload via Admin → Settings)'],
            ['key' => 'favicon_url', 'value' => '', 'description' => 'Favicon URL (upload via Admin → Settings)'],
            ['key' => 'primary_color', 'value' => '#2563eb', 'description' => 'Primary brand color'],
            ['key' => 'company_name', 'value' => 'Talksasa Cloud', 'description' => 'Company display name'],
            ['key' => 'footer_text', 'value' => '© 2026 Talksasa Cloud Limited. All rights reserved.', 'description' => 'Footer text'],

            // Email settings
            ['key' => 'smtp_host', 'value' => 'smtp.mailtrap.io', 'description' => 'SMTP server host'],
            ['key' => 'smtp_port', 'value' => '2525', 'description' => 'SMTP server port'],
            ['key' => 'smtp_encryption', 'value' => 'tls', 'description' => 'SMTP encryption (tls, ssl, or empty)'],
            ['key' => 'smtp_user', 'value' => 'user@mailtrap.io', 'description' => 'SMTP username'],
            ['key' => 'smtp_password', 'value' => 'password', 'description' => 'SMTP password'],
            ['key' => 'mail_from_name', 'value' => 'Talksasa Cloud', 'description' => 'Email from name'],
            ['key' => 'mail_from_address', 'value' => 'noreply@talksasa.cloud', 'description' => 'Email from address'],

            // Notification settings
            ['key' => 'notify_new_order', 'value' => 'true', 'description' => 'Notify on new orders'],
            ['key' => 'notify_payment', 'value' => 'true', 'description' => 'Notify on payments received'],
            ['key' => 'notify_service_suspend', 'value' => 'true', 'description' => 'Notify on service suspension'],
            ['key' => 'notify_ticket', 'value' => 'true', 'description' => 'Master toggle for support ticket notifications'],
            ['key' => 'notify_ticket_platform', 'value' => 'true', 'description' => 'Notify admins for platform tickets and escalations'],
            ['key' => 'notify_ticket_reseller', 'value' => 'true', 'description' => 'Notify resellers when their customers open or reply to tickets'],
            ['key' => 'notify_invoice_generated', 'value' => 'true', 'description' => 'Notify on invoice generation'],
            ['key' => 'notify_invoice_reminder', 'value' => 'true', 'description' => 'Notify with invoice payment reminders'],
            ['key' => 'notify_invoice_overdue', 'value' => 'true', 'description' => 'Notify when invoice becomes overdue'],
            ['key' => 'notify_service_activated', 'value' => 'true', 'description' => 'Notify when service is activated'],
            ['key' => 'notify_service_terminated', 'value' => 'true', 'description' => 'Notify when service is terminated'],
            ['key' => 'notify_domain_expiry', 'value' => 'true', 'description' => 'Notify on domain expiry warnings'],
            ['key' => 'notify_service_unsuspended', 'value' => 'true', 'description' => 'Notify when service is restored'],
            ['key' => 'notify_hosting_package_usage_warning', 'value' => 'true', 'description' => 'Notify customers when hosting usage reaches the upgrade warning threshold'],
            ['key' => 'notify_hosting_upgrade_completed', 'value' => 'true', 'description' => 'Notify customers when a paid hosting upgrade is applied'],
            ['key' => 'notify_customer_account_transferred', 'value' => 'true', 'description' => 'Email customers when their account is reassigned to a reseller partner'],
            ['key' => 'notify_reseller_customer_assigned', 'value' => 'true', 'description' => 'Email resellers when a customer account is assigned to them'],
            ['key' => 'notify_container_backup', 'value' => 'true', 'description' => 'Email on container backup completion (no SMS)'],
            ['key' => 'notify_container_backup_failure', 'value' => 'true', 'description' => 'Email admins on container backup failure (no SMS)'],
            ['key' => 'notify_container_failure', 'value' => 'true', 'description' => 'Notify on container failure'],
            ['key' => 'notify_container_restart', 'value' => 'true', 'description' => 'Notify on container auto-restart'],
            ['key' => 'backup_storage_driver', 'value' => 'node', 'description' => 'Where container backups are stored: node or hetzner'],
            ['key' => 'hetzner_storage_host', 'value' => '', 'description' => 'Hetzner Storage Box hostname'],
            ['key' => 'hetzner_storage_port', 'value' => '23', 'description' => 'Hetzner Storage Box SFTP port'],
            ['key' => 'hetzner_storage_username', 'value' => '', 'description' => 'Hetzner Storage Box username'],
            ['key' => 'hetzner_storage_password', 'value' => '', 'description' => 'Hetzner Storage Box password (encrypted at rest)'],
            ['key' => 'hetzner_storage_path', 'value' => '/backups/containers', 'description' => 'Remote base path on the Storage Box'],
            ['key' => 'notify_reseller_domain_queued', 'value' => 'true', 'description' => 'Notify reseller when domain order is queued'],
            ['key' => 'notify_reseller_domain_pushed', 'value' => 'true', 'description' => 'Notify reseller when domain is pushed to admin'],
            ['key' => 'notify_domain_renewal_completed', 'value' => 'true', 'description' => 'Notify reseller when admin manually completes a domain renewal'],
            ['key' => 'notify_reseller_new_customer_order', 'value' => 'true', 'description' => 'Notify reseller on new customer domain order'],
            ['key' => 'notify_reseller_wallet_low', 'value' => 'true', 'description' => 'Notify reseller on low wallet balance'],
            ['key' => 'notify_reseller_wallet_topup', 'value' => 'true', 'description' => 'Notify reseller on wallet top-up'],
            ['key' => 'notify_reseller_wallet_adjustment', 'value' => 'true', 'description' => 'Notify reseller on admin manual wallet adjustment'],
            ['key' => 'reseller_auto_pay_subscription_from_wallet', 'value' => 'true', 'description' => 'Auto-pay reseller package renewal invoices from wallet when balance is sufficient'],
            ['key' => 'notify_admin_new_order', 'value' => 'true', 'description' => 'Email and SMS admins on new orders'],
            ['key' => 'notify_admin_reseller_domain_push', 'value' => 'true', 'description' => 'Email admins on reseller domain push'],
            ['key' => 'notify_admin_manual_payment', 'value' => 'true', 'description' => 'Email admins on manual payment submission'],
            ['key' => 'notify_admin_node_offline', 'value' => 'true', 'description' => 'Email admins when container node goes offline'],
            ['key' => 'notify_service_provision_failed', 'value' => 'true', 'description' => 'Notify customer and admins when service auto-provisioning fails'],
            ['key' => 'notify_payment_failed', 'value' => 'true', 'description' => 'Notify customer when an online payment fails'],
            ['key' => 'notify_reseller_suspended', 'value' => 'true', 'description' => 'Notify reseller when their account is suspended for overdue package billing'],
            ['key' => 'notify_reseller_disk_pool_warning', 'value' => 'true', 'description' => 'Notify reseller when disk pool usage is exceeded'],
            ['key' => 'notify_reseller_domain_order_expired', 'value' => 'true', 'description' => 'Notify reseller when queued domain orders expire'],
            ['key' => 'notify_password_changed', 'value' => 'true', 'description' => 'Notify customer when their password is changed'],
            ['key' => 'notify_manual_payment_rejected', 'value' => 'true', 'description' => 'Notify customer when a manual payment submission is rejected'],
            ['key' => 'notify_reseller_ssl_provision_failed', 'value' => 'true', 'description' => 'Notify reseller when custom domain SSL provisioning fails'],
            ['key' => 'email_queue_enabled', 'value' => 'true', 'description' => 'Queue outbound emails via Laravel queue'],

            // SMS settings
            ['key' => 'sms_enabled', 'value' => 'false', 'description' => 'Enable SMS notifications'],
            ['key' => 'sms_api_token', 'value' => '', 'description' => 'Talksasa SMS API Bearer token'],
            ['key' => 'sms_sender_id', 'value' => 'TalksasaCloud', 'description' => 'SMS sender ID (max 11 chars)'],

            // DirectAdmin settings
            ['key' => 'directadmin_api_url', 'value' => '', 'description' => 'DirectAdmin API URL (e.g., https://da.example.com:2222)'],
            ['key' => 'directadmin_api_user', 'value' => 'admin', 'description' => 'DirectAdmin admin username'],
            ['key' => 'directadmin_api_password', 'value' => '', 'description' => 'DirectAdmin admin password'],
            ['key' => 'directadmin_default_package', 'value' => 'default', 'description' => 'Default DirectAdmin package for hosting accounts'],
            ['key' => 'directadmin_auto_push_package_limits', 'value' => '0', 'description' => 'When enabled, provisioning/upgrades rewrite DirectAdmin user package templates from the local catalog. Leave disabled to avoid overwriting server limits.'],

            // Cloudflare DNS
            ['key' => 'cloudflare_enabled', 'value' => 'false', 'description' => 'Enable Cloudflare managed DNS for customers'],
            ['key' => 'cloudflare_api_token', 'value' => '', 'description' => 'Cloudflare API token'],
            ['key' => 'cloudflare_account_id', 'value' => '', 'description' => 'Cloudflare account ID'],
            ['key' => 'cloudflare_branded_ns1', 'value' => '', 'description' => 'Cloudflare branded nameserver 1'],
            ['key' => 'cloudflare_branded_ns2', 'value' => '', 'description' => 'Cloudflare branded nameserver 2'],
            ['key' => 'cloudflare_branded_ns3', 'value' => '', 'description' => 'Cloudflare branded nameserver 3'],
            ['key' => 'cloudflare_branded_ns4', 'value' => '', 'description' => 'Cloudflare branded nameserver 4'],

            // Domain nameserver settings
            ['key' => 'domain_ns1', 'value' => 'ns1.talksasa.cloud', 'description' => 'Default nameserver 1 for domain registrations'],
            ['key' => 'domain_ns2', 'value' => 'ns2.talksasa.cloud', 'description' => 'Default nameserver 2 for domain registrations'],
            ['key' => 'domain_ns3', 'value' => '', 'description' => 'Default nameserver 3 (optional)'],
            ['key' => 'domain_ns4', 'value' => '', 'description' => 'Default nameserver 4 (optional)'],
        ];

        foreach ($settings as $setting) {
            if (app()->environment('production')) {
                Setting::firstOrCreate(
                    ['key' => $setting['key']],
                    ['value' => $setting['value'], 'description' => $setting['description']]
                );

                continue;
            }

            Setting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'], 'description' => $setting['description']]
            );
        }
    }
}

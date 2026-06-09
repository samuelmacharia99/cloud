<?php

namespace App\Enums;

enum NotificationEvent: string
{
    case InvoiceGenerated = 'invoice_generated';
    case InvoiceReminder = 'invoice_reminder';
    case InvoiceOverdue = 'invoice_overdue';
    case PaymentReceived = 'payment_received';
    case NewOrder = 'new_order';
    case ServiceActivated = 'service_activated';
    case ServiceSuspended = 'service_suspended';
    case ServiceUnsuspended = 'service_unsuspended';
    case ServiceTerminated = 'service_terminated';
    case DomainExpiry = 'domain_expiry';
    case TicketCreated = 'ticket_created';
    case TicketReplied = 'ticket_replied';
    case ContainerBackupCompleted = 'container_backup_completed';
    case ContainerBackupFailed = 'container_backup_failed';
    case ContainerFailed = 'container_failed';
    case ContainerRestart = 'container_restart';
    case ManualPaymentSubmitted = 'manual_payment_submitted';
    case ManualPaymentRejected = 'manual_payment_rejected';
    case ResellerDomainQueued = 'reseller_domain_queued';
    case ResellerDomainPushed = 'reseller_domain_pushed';
    case ResellerNewCustomerOrder = 'reseller_new_customer_order';
    case ResellerWalletLow = 'reseller_wallet_low';
    case ResellerWalletTopup = 'reseller_wallet_topup';
    case ResellerWalletAdjustment = 'reseller_wallet_adjustment';
    case AdminNewOrder = 'admin_new_order';
    case AdminResellerDomainPush = 'admin_reseller_domain_push';
    case AdminNodeOffline = 'admin_node_offline';
    case CronFailure = 'cron_failure';
    case CronHealth = 'cron_health';
    case PasswordChanged = 'password_changed';
    case EmailVerification = 'email_verification';
    case DomainTransfer = 'domain_transfer';
    case ServiceProvisionFailed = 'service_provision_failed';
    case PaymentFailed = 'payment_failed';
    case ResellerSuspended = 'reseller_suspended';
    case ResellerDiskPoolWarning = 'reseller_disk_pool_warning';
    case ResellerDomainOrderExpired = 'reseller_domain_order_expired';
    case DomainTransferCompleted = 'domain_transfer_completed';
    case DomainTransferFailed = 'domain_transfer_failed';
    case ResellerSslProvisionFailed = 'reseller_ssl_provision_failed';

    public function settingKey(): string
    {
        return match ($this) {
            self::InvoiceGenerated => 'notify_invoice_generated',
            self::InvoiceReminder => 'notify_invoice_reminder',
            self::InvoiceOverdue => 'notify_invoice_overdue',
            self::PaymentReceived => 'notify_payment',
            self::NewOrder => 'notify_new_order',
            self::ServiceActivated => 'notify_service_activated',
            self::ServiceSuspended => 'notify_service_suspend',
            self::ServiceUnsuspended => 'notify_service_unsuspended',
            self::ServiceTerminated => 'notify_service_terminated',
            self::DomainExpiry => 'notify_domain_expiry',
            self::TicketCreated, self::TicketReplied => 'notify_ticket',
            self::ContainerBackupCompleted => 'notify_container_backup',
            self::ContainerBackupFailed => 'notify_container_backup_failure',
            self::ContainerFailed => 'notify_container_failure',
            self::ContainerRestart => 'notify_container_restart',
            self::ManualPaymentSubmitted => 'notify_admin_manual_payment',
            self::ManualPaymentRejected => 'notify_manual_payment_rejected',
            self::ResellerDomainQueued => 'notify_reseller_domain_queued',
            self::ResellerDomainPushed => 'notify_reseller_domain_pushed',
            self::ResellerNewCustomerOrder => 'notify_reseller_new_customer_order',
            self::ResellerWalletLow => 'notify_reseller_wallet_low',
            self::ResellerWalletTopup => 'notify_reseller_wallet_topup',
            self::ResellerWalletAdjustment => 'notify_reseller_wallet_adjustment',
            self::AdminNewOrder => 'notify_admin_new_order',
            self::AdminResellerDomainPush => 'notify_admin_reseller_domain_push',
            self::AdminNodeOffline => 'notify_admin_node_offline',
            self::CronFailure => 'notify_cron_failure',
            self::CronHealth => 'notify_cron_health',
            self::PasswordChanged => 'notify_password_changed',
            self::EmailVerification => 'notify_email_verification',
            self::DomainTransfer => 'notify_domain_transfer',
            self::ServiceProvisionFailed => 'notify_service_provision_failed',
            self::PaymentFailed => 'notify_payment_failed',
            self::ResellerSuspended => 'notify_reseller_suspended',
            self::ResellerDiskPoolWarning => 'notify_reseller_disk_pool_warning',
            self::ResellerDomainOrderExpired => 'notify_reseller_domain_order_expired',
            self::DomainTransferCompleted => 'notify_domain_transfer',
            self::DomainTransferFailed => 'notify_domain_transfer',
            self::ResellerSslProvisionFailed => 'notify_reseller_ssl_provision_failed',
        };
    }

    public function audience(): string
    {
        return match ($this) {
            self::ManualPaymentSubmitted,
            self::AdminNewOrder,
            self::AdminResellerDomainPush,
            self::AdminNodeOffline,
            self::CronFailure,
            self::CronHealth,
            self::ContainerBackupFailed,
            self::ServiceProvisionFailed => 'admin',
            self::ResellerDomainQueued,
            self::ResellerDomainPushed,
            self::ResellerNewCustomerOrder,
            self::ResellerWalletLow,
            self::ResellerWalletTopup,
            self::ResellerWalletAdjustment,
            self::ResellerSuspended,
            self::ResellerDiskPoolWarning,
            self::ResellerDomainOrderExpired,
            self::ResellerSslProvisionFailed => 'reseller',
            default => 'customer',
        };
    }

    public function label(): string
    {
        return str_replace('_', ' ', ucwords($this->value, '_'));
    }
}

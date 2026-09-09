<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationEvent;
use Tests\TestCase;

class NotificationEventTest extends TestCase
{
    public function test_operator_error_alerts_are_telegram_only(): void
    {
        $this->assertTrue(NotificationEvent::ContainerBackupFailed->adminAlertsAreTelegramOnly());
        $this->assertTrue(NotificationEvent::AdminNodeOffline->adminAlertsAreTelegramOnly());
        $this->assertTrue(NotificationEvent::CronFailure->adminAlertsAreTelegramOnly());
        $this->assertTrue(NotificationEvent::CronHealth->adminAlertsAreTelegramOnly());
        $this->assertTrue(NotificationEvent::ServiceProvisionFailed->adminAlertsAreTelegramOnly());
    }

    public function test_non_error_admin_alerts_may_still_email(): void
    {
        $this->assertFalse(NotificationEvent::AdminNewOrder->adminAlertsAreTelegramOnly());
        $this->assertFalse(NotificationEvent::AdminNodeScaleOut->adminAlertsAreTelegramOnly());
        $this->assertFalse(NotificationEvent::PaymentFailed->adminAlertsAreTelegramOnly());
    }
}

<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\InAppNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InAppNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_stores_and_marks_notifications_read(): void
    {
        $user = User::factory()->customer()->create();
        $svc = app(InAppNotificationService::class);

        $svc->pushEvent($user, NotificationEvent::InvoiceGenerated, 'Invoice ready', 'Pay soon', '/invoices/1');
        $this->assertSame(1, $svc->unreadCount($user));

        $recent = $svc->recentFor($user);
        $this->assertCount(1, $recent);
        $this->assertTrue($recent->first()->isUnread());

        $svc->markAllRead($user);
        $this->assertSame(0, $svc->unreadCount($user));
    }
}

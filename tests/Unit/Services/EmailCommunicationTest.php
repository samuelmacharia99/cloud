<?php

namespace Tests\Unit\Services;

use App\Enums\NotificationEvent;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailCommunicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_preference_defaults_to_enabled(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationPreferenceService::class);

        $this->assertTrue($service->isEmailEnabledForUser($user, NotificationEvent::InvoiceGenerated));
        $this->assertTrue($service->isSmsEnabledForUser($user, NotificationEvent::InvoiceGenerated));
    }

    public function test_user_can_disable_email_for_event(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationPreferenceService::class);

        $service->updatePreference($user, NotificationEvent::InvoiceGenerated->value, false, true);

        $this->assertFalse($service->isEmailEnabledForUser($user, NotificationEvent::InvoiceGenerated));
        $this->assertTrue($service->isSmsEnabledForUser($user, NotificationEvent::InvoiceGenerated));
    }

    public function test_email_template_renders_placeholders(): void
    {
        $this->seed(\Database\Seeders\EmailTemplateSeeder::class);

        $template = EmailTemplate::forEvent('payment_received');
        $this->assertNotNull($template);

        $rendered = $template->renderBody([
            'customer_name' => 'Jane',
            'amount' => 'KES 100',
            'invoice_number' => 'INV-1',
            'site_name' => 'Talksasa',
        ]);

        $this->assertStringContainsString('Jane', $rendered);
        $this->assertStringContainsString('INV-1', $rendered);
    }
}

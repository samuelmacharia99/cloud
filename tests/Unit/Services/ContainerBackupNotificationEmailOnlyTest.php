<?php

namespace Tests\Unit\Services;

use App\Mail\ContainerBackupCompletedMail;
use App\Mail\ContainerBackupFailedMail;
use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class ContainerBackupNotificationEmailOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('smtp_host', 'smtp.example.com');
        Setting::setValue('smtp_port', '587');
        Setting::setValue('smtp_from_address', 'noreply@example.com');
        Setting::setValue('notify_container_backup', 'true');
        Setting::setValue('notify_container_backup_failure', 'true');
    }

    public function test_backup_completed_sends_email_but_not_sms(): void
    {
        Mail::fake();

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldNotReceive('send');
        $this->app->instance(SmsService::class, $sms);

        $customer = User::factory()->customer()->create([
            'email' => 'backup@example.com',
            'phone' => '+254700000001',
        ]);
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'name' => 'App Box',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
        ]);
        $backup = ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'completed',
        ]);

        app(NotificationService::class)->notifyContainerBackupCompleted($service->fresh('user'), $backup);

        Mail::assertSent(ContainerBackupCompletedMail::class, function ($mail) use ($customer) {
            return $mail->hasTo($customer->email);
        });
    }

    public function test_backup_failed_sends_admin_email_but_not_sms(): void
    {
        Mail::fake();

        $sms = Mockery::mock(SmsService::class);
        $sms->shouldReceive('isConfigured')->andReturn(true);
        $sms->shouldNotReceive('send');
        $this->app->instance(SmsService::class, $sms);

        User::factory()->create([
            'is_admin' => true,
            'email' => 'admin@example.com',
            'notification_phones' => ['+254700000099'],
        ]);

        $customer = User::factory()->customer()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'name' => 'Failing Box',
        ]);

        app(NotificationService::class)->notifyContainerBackupFailed($service->fresh('user'), 'disk full');

        Mail::assertSent(ContainerBackupFailedMail::class);
    }
}

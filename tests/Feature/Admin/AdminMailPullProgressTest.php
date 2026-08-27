<?php

namespace Tests\Feature\Admin;

use App\Jobs\RetryDirectAdminMailPullJob;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminMailPullProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminMailPullProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_poll_mail_pull_status(): void
    {
        [$admin, $service] = $this->convertedService();
        app(DirectAdminMailPullProgress::class)->queue($service);

        $this->actingAs($admin)
            ->getJson(route('admin.services.mail-pull-status', $service))
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('is_active', true)
            ->assertJsonPath('percent', 1);
    }

    public function test_admin_retry_queues_mail_pull_and_returns_live_state(): void
    {
        Queue::fake();
        [$admin, $service] = $this->convertedService();

        $this->actingAs($admin)
            ->postJson(route('admin.services.retry-mail-pull', $service))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('is_active', true);

        Queue::assertPushed(RetryDirectAdminMailPullJob::class, fn ($job) => $job->serviceId === $service->id);
        $this->assertTrue(app(DirectAdminMailPullProgress::class)->isActive($service->fresh()));
    }

    public function test_admin_retry_does_not_queue_a_second_active_pull(): void
    {
        Queue::fake();
        [$admin, $service] = $this->convertedService();
        app(DirectAdminMailPullProgress::class)->begin($service, 3);

        $this->actingAs($admin)
            ->postJson(route('admin.services.retry-mail-pull', $service))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertSee('already running', false);

        Queue::assertNothingPushed();
    }

    public function test_admin_service_show_includes_live_console(): void
    {
        [$admin, $service] = $this->convertedService();

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Operator console')
            ->assertSee('mail-pull · service-'.$service->id)
            ->assertSee('Retry mail pull');
    }

    public function test_customer_cannot_poll_mail_pull_status(): void
    {
        [, $service] = $this->convertedService();
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->getJson(route('admin.services.mail-pull-status', $service))
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function convertedService(): array
    {
        $admin = User::factory()->admin()->create();
        $customer = User::factory()->create();
        $email = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => Product::factory()->emailHosting()->create()->id,
            'provisioning_driver_key' => 'mailcow',
        ]);
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => Product::factory()->containerHosting()->create()->id,
            'provisioning_driver_key' => 'container',
            'service_meta' => [
                'da_convert' => ['status' => 'completed'],
                'da_legacy' => [
                    'email_service_id' => $email->id,
                    'username' => 'winkairwaystrave',
                    'domain' => 'winkairwaystraveladventure.co.ke',
                ],
                'mailcow_migration' => [
                    'email_service_id' => $email->id,
                    'mailboxes_created' => ['info@winkairwaystraveladventure.co.ke'],
                ],
            ],
        ]);

        return [$admin, $service];
    }
}

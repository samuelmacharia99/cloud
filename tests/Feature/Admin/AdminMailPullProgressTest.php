<?php

namespace Tests\Feature\Admin;

use App\Jobs\ConvertDirectAdminServiceToContainerJob;
use App\Jobs\RetryDirectAdminMailPullJob;
use App\Models\CustomerProject;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminMailPullProgress;
use App\Services\Provisioning\DirectAdminToContainerConvertService;
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

    public function test_admin_retry_convert_requeues_the_same_service(): void
    {
        Queue::fake();
        [$admin, $service] = $this->convertedService();
        $daProduct = Product::factory()->create(['type' => 'shared_hosting', 'provisioning_driver_key' => 'directadmin']);
        $meta = $service->service_meta;
        $meta['da_convert'] = [
            'status' => 'failed',
            'error' => 'import failed',
            'previous' => ['product_id' => $daProduct->id, 'node_id' => null, 'provisioning_driver_key' => 'directadmin', 'custom_price' => null, 'status' => 'active'],
            'options' => ['product_id' => $service->product_id, 'email_product_id' => null, 'database_name' => null, 'acknowledge_mail_pull' => true, 'acknowledge_addon_sites' => true],
        ];
        $service->update(['service_meta' => $meta]);
        $servicesBefore = Service::query()->count();

        $this->actingAs($admin)
            ->postJson(route('admin.services.retry-convert', $service))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('convert_status', 'queued');

        Queue::assertPushed(ConvertDirectAdminServiceToContainerJob::class, fn ($job) => $job->serviceId === $service->id && $job->productId === $service->product_id);
        $this->assertSame($servicesBefore, Service::query()->count());
        $this->assertSame($daProduct->id, $service->fresh()->product_id);
    }

    public function test_admin_retry_convert_reports_why_it_cannot_run(): void
    {
        Queue::fake();
        [$admin, $service] = $this->convertedService();

        $this->actingAs($admin)
            ->postJson(route('admin.services.retry-convert', $service))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        Queue::assertNothingPushed();
    }

    public function test_customer_cannot_retry_convert(): void
    {
        [, $service] = $this->convertedService();

        $this->actingAs(User::factory()->create())
            ->postJson(route('admin.services.retry-convert', $service))
            ->assertForbidden();
    }

    public function test_admin_service_show_offers_convert_retry_and_lists_sibling_sites(): void
    {
        [$admin, $service] = $this->convertedService();
        $meta = $service->service_meta;
        $meta['da_convert']['previous'] = ['product_id' => Product::factory()->create()->id];
        $meta['da_convert']['options'] = ['product_id' => $service->product_id];
        $service->update(['service_meta' => $meta]);
        $project = CustomerProject::factory()->create([
            'user_id' => $service->user_id,
            'billing_service_id' => $service->id,
            'recipe_key' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
        ]);
        $service->update(['project_id' => $project->id]);
        Service::factory()->create([
            'user_id' => $service->user_id,
            'product_id' => $service->product_id,
            'project_id' => $project->id,
            'status' => 'failed',
            'service_meta' => [
                'domain' => 'shop.winkairwaystraveladventure.co.ke',
                'project_recipe' => DirectAdminToContainerConvertService::PROJECT_RECIPE_KEY,
                'project_role' => 'site',
                'da_convert' => ['status' => 'failed', 'error' => 'no docroot'],
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Retry convert')
            ->assertSee('Extra sites on this package')
            ->assertSee('shop.winkairwaystraveladventure.co.ke');

        $this->actingAs($admin)
            ->getJson(route('admin.services.mail-pull-status', $service))
            ->assertOk()
            ->assertJsonPath('can_retry_convert', true)
            ->assertJsonPath('siblings_total', 1)
            ->assertJsonPath('siblings_failed', 1)
            ->assertJsonPath('siblings.0.domain', 'shop.winkairwaystraveladventure.co.ke')
            ->assertJsonPath('siblings.0.can_retry', true);
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

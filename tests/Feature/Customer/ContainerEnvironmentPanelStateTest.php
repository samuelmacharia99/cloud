<?php

namespace Tests\Feature\Customer;

use App\Http\Controllers\Customer\ContainerController;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ApplicationEnvironmentRequirements;
use App\Services\Provisioning\ContainerEnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Service 457 crash-looped on four settings that were present and empty, and
 * the page its owner was sent to in order to fix it could not show the
 * difference between one of those and a setting nobody had ever set.
 *
 * Both rendered as a row with an empty box. Worse, a setting present and empty
 * is both stored and outstanding, so it was drawn twice: the same key, two
 * identical empty boxes, on exactly the rows somebody had come to the page for.
 */
class ContainerEnvironmentPanelStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_setting_present_and_empty_appears_once_and_needs_a_value(): void
    {
        [$service, $deployment] = $this->deployedService(['ENABLE_SMS' => '']);
        app(ApplicationEnvironmentRequirements::class)->rememberRequired($service, ['ENABLE_SMS']);

        $rows = $this->rowsFor($service->fresh(), $deployment);

        $this->assertCount(1, array_filter($rows, fn (array $row): bool => $row['key'] === 'ENABLE_SMS'));
        $this->assertSame(ContainerEnvironmentService::STATE_NEEDS_VALUE, $this->row($rows, 'ENABLE_SMS')['state']);
    }

    public function test_a_setting_nobody_ever_set_is_a_suggestion_rather_than_a_fault(): void
    {
        [$service, $deployment] = $this->deployedService([]);
        app(ApplicationEnvironmentRequirements::class)->rememberDeclared($service, ['SENTRY_DSN']);

        $row = $this->row($this->rowsFor($service->fresh(), $deployment), 'SENTRY_DSN');

        $this->assertSame(ContainerEnvironmentService::STATE_SUGGESTED, $row['state']);
        $this->assertTrue($row['suggested']);
    }

    public function test_a_rejected_setting_keeps_its_value_and_is_marked(): void
    {
        [$service, $deployment] = $this->deployedService(['ENABLE_SMS' => 'maybe']);
        app(ApplicationEnvironmentRequirements::class)->rememberInvalid($service, ['ENABLE_SMS']);

        $row = $this->row($this->rowsFor($service->fresh(), $deployment), 'ENABLE_SMS');

        $this->assertSame(ContainerEnvironmentService::STATE_REJECTED, $row['state']);
        $this->assertSame('maybe', $row['value']);
        $this->assertFalse($row['unset']);
    }

    public function test_the_rows_holding_the_app_down_come_first(): void
    {
        [$service, $deployment] = $this->deployedService([
            'APP_ENV' => 'production',
            'DB_HOST' => 'db',
            'ENABLE_SMS' => '',
            'MPESA_ENV' => 'sandbox',
        ]);
        $requirements = app(ApplicationEnvironmentRequirements::class);
        $requirements->rememberRequired($service, ['ENABLE_SMS']);
        $requirements->rememberInvalid($service->fresh(), ['MPESA_ENV']);
        $requirements->rememberDeclared($service->fresh(), ['SENTRY_DSN']);

        $keys = array_column($this->rowsFor($service->fresh(), $deployment), 'key');

        $this->assertSame('ENABLE_SMS', $keys[0]);
        $this->assertSame('MPESA_ENV', $keys[1]);
        // The platform's own database wiring sinks below the customer's values,
        // and a suggestion sinks below everything.
        $this->assertGreaterThan(array_search('APP_ENV', $keys, true), array_search('DB_HOST', $keys, true));
        $this->assertSame('SENTRY_DSN', $keys[count($keys) - 1]);
    }

    public function test_removing_a_suggestion_dismisses_it_instead_of_pretending(): void
    {
        [$service, $deployment] = $this->deployedService([]);
        app(ApplicationEnvironmentRequirements::class)->rememberDeclared($service, ['SENTRY_DSN', 'REDIS_URL']);

        $result = app(ContainerEnvironmentService::class)
            ->deleteVariables($service->fresh(), ['SENTRY_DSN'], restart: false);

        $this->assertSame(0, $result['deleted']);
        $this->assertSame(['SENTRY_DSN'], $result['dismissed']);
        $this->assertStringContainsString('not be offered again', $result['message']);

        $keys = array_column($this->rowsFor($service->fresh(), $deployment), 'key');

        $this->assertNotContains('SENTRY_DSN', $keys);
        $this->assertContains('REDIS_URL', $keys);
    }

    public function test_a_dismissed_setting_comes_back_when_the_app_stops_on_it(): void
    {
        // The safety property that makes a permanent dismissal safe. A customer
        // waving away a suggestion is not a customer deciding their app should
        // never start.
        [$service, $deployment] = $this->deployedService([]);
        $requirements = app(ApplicationEnvironmentRequirements::class);
        $requirements->rememberDeclared($service, ['SENTRY_DSN']);
        $requirements->rememberDismissed($service->fresh(), ['SENTRY_DSN']);

        $requirements->rememberRequired($service->fresh(), ['SENTRY_DSN']);

        $row = $this->row($this->rowsFor($service->fresh(), $deployment), 'SENTRY_DSN');

        $this->assertSame(ContainerEnvironmentService::STATE_NEEDS_VALUE, $row['state']);
    }

    public function test_removing_a_real_setting_still_deletes_it(): void
    {
        [$service, $deployment] = $this->deployedService(['SENTRY_DSN' => 'https://x@sentry.io/1']);

        $result = app(ContainerEnvironmentService::class)
            ->deleteVariables($service->fresh(), ['SENTRY_DSN'], restart: false);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame([], $result['dismissed']);
        $this->assertArrayNotHasKey('SENTRY_DSN', $deployment->fresh()->env_values);
    }

    public function test_the_operator_console_offers_no_remove_and_hides_no_value_that_does_not_exist(): void
    {
        // Import, Add and Save are all disabled for an operator. Remove was not,
        // so a support screen showing "[redacted]" could still destroy a
        // customer's setting. And "[redacted]" on a suggestion told an operator
        // a secret was set where none was.
        [$service, $deployment] = $this->deployedService(['SENTRY_DSN' => 'https://x@sentry.io/1']);
        app(ApplicationEnvironmentRequirements::class)->rememberDeclared($service, ['REDIS_URL']);

        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.services.show', $service))
            ->assertOk()
            ->assertSee('Environment values are hidden in the operator console', false);

        $panel = app(ContainerController::class)
            ->consoleViewData($service->fresh(), false, includeSecrets: false)['environmentPanel'];

        $this->assertFalse($panel['can_save']);
        $this->assertFalse($panel['can_apply']);
        $this->assertSame('[redacted]', $this->row($panel['variables'], 'SENTRY_DSN')['value']);
        $this->assertSame('', $this->row($panel['variables'], 'REDIS_URL')['value']);
    }

    public function test_staging_sync_copies_values_and_never_a_suggestion(): void
    {
        // A suggestion copied into staging writes an empty setting, which an
        // application treats as a real answer and refuses, where no setting at
        // all falls back to its own default.
        [$service, $deployment] = $this->deployedService(['APP_ENV' => 'production']);
        app(ApplicationEnvironmentRequirements::class)->rememberDeclared($service, ['SENTRY_DSN']);

        $rows = $this->rowsFor($service->fresh(), $deployment);
        $copied = [];
        foreach ($rows as $row) {
            if (! empty($row['platform_managed']) || ! empty($row['suggested'])) {
                continue;
            }
            $copied[$row['key']] = $row['value'];
        }

        $this->assertArrayHasKey('APP_ENV', $copied);
        $this->assertArrayNotHasKey('SENTRY_DSN', $copied);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function row(array $rows, string $key): array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }

        $this->fail("No row for {$key}.");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(Service $service, ContainerDeployment $deployment): array
    {
        return app(ContainerEnvironmentService::class)
            ->buildPanelState($service, $deployment->fresh())['variables'];
    }

    /**
     * @param  array<string, string>  $env
     * @return array{0: Service, 1: ContainerDeployment}
     */
    private function deployedService(array $env): array
    {
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'python'],
        ]);

        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-python',
            'status' => 'running',
            'env_values' => $env,
        ]);

        return [$service->fresh(), $deployment];
    }
}

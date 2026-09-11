<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerEnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Python service crash-looped on ENABLE_SMS and three settings beside it,
 * every one of them present and empty.
 *
 * The Environment tab lists the settings an application declares but nobody has
 * filled in, as rows with empty boxes. Saving the form posted those rows too,
 * and they were written. An absent setting falls back to the application's own
 * default; one present and empty is handed to the parser, which refuses it. The
 * two look identical in a settings list and behave nothing alike.
 */
class ContainerEnvironmentBlankValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_suggestion_saved_untouched_is_not_written_as_an_empty_value(): void
    {
        [$service, $deployment] = $this->deployedService(['DB_HOST' => 'db']);

        $result = $this->save($service, [
            ['key' => 'MPESA_SHORTCODE', 'value' => '174379'],
            ['key' => 'ENABLE_SMS', 'value' => ''],
            ['key' => 'RIDER_LOCATION_MAX_AGE_SECONDS', 'value' => ''],
        ]);

        $env = $deployment->fresh()->env_values;

        $this->assertSame('174379', $env['MPESA_SHORTCODE']);
        $this->assertArrayNotHasKey('ENABLE_SMS', $env);
        $this->assertArrayNotHasKey('RIDER_LOCATION_MAX_AGE_SECONDS', $env);
        $this->assertSame(['ENABLE_SMS', 'RIDER_LOCATION_MAX_AGE_SECONDS'], $result['skipped']);
    }

    public function test_clearing_a_setting_that_exists_is_still_a_real_instruction(): void
    {
        // Only a blank for a key that never had a value is the panel's own
        // suggestion row. Emptying one the customer already set is a decision.
        [$service, $deployment] = $this->deployedService(['SENTRY_DSN' => 'https://old@sentry.io/1']);

        $this->save($service, [['key' => 'SENTRY_DSN', 'value' => '']]);

        $env = $deployment->fresh()->env_values;

        $this->assertArrayHasKey('SENTRY_DSN', $env);
        $this->assertSame('', $env['SENTRY_DSN']);
    }

    public function test_the_customer_is_told_what_was_left_alone(): void
    {
        [$service] = $this->deployedService([]);

        $result = $this->save($service, [
            ['key' => 'APP_ENV', 'value' => 'production'],
            ['key' => 'ENABLE_SMS', 'value' => ''],
        ]);

        $this->assertStringContainsString('ENABLE_SMS had no value', $result['message']);
        $this->assertSame(1, $result['updated']);
    }

    public function test_a_value_that_is_only_whitespace_is_still_a_value(): void
    {
        // Deliberate: trimming here would silently change what the customer
        // typed, and some applications do use a single space as a marker.
        [$service, $deployment] = $this->deployedService([]);

        $this->save($service, [['key' => 'PREFIX', 'value' => ' ']]);

        $this->assertSame(' ', $deployment->fresh()->env_values['PREFIX']);
    }

    /**
     * @param  list<array{key: string, value: string}>  $variables
     * @return array<string, mixed>
     */
    private function save(Service $service, array $variables): array
    {
        return app(ContainerEnvironmentService::class)
            ->updateVariables($service->fresh(), $variables, restart: false);
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

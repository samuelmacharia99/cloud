<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ApplicationEnvironmentRequirements;
use App\Services\Provisioning\ContainerEnvironmentService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The console has to show a customer which settings their application is
 * waiting on, without ever writing a blank value into the container.
 */
class ContainerEnvironmentPanelRequirementsTest extends TestCase
{
    #[Test]
    public function it_lists_blocking_keys_the_application_requires(): void
    {
        $state = $this->panel(
            required: ['AT_USERNAME', 'AT_API_KEY'],
            declared: [],
            envValues: ['DATABASE_URL' => 'postgresql://app:secret@db:5432/appdb'],
        );

        $this->assertSame(['AT_USERNAME', 'AT_API_KEY'], $state['required_by_app']);
        $this->assertSame([], $state['suggested_by_app']);

        $rows = $this->rowsByKey($state);
        $this->assertTrue($rows['AT_API_KEY']['required_by_app']);
        $this->assertTrue($rows['AT_API_KEY']['unset']);
        $this->assertSame('', $rows['AT_API_KEY']['value']);
    }

    #[Test]
    public function it_separates_optional_suggestions_from_blocking_keys(): void
    {
        $state = $this->panel(
            required: ['AT_API_KEY'],
            declared: ['AT_API_KEY', 'SENTRY_DSN'],
            envValues: [],
        );

        $this->assertSame(['AT_API_KEY'], $state['required_by_app']);
        $this->assertSame(['SENTRY_DSN'], $state['suggested_by_app']);

        $rows = $this->rowsByKey($state);
        $this->assertFalse($rows['SENTRY_DSN']['required_by_app']);
        $this->assertTrue($rows['SENTRY_DSN']['unset']);
    }

    #[Test]
    public function a_key_that_now_has_a_value_stops_being_listed(): void
    {
        $state = $this->panel(
            required: ['AT_USERNAME', 'AT_API_KEY'],
            declared: [],
            envValues: ['AT_USERNAME' => 'zumi'],
        );

        $this->assertSame(['AT_API_KEY'], $state['required_by_app']);

        $rows = $this->rowsByKey($state);
        $this->assertSame('zumi', $rows['AT_USERNAME']['value']);
        $this->assertFalse($rows['AT_USERNAME']['unset']);
    }

    #[Test]
    public function it_never_lists_a_variable_the_platform_supplies_itself(): void
    {
        $state = $this->panel(
            required: [],
            declared: ['DATABASE_URL', 'DB_HOST', 'SECRET_KEY', 'INTERNAL_API_URL'],
            envValues: [],
        );

        $this->assertSame([], $state['required_by_app']);
        $this->assertSame([], $state['suggested_by_app']);
    }

    #[Test]
    public function existing_variables_report_the_same_row_shape(): void
    {
        $state = $this->panel(required: [], declared: [], envValues: ['CUSTOM' => 'value']);

        $rows = $this->rowsByKey($state);
        $this->assertArrayHasKey('required_by_app', $rows['CUSTOM']);
        $this->assertArrayHasKey('unset', $rows['CUSTOM']);
        $this->assertFalse($rows['CUSTOM']['unset']);
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $declared
     * @param  array<string, string>  $envValues
     * @return array<string, mixed>
     */
    private function panel(array $required, array $declared, array $envValues): array
    {
        $service = new Service;
        $service->id = 457;
        $service->service_meta = [
            ApplicationEnvironmentRequirements::REQUIRED_META_KEY => $required,
            ApplicationEnvironmentRequirements::DECLARED_META_KEY => $declared,
        ];

        $deployment = new ContainerDeployment;
        $deployment->status = 'stopped';
        $deployment->env_values = $envValues;

        return app(ContainerEnvironmentService::class)->buildPanelState($service, $deployment);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, array<string, mixed>>
     */
    private function rowsByKey(array $state): array
    {
        $rows = [];
        foreach ($state['variables'] as $row) {
            $rows[$row['key']] = $row;
        }

        return $rows;
    }
}

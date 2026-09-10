<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A redeploy rebuilds the container environment from scratch. Anything the
 * customer added under Environment has to survive that rebuild, or the stack
 * comes back missing the credentials its application needs to boot.
 */
class ContainerEnvironmentRebuildTest extends TestCase
{
    #[Test]
    public function it_keeps_customer_variables_the_template_does_not_declare(): void
    {
        $env = $this->build(['AT_USERNAME' => 'zumi', 'AT_API_KEY' => 'live-key']);

        $this->assertSame('zumi', $env['AT_USERNAME']);
        $this->assertSame('live-key', $env['AT_API_KEY']);
    }

    #[Test]
    public function it_keeps_a_generated_secret_across_redeploys(): void
    {
        $first = $this->build([]);
        $second = $this->build($first);

        $this->assertSame($first['SECRET_KEY'], $second['SECRET_KEY']);
        $this->assertSame($first['DB_PASSWORD'], $second['DB_PASSWORD']);
    }

    #[Test]
    public function it_still_lets_platform_values_win_over_stale_customer_copies(): void
    {
        $env = $this->build([
            'APP_PORT' => '30001',
            'COMPOSE_PROJECT_NAME' => 'talksasa-stale',
            'PORT' => '9999',
        ]);

        $this->assertSame('31234', $env['APP_PORT']);
        $this->assertSame('talksasa-457', $env['COMPOSE_PROJECT_NAME']);
        $this->assertSame('8000', $env['PORT']);
    }

    #[Test]
    public function it_applies_the_template_default_when_the_customer_supplied_nothing(): void
    {
        $env = $this->build([]);

        $this->assertSame('1', $env['PYTHONUNBUFFERED']);
    }

    /**
     * @param  array<string, string>  $userValues
     * @return array<string, string>
     */
    private function build(array $userValues): array
    {
        $service = new Service;
        $service->id = 457;

        $method = new ReflectionMethod(ContainerDeploymentService::class, 'buildEnvironmentVariables');

        return $method->invoke(
            new ContainerDeploymentService,
            $this->pythonTemplate(),
            $userValues,
            $service,
            null,
            31234,
            'user-493-service-457-python',
        );
    }

    private function pythonTemplate(): object
    {
        return new class
        {
            public string $slug = 'python';

            public int $default_port = 8000;

            /** @var array<int, array<string, mixed>> */
            public array $environment_variables = [
                ['key' => 'PYTHONUNBUFFERED', 'default' => '1', 'required' => false, 'secret' => false],
            ];
        };
    }
}

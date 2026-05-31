<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\LaravelAppInitializationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LaravelAppInitializationServiceTest extends TestCase
{
    #[Test]
    public function it_quotes_env_values_when_special_characters_are_present(): void
    {
        $service = new LaravelAppInitializationService;
        $method = new ReflectionMethod(LaravelAppInitializationService::class, 'quoteEnvValue');
        $method->setAccessible(true);

        $this->assertSame('simple', $method->invoke($service, 'simple'));
        $this->assertSame('""', $method->invoke($service, ''));
        $this->assertSame('"pa ss"', $method->invoke($service, 'pa ss'));
    }

    #[Test]
    public function it_builds_initialization_steps_for_laravel(): void
    {
        $service = new LaravelAppInitializationService;
        $method = new ReflectionMethod(LaravelAppInitializationService::class, 'buildInitialSteps');
        $method->setAccessible(true);

        $steps = $method->invoke($service);

        $this->assertCount(count(LaravelAppInitializationService::STEP_DEFINITIONS), $steps);
        $this->assertSame('validate', $steps[0]['key']);
        $this->assertSame('pending', $steps[0]['status']);
    }
}

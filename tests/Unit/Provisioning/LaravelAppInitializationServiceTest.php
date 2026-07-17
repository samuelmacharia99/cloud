<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\LaravelAppInitializationService;
use App\Services\Provisioning\LaravelProjectPathResolver;
use App\Services\Provisioning\LaravelWelcomePageService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class LaravelAppInitializationServiceTest extends TestCase
{
    #[Test]
    public function it_quotes_env_values_when_special_characters_are_present(): void
    {
        $service = new LaravelAppInitializationService(
            new ContainerAppDirectoryService,
            new LaravelWelcomePageService,
            new LaravelProjectPathResolver,
        );
        $method = new ReflectionMethod(LaravelAppInitializationService::class, 'quoteEnvValue');
        $method->setAccessible(true);

        $this->assertSame('simple', $method->invoke($service, 'simple'));
        $this->assertSame('""', $method->invoke($service, ''));
        $this->assertSame('"pa ss"', $method->invoke($service, 'pa ss'));
    }

    #[Test]
    public function it_builds_initialization_steps_for_laravel(): void
    {
        $service = new LaravelAppInitializationService(
            new ContainerAppDirectoryService,
            new LaravelWelcomePageService,
            new LaravelProjectPathResolver,
        );
        $method = new ReflectionMethod(LaravelAppInitializationService::class, 'buildInitialSteps');
        $method->setAccessible(true);

        $steps = $method->invoke($service);

        $this->assertCount(count(LaravelAppInitializationService::STEP_DEFINITIONS), $steps);
        $this->assertSame('validate', $steps[0]['key']);
        $this->assertSame('pending', $steps[0]['status']);
    }

    #[Test]
    public function it_preserves_custom_env_values_when_merging_platform_settings(): void
    {
        $service = new LaravelAppInitializationService(
            new ContainerAppDirectoryService,
            new LaravelWelcomePageService,
            new LaravelProjectPathResolver,
        );
        $method = new ReflectionMethod(LaravelAppInitializationService::class, 'mergePlatformEnvIntoExisting');
        $method->setAccessible(true);

        $existing = <<<'ENV'
APP_NAME="My Custom App"
APP_KEY=base64:existing-key
MAIL_MAILER=smtp
MAIL_PASSWORD=secret-value
DB_HOST=127.0.0.1
ENV;

        $merged = $method->invoke($service, $existing, [
            'DB_HOST' => 'db',
            'DB_DATABASE' => 'appdb',
            'APP_URL' => 'https://app.example.test',
        ]);

        $this->assertStringContainsString('APP_NAME="My Custom App"', $merged);
        $this->assertStringContainsString('APP_KEY=base64:existing-key', $merged);
        $this->assertStringContainsString('MAIL_PASSWORD=secret-value', $merged);
        $this->assertStringContainsString('DB_HOST=db', $merged);
        $this->assertStringContainsString('DB_DATABASE=appdb', $merged);
        $this->assertStringContainsString('APP_URL=https://app.example.test', $merged);
    }

    #[Test]
    public function it_injects_app_key_when_missing_or_empty_in_env_content(): void
    {
        $service = new LaravelAppInitializationService(
            new ContainerAppDirectoryService,
            new LaravelWelcomePageService,
            new LaravelProjectPathResolver,
        );

        $withKey = $service->ensureAppKeyInEnvContent("APP_NAME=Demo\nAPP_KEY=base64:keep-me\n");
        $this->assertStringContainsString('APP_KEY=base64:keep-me', $withKey);

        $emptyKey = $service->ensureAppKeyInEnvContent("APP_NAME=Demo\nAPP_KEY=\n");
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', $emptyKey);
        $this->assertStringNotContainsString('APP_KEY=\n', $emptyKey);

        $missingKey = $service->ensureAppKeyInEnvContent("APP_NAME=Demo\n");
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]+$/m', $missingKey);
    }
}

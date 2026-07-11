<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerEnvironmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

class ContainerEnvironmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_env_file_updates_existing_and_appends_missing_keys(): void
    {
        $service = new ContainerEnvironmentService;
        $content = "APP_NAME=Demo\nAPP_ENV=local\n# comment\nDB_HOST=127.0.0.1\n";

        $merged = $service->mergeEnvFileContent($content, [
            'APP_ENV' => 'production',
            'NEW_KEY' => 'value with spaces',
        ]);

        $this->assertStringContainsString('APP_ENV=production', $merged);
        $this->assertStringContainsString('APP_NAME=Demo', $merged);
        $this->assertStringContainsString('NEW_KEY="value with spaces"', $merged);
        $this->assertStringContainsString('# comment', $merged);
    }

    public function test_sensitive_and_platform_key_detection(): void
    {
        $service = new ContainerEnvironmentService;

        $this->assertTrue($service->isSensitiveKey('DB_PASSWORD'));
        $this->assertTrue($service->isSensitiveKey('APP_KEY'));
        $this->assertFalse($service->isSensitiveKey('APP_ENV'));
        $this->assertTrue($service->isPlatformManagedKey('DB_HOST'));
        $this->assertFalse($service->isPlatformManagedKey('CUSTOM_FLAG'));
    }

    public function test_build_panel_state_sorts_and_flags_variables(): void
    {
        $service = new Service;
        $service->setRelation('product', null);

        $deployment = new ContainerDeployment([
            'status' => 'running',
            'env_values' => [
                'ZZ_CUSTOM' => '1',
                'DB_PASSWORD' => 'secret',
                'APP_ENV' => 'production',
            ],
        ]);

        $panel = (new ContainerEnvironmentService)->buildPanelState($service, $deployment);

        $this->assertSame(['APP_ENV', 'DB_PASSWORD', 'ZZ_CUSTOM'], array_column($panel['variables'], 'key'));
        $this->assertTrue($panel['variables'][1]['sensitive']);
        $this->assertTrue($panel['variables'][1]['platform_managed']);
        $this->assertTrue($panel['can_apply']);
    }

    public function test_normalize_rejects_invalid_keys(): void
    {
        $this->expectException(ValidationException::class);

        $method = new ReflectionMethod(ContainerEnvironmentService::class, 'normalizeIncoming');
        $method->invoke(new ContainerEnvironmentService, [
            ['key' => 'bad-key!', 'value' => 'x'],
        ]);
    }
}

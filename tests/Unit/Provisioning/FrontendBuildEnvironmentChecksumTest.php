<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerNodeBuildService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Applying environment changes rebuilds the frontend only when this checksum
 * moves. A public key left out of it ships a bundle carrying the old value.
 */
class FrontendBuildEnvironmentChecksumTest extends TestCase
{
    #[Test]
    public function changing_an_expo_variable_triggers_a_rebuild(): void
    {
        $before = $this->checksum(['EXPO_PUBLIC_API_URL' => 'https://old.example.com']);
        $after = $this->checksum(['EXPO_PUBLIC_API_URL' => 'https://fa50-102-0-35-50.ngrok-free.app']);

        $this->assertNotSame($before, $after);
    }

    #[Test]
    public function the_prefixes_that_were_already_watched_still_are(): void
    {
        $this->assertNotSame(
            $this->checksum(['NEXT_PUBLIC_API_URL' => 'https://a.example.com']),
            $this->checksum(['NEXT_PUBLIC_API_URL' => 'https://b.example.com']),
        );

        $this->assertNotSame(
            $this->checksum(['VITE_API_URL' => 'https://a.example.com']),
            $this->checksum(['VITE_API_URL' => 'https://b.example.com']),
        );
    }

    #[Test]
    public function a_runtime_secret_does_not_force_a_frontend_rebuild(): void
    {
        $this->assertSame(
            $this->checksum(['AT_API_KEY' => 'one', 'VITE_API_URL' => 'https://a.example.com']),
            $this->checksum(['AT_API_KEY' => 'two', 'VITE_API_URL' => 'https://a.example.com']),
        );
    }

    #[Test]
    public function key_order_does_not_change_the_checksum(): void
    {
        $this->assertSame(
            $this->checksum(['VITE_A' => 'one', 'EXPO_PUBLIC_B' => 'two']),
            $this->checksum(['EXPO_PUBLIC_B' => 'two', 'VITE_A' => 'one']),
        );
    }

    /**
     * @param  array<string, mixed>  $envValues
     */
    private function checksum(array $envValues): string
    {
        $deployment = new ContainerDeployment;
        $deployment->env_values = $envValues;

        return app(ContainerNodeBuildService::class)->frontendBuildEnvironmentChecksum($deployment);
    }
}

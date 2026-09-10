<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerDoctorFrontendBuildAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerDoctorFrontendBuildAnalyzerTest extends TestCase
{
    private ContainerDoctorFrontendBuildAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->analyzer = new ContainerDoctorFrontendBuildAnalyzer;
    }

    #[Test]
    public function it_recognises_every_prefix_that_is_baked_into_a_bundle(): void
    {
        foreach (['EXPO_PUBLIC_API_URL', 'NEXT_PUBLIC_API_URL', 'VITE_API_URL', 'NUXT_PUBLIC_X', 'REACT_APP_Y'] as $key) {
            $this->assertTrue($this->analyzer->isPublicBuildKey($key), $key.' should be a build-time key');
        }
    }

    #[Test]
    public function it_leaves_runtime_and_secret_keys_alone(): void
    {
        foreach (['DATABASE_URL', 'AT_API_KEY', 'SECRET_KEY', 'API_URL', 'PORT'] as $key) {
            $this->assertFalse($this->analyzer->isPublicBuildKey($key), $key.' is not a build-time key');
        }
    }

    #[Test]
    public function it_collects_public_values_worth_searching_a_bundle_for(): void
    {
        $values = $this->analyzer->publicBuildValues($this->deployment([
            'EXPO_PUBLIC_API_URL' => 'https://fa50-102-0-35-50.ngrok-free.app',
            'VITE_API_URL' => 'https://api.example.com',
            'DATABASE_URL' => 'postgresql://app:secret@db:5432/appdb',
        ]));

        $this->assertSame(['EXPO_PUBLIC_API_URL', 'VITE_API_URL'], array_keys($values));
        $this->assertSame('https://fa50-102-0-35-50.ngrok-free.app', $values['EXPO_PUBLIC_API_URL']);
    }

    #[Test]
    public function it_skips_relative_values_that_would_match_anything(): void
    {
        $values = $this->analyzer->publicBuildValues($this->deployment([
            'EXPO_PUBLIC_API_URL' => '/api',
            'VITE_API_URL' => '/api',
        ]));

        $this->assertSame([], $values);
    }

    #[Test]
    public function it_skips_blank_and_non_scalar_values(): void
    {
        $values = $this->analyzer->publicBuildValues($this->deployment([
            'EXPO_PUBLIC_A' => '',
            'EXPO_PUBLIC_B' => '   ',
            'EXPO_PUBLIC_C' => ['nested'],
        ]));

        $this->assertSame([], $values);
    }

    #[Test]
    public function it_bounds_how_many_keys_one_diagnosis_will_probe(): void
    {
        $env = [];
        for ($index = 0; $index < 20; $index++) {
            $env['VITE_KEY_'.$index] = 'https://value-'.$index.'.example.com';
        }

        $this->assertCount(8, $this->analyzer->publicBuildValues($this->deployment($env)));
    }

    #[Test]
    public function the_finding_names_the_keys_and_offers_a_rebuild(): void
    {
        $finding = $this->analyzer->finding(['EXPO_PUBLIC_API_URL'], 'apps/mobile');

        $this->assertSame('frontend_public_env_stale', $finding['id']);
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('rebuild_frontend_bundle', $finding['treat_action']);
        $this->assertSame(ContainerDoctorFrontendBuildAnalyzer::TREAT_ACTION, $finding['treat_action']);
        $this->assertStringContainsString('EXPO_PUBLIC_API_URL', $finding['summary']);
        $this->assertStringContainsString('apps/mobile', $finding['evidence'][0]);
        $this->assertSame('live', $finding['source']);
    }

    #[Test]
    public function the_finding_warns_that_native_builds_are_rebuilt_elsewhere(): void
    {
        $finding = $this->analyzer->finding(['EXPO_PUBLIC_API_URL'], 'apps/mobile');

        $steps = implode(' ', $finding['manual_steps']);
        $this->assertStringContainsString('Native mobile builds', $steps);
    }

    /**
     * @param  array<string, mixed>  $envValues
     */
    private function deployment(array $envValues): ContainerDeployment
    {
        $deployment = new ContainerDeployment;
        $deployment->env_values = $envValues;

        return $deployment;
    }
}

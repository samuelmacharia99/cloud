<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerDoctorFrontendBuildAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BootsBareFacades;

class ContainerDoctorFrontendBuildAnalyzerTest extends TestCase
{
    use BootsBareFacades;

    private ContainerDoctorFrontendBuildAnalyzer $analyzer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootBareFacades();

        $this->analyzer = new ContainerDoctorFrontendBuildAnalyzer;
    }

    protected function tearDown(): void
    {
        $this->tearDownBareFacades();

        parent::tearDown();
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

    #[Test]
    public function it_reports_a_public_setting_stranded_on_an_api_container(): void
    {
        $finding = $this->analyzer->misplacedFinding(['EXPO_PUBLIC_API_URL'], 'Sameplan-Web');

        $this->assertSame('frontend_public_env_on_api_container', $finding['id']);
        $this->assertSame('warning', $finding['severity']);
        $this->assertSame('move_public_env_to_web', $finding['treat_action']);
        $this->assertSame(ContainerDoctorFrontendBuildAnalyzer::MOVE_TREAT_ACTION, $finding['treat_action']);
        $this->assertStringContainsString('Sameplan-Web', $finding['summary']);
        $this->assertStringContainsString('EXPO_PUBLIC_API_URL', $finding['summary']);
        $this->assertStringContainsString('unset on the Web container', $finding['evidence'][0]);
    }

    #[Test]
    public function it_still_explains_the_placement_when_the_web_service_has_no_name(): void
    {
        $finding = $this->analyzer->misplacedFinding(['VITE_API_URL'], null);

        $this->assertStringContainsString('the Web container in this project', $finding['summary']);
    }

    #[Test]
    public function it_promises_not_to_touch_the_api_container(): void
    {
        $steps = implode(' ', $this->analyzer->misplacedFinding(['VITE_API_URL'], 'Web')['manual_steps']);

        $this->assertStringContainsString('Nothing is removed from this API container', $steps);
    }

    #[Test]
    public function it_flags_an_api_address_that_only_resolves_on_the_container_network(): void
    {
        $offenders = $this->analyzer->unreachableFromBrowser(
            ['EXPO_PUBLIC_API_URL' => 'http://user-493-service-454-nodejs:8000'],
            siteUsesHttps: true,
        );

        $this->assertArrayHasKey('EXPO_PUBLIC_API_URL', $offenders);
        $this->assertStringContainsString('container network', $offenders['EXPO_PUBLIC_API_URL']);
    }

    #[Test]
    public function it_flags_plain_http_only_when_the_site_itself_is_https(): void
    {
        $value = ['VITE_API_URL' => 'http://api.example.com'];

        $this->assertArrayHasKey('VITE_API_URL', $this->analyzer->unreachableFromBrowser($value, siteUsesHttps: true));
        $this->assertSame([], $this->analyzer->unreachableFromBrowser($value, siteUsesHttps: false));
    }

    #[Test]
    public function it_leaves_a_public_https_address_alone(): void
    {
        $this->assertSame([], $this->analyzer->unreachableFromBrowser(
            ['EXPO_PUBLIC_API_URL' => 'https://api.carslynk.example.com'],
            siteUsesHttps: true,
        ));
    }

    #[Test]
    public function it_ignores_a_value_that_is_not_an_absolute_url(): void
    {
        $this->assertSame([], $this->analyzer->unreachableFromBrowser(
            ['EXPO_PUBLIC_API_KEY' => 'pk_live_0123456789'],
            siteUsesHttps: true,
        ));
    }

    #[Test]
    public function the_unreachable_finding_offers_the_api_domain_when_there_is_one(): void
    {
        $finding = $this->analyzer->unreachableFinding(
            ['EXPO_PUBLIC_API_URL' => 'resolves only on the container network'],
            'https://api.example.com',
        );

        $this->assertSame('frontend_public_env_unreachable', $finding['id']);
        $this->assertSame('critical', $finding['severity']);
        $this->assertSame('point_public_env_at_api', $finding['treat_action']);
        $this->assertSame(ContainerDoctorFrontendBuildAnalyzer::POINT_AT_API_TREAT_ACTION, $finding['treat_action']);
        $this->assertStringContainsString('https://api.example.com', $finding['manual_steps'][0]);
    }

    #[Test]
    public function the_unreachable_finding_asks_for_a_domain_when_the_api_has_none(): void
    {
        $finding = $this->analyzer->unreachableFinding(
            ['EXPO_PUBLIC_API_URL' => 'resolves only on the container network'],
            null,
        );

        $this->assertNull($finding['treat_action']);
        $this->assertStringContainsString('Bind a domain to the API service', $finding['manual_steps'][0]);
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

<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerOriginSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Python app crash-looped because ALLOWED_ORIGINS held a comma-separated
 * list where pydantic wanted JSON. The platform knew the answer the whole time:
 * the domains bound to that very service.
 *
 * The reason this is not simply automated everywhere is that two settings which
 * read alike want opposite values, and two applications using the same name can
 * want different formats. These tests pin both boundaries.
 */
class ContainerOriginSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_origin_list_carries_the_scheme_and_both_name_forms(): void
    {
        $deployment = $this->deploymentWithDomain('chakula.co.ke');

        $this->assertSame(
            '["https://chakula.co.ke","https://www.chakula.co.ke"]',
            $this->origins()->valueFor($deployment, 'ALLOWED_ORIGINS'),
        );
    }

    #[Test]
    public function a_hostname_list_never_carries_a_scheme(): void
    {
        // Django's ALLOWED_HOSTS reads like the setting above and wants the
        // opposite. An origin in it rejects every request the app receives.
        $deployment = $this->deploymentWithDomain('chakula.co.ke');

        $this->assertSame(
            '["chakula.co.ke","www.chakula.co.ke"]',
            $this->origins()->valueFor($deployment, 'ALLOWED_HOSTS'),
        );
    }

    #[Test]
    public function a_multi_part_country_domain_still_gets_its_www_form(): void
    {
        // Counting dots to spot a subdomain calls chakula.co.ke one, and the
        // same goes for every .co.uk, .com.au and .ac.ke there is. Nothing here
        // tries: an origin nobody sends is dead weight, while a missing www
        // entry locks out half the visitors.
        $deployment = $this->deploymentWithDomain('api.chakula.co.ke');

        $this->assertSame(
            '["https://api.chakula.co.ke","https://www.api.chakula.co.ke"]',
            $this->origins()->valueFor($deployment, 'CORS_ORIGINS'),
        );
    }

    #[Test]
    public function a_domain_bound_as_www_is_not_doubled_up(): void
    {
        $deployment = $this->deploymentWithDomain('www.chakula.co.ke');

        $this->assertSame(
            '["https://chakula.co.ke","https://www.chakula.co.ke"]',
            $this->origins()->valueFor($deployment, 'ALLOWED_ORIGINS'),
        );
    }

    #[Test]
    public function a_domain_without_a_certificate_is_a_hostname_but_not_an_origin(): void
    {
        // An https origin the browser will never send is noise in a trust list.
        // The hostname is true either way.
        $deployment = $this->deploymentWithDomain('chakula.co.ke', sslEnabled: false);

        $this->assertNull($this->origins()->valueFor($deployment, 'ALLOWED_ORIGINS'));
        $this->assertSame('["chakula.co.ke","www.chakula.co.ke"]', $this->origins()->valueFor($deployment, 'ALLOWED_HOSTS'));
    }

    #[Test]
    public function a_domain_that_is_not_live_is_not_trusted(): void
    {
        $deployment = $this->deploymentWithDomain('chakula.co.ke', status: 'pending');

        $this->assertNull($this->origins()->valueFor($deployment, 'ALLOWED_ORIGINS'));
    }

    #[Test]
    public function a_setting_nobody_here_recognises_is_left_alone(): void
    {
        // Guessing at a name the platform does not know is how it breaks an
        // application quietly.
        $origins = $this->origins();

        $this->assertFalse($origins->supports('MPESA_SHORTCODE'));
        $this->assertFalse($origins->supports('DATABASE_URL'));
        $this->assertTrue($origins->supports('BACKEND_CORS_ORIGINS'));
        $this->assertTrue($origins->supports('CSRF_TRUSTED_ORIGINS'));
    }

    #[Test]
    public function it_fills_a_declared_setting_that_nobody_has_set(): void
    {
        $deployment = $this->deploymentWithDomain('chakula.co.ke');
        $env = ['DB_HOST' => 'db'];

        $filled = $this->origins()->fillUnset($deployment, ['ALLOWED_ORIGINS', 'MPESA_SHORTCODE'], $env);

        $this->assertSame(['ALLOWED_ORIGINS'], array_keys($filled));
        $this->assertSame('["https://chakula.co.ke","https://www.chakula.co.ke"]', $env['ALLOWED_ORIGINS']);
        $this->assertArrayNotHasKey('MPESA_SHORTCODE', $env);
    }

    #[Test]
    public function it_never_overwrites_a_value_the_customer_chose(): void
    {
        // Including one that is about to fail. A platform that silently
        // rewrites what somebody typed is worse to operate than one that
        // reports the problem and leaves the decision with them.
        $deployment = $this->deploymentWithDomain('chakula.co.ke');
        $env = ['ALLOWED_ORIGINS' => 'https://partner.example,https://chakula.co.ke'];

        $filled = $this->origins()->fillUnset($deployment, ['ALLOWED_ORIGINS'], $env);

        $this->assertSame([], $filled);
        $this->assertSame('https://partner.example,https://chakula.co.ke', $env['ALLOWED_ORIGINS']);
    }

    #[Test]
    public function a_service_with_no_live_domain_is_left_completely_alone(): void
    {
        $deployment = $this->deployment();
        $env = [];

        $this->assertSame([], $this->origins()->fillUnset($deployment, ['ALLOWED_ORIGINS'], $env));
        $this->assertSame([], $env);
    }

    #[Test]
    public function the_suggestion_names_the_value_and_not_just_the_problem(): void
    {
        $deployment = $this->deploymentWithDomain('chakula.co.ke');

        $suggestion = (string) $this->origins()->suggestion($deployment, ['ALLOWED_ORIGINS', 'MPESA_SHORTCODE']);

        $this->assertStringContainsString('ALLOWED_ORIGINS=["https://chakula.co.ke"', $suggestion);
        $this->assertStringNotContainsString('MPESA_SHORTCODE', $suggestion);
    }

    private function origins(): ContainerOriginSettingsService
    {
        return app(ContainerOriginSettingsService::class);
    }

    private function deploymentWithDomain(
        string $domain,
        bool $sslEnabled = true,
        string $status = 'active',
    ): ContainerDeployment {
        $deployment = $this->deployment();

        ContainerDomain::create([
            'container_deployment_id' => $deployment->id,
            'domain' => $domain,
            'purpose' => ContainerDomain::PURPOSE_WEB,
            'status' => $status,
            'ssl_enabled' => $sslEnabled,
        ]);

        return $deployment->fresh();
    }

    private function deployment(): ContainerDeployment
    {
        $node = Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
        ]);

        return ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-493-service-457-python',
            'status' => 'running',
        ]);
    }
}

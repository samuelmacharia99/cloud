<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Services\Provisioning\ContainerDeploymentService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The address a split project hands its Web container for the API.
 *
 * NEXT_PUBLIC_ and EXPO_PUBLIC_ values are downloaded by the visitor, so the
 * API container's own hostname and port cannot go there: the browser resolves
 * neither the name nor, over https, a plain http request. Only a bound domain
 * holding a certificate qualifies.
 */
class BrowserReachableApiUrlTest extends TestCase
{
    #[Test]
    public function it_prefers_the_domain_bound_as_the_api_endpoint(): void
    {
        $url = $this->resolve([
            ['domain' => 'app.example.com', 'purpose' => 'web', 'status' => 'active', 'ssl_enabled' => true],
            ['domain' => 'api.example.com', 'purpose' => 'api', 'status' => 'active', 'ssl_enabled' => true],
        ]);

        $this->assertSame('https://api.example.com', $url);
    }

    #[Test]
    public function it_falls_back_to_the_only_secured_domain(): void
    {
        $this->assertSame('https://app.example.com', $this->resolve([
            ['domain' => 'app.example.com', 'purpose' => 'web', 'status' => 'active', 'ssl_enabled' => true],
        ]));
    }

    #[Test]
    public function a_domain_without_a_certificate_is_not_offered_to_a_browser(): void
    {
        $this->assertNull($this->resolve([
            ['domain' => 'api.example.com', 'purpose' => 'api', 'status' => 'active', 'ssl_enabled' => false],
        ]));
    }

    #[Test]
    public function a_domain_that_is_not_active_yet_is_not_offered_either(): void
    {
        $this->assertNull($this->resolve([
            ['domain' => 'api.example.com', 'purpose' => 'api', 'status' => 'pending', 'ssl_enabled' => true],
        ]));
    }

    #[Test]
    public function nothing_bound_means_nothing_to_bake_into_the_bundle(): void
    {
        $this->assertNull($this->resolve([]));
    }

    /**
     * @param  list<array<string, mixed>>  $domains
     */
    private function resolve(array $domains): ?string
    {
        $deployment = new ContainerDeployment;
        $deployment->container_name = 'user-493-service-454-nodejs';
        $deployment->setRelation('domains', new Collection(array_map(
            fn (array $attributes): ContainerDomain => new ContainerDomain($attributes),
            $domains,
        )));

        return app(ContainerDeploymentService::class)->browserReachableApiUrl($deployment);
    }
}

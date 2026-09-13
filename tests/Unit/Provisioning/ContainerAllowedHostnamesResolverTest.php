<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Service;
use App\Services\Provisioning\ContainerAllowedHostnamesResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerAllowedHostnamesResolverTest extends TestCase
{
    #[Test]
    public function it_lists_bound_domains_the_platform_hostname_and_the_loopback_names(): void
    {
        $resolver = new ContainerAllowedHostnamesResolver(fn (ContainerDeployment $deployment) => $deployment->container_name.'.apps.example.test');

        $service = $this->serviceWithDomains(['Shop.Example.com', 'www.shop.example.com', 'https://api.shop.example.com/']);

        $this->assertSame(
            ['api.shop.example.com', 'shop.example.com', 'user-1-service-30-ospos.apps.example.test', 'www.shop.example.com', 'localhost', '127.0.0.1'],
            $resolver->hostnamesFor($service)
        );
        $this->assertSame(
            'api.shop.example.com,shop.example.com,user-1-service-30-ospos.apps.example.test,www.shop.example.com,localhost,127.0.0.1',
            $resolver->commaList($service)
        );
    }

    #[Test]
    public function a_stack_without_domains_or_a_platform_zone_still_allows_the_probes(): void
    {
        $resolver = new ContainerAllowedHostnamesResolver(fn () => null);

        $this->assertSame(['localhost', '127.0.0.1'], $resolver->hostnamesFor($this->serviceWithDomains([])));
    }

    #[Test]
    public function a_service_that_is_not_deployed_yet_allows_only_the_probes(): void
    {
        $resolver = new ContainerAllowedHostnamesResolver(fn () => 'never-called');
        $service = new Service;
        $service->setRelation('containerDeployment', null);

        $this->assertSame(['localhost', '127.0.0.1'], $resolver->hostnamesFor($service));
    }

    /**
     * @param  list<string>  $domains
     */
    private function serviceWithDomains(array $domains): Service
    {
        $deployment = new ContainerDeployment(['container_name' => 'user-1-service-30-ospos']);
        $deployment->setRelation('domains', collect(array_map(fn (string $domain) => new ContainerDomain(['domain' => $domain]), $domains)));

        $service = new Service;
        $service->setRelation('containerDeployment', $deployment);

        return $service;
    }
}

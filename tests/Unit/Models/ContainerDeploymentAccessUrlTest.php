<?php

namespace Tests\Unit\Models;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The URL the platform hands out for a stack. A customer's own domain wins,
 * the platform hostname comes next, and http://node:port is only offered for
 * a stack that still publishes on the public interface, because an isolated
 * stack does not answer there.
 */
class ContainerDeploymentAccessUrlTest extends TestCase
{
    #[Test]
    public function a_customer_domain_beats_the_platform_hostname_which_beats_the_node_port(): void
    {
        $deployment = $this->deployment([
            $this->domain('user-1-service-2.apps.example.com', ContainerDomain::PURPOSE_PLATFORM),
            $this->domain('shop.example.com'),
        ]);
        $this->assertSame('https://shop.example.com', $deployment->getAccessUrl());

        $deployment = $this->deployment([
            $this->domain('user-1-service-2.apps.example.com', ContainerDomain::PURPOSE_PLATFORM),
            $this->domain('pending.example.com', ContainerDomain::PURPOSE_WEB, 'pending'),
        ]);
        $this->assertSame('https://user-1-service-2.apps.example.com', $deployment->getAccessUrl());
    }

    #[Test]
    public function the_node_port_remains_the_last_resort_so_installers_always_have_a_url(): void
    {
        // Legacy and isolated alike: installers write this into the app, and
        // returning nothing failed every WordPress install on a host with no
        // apps zone configured. The platform hostname is what makes it public.
        $legacy = $this->deployment([], network_subnet: null);
        $this->assertSame('http://node-1.example.com:31012', $legacy->getAccessUrl());

        $isolated = $this->deployment([], network_subnet: '10.210.0.0/24');
        $this->assertSame('http://node-1.example.com:31012', $isolated->getAccessUrl());

        $withDomainColumn = $this->deployment([], network_subnet: '10.210.0.0/24', domain: 'legacy.example.com');
        $this->assertSame('https://legacy.example.com', $withDomainColumn->getAccessUrl());
    }

    #[Test]
    public function the_preferred_domain_is_the_customers_even_when_the_platform_row_came_first(): void
    {
        $deployment = $this->deployment([
            $this->domain('user-1-service-2.apps.example.com', ContainerDomain::PURPOSE_PLATFORM),
            $this->domain('failed.example.com', ContainerDomain::PURPOSE_WEB, 'failed'),
            $this->domain('shop.example.com'),
        ]);

        $this->assertSame('shop.example.com', $deployment->preferredDomain()?->domain);
        $this->assertSame('shop.example.com', $deployment->primaryDomain()?->domain);
        $this->assertSame('shop.example.com', $deployment->probeHostHeader());

        $platformOnly = $this->deployment([$this->domain('user-1-service-2.apps.example.com', ContainerDomain::PURPOSE_PLATFORM)]);
        $this->assertSame('user-1-service-2.apps.example.com', $platformOnly->preferredDomain()?->domain);
        $this->assertNull($this->deployment([])->preferredDomain());
    }

    /**
     * @param  list<ContainerDomain>  $domains
     */
    private function deployment(array $domains, ?string $network_subnet = null, ?string $domain = null): ContainerDeployment
    {
        $deployment = new ContainerDeployment([
            'assigned_port' => 31012,
            'network_subnet' => $network_subnet,
            'domain' => $domain,
        ]);
        $deployment->setRelation('domains', new Collection($domains));
        $deployment->setRelation('node', new Node(['hostname' => 'node-1.example.com', 'ip_address' => '10.0.0.1']));

        return $deployment;
    }

    private function domain(string $hostname, string $purpose = ContainerDomain::PURPOSE_WEB, string $status = 'active'): ContainerDomain
    {
        return new ContainerDomain(['domain' => $hostname, 'purpose' => $purpose, 'status' => $status]);
    }
}

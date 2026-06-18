<?php

namespace Tests\Unit\Services;

use App\Models\Node;
use App\Models\Setting;
use App\Services\NodeNameserverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeNameserverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_defaults_use_container_host_when_directadmin_missing(): void
    {
        Node::factory()->create([
            'type' => 'container_host',
            'is_active' => true,
            'nameserver_1' => 'ns1.containers.example',
            'nameserver_2' => 'ns2.containers.example',
        ]);

        $defaults = app(NodeNameserverService::class)->platformDefaults();

        $this->assertSame('ns1.containers.example', $defaults['ns1']);
        $this->assertSame('ns2.containers.example', $defaults['ns2']);
    }

    public function test_platform_defaults_prefer_directadmin_over_container_host(): void
    {
        Node::factory()->create([
            'type' => 'container_host',
            'is_active' => true,
            'nameserver_1' => 'ns1.containers.example',
        ]);

        Node::factory()->create([
            'type' => 'directadmin',
            'is_active' => true,
            'nameserver_1' => 'ns1.da.example',
            'nameserver_2' => 'ns2.da.example',
        ]);

        $defaults = app(NodeNameserverService::class)->platformDefaults();

        $this->assertSame('ns1.da.example', $defaults['ns1']);
        $this->assertSame('ns2.da.example', $defaults['ns2']);
    }

    public function test_for_node_returns_container_host_nameservers(): void
    {
        $node = Node::factory()->create([
            'type' => 'container_host',
            'nameserver_1' => 'ns1.node.example',
            'nameserver_2' => 'ns2.node.example',
        ]);

        $nameservers = app(NodeNameserverService::class)->forNode($node);

        $this->assertSame('ns1.node.example', $nameservers['ns1']);
        $this->assertSame('ns2.node.example', $nameservers['ns2']);
    }

    public function test_platform_defaults_fall_back_to_settings(): void
    {
        Setting::updateOrCreate(['key' => 'domain_ns1'], ['value' => 'ns1.platform.example']);
        Setting::updateOrCreate(['key' => 'domain_ns2'], ['value' => 'ns2.platform.example']);

        $defaults = app(NodeNameserverService::class)->platformDefaults();

        $this->assertSame('ns1.platform.example', $defaults['ns1']);
        $this->assertSame('ns2.platform.example', $defaults['ns2']);
    }
}

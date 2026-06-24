<?php

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDirectAdminNodeTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_directadmin_node_can_be_created_with_all_cards(): void
    {
        $response = $this->actingAs($this->adminUser())->post(route('admin.nodes.store'), [
            'type' => 'directadmin',
            'name' => 'DA East',
            'hostname' => 'da-east.example.com',
            'ip_address' => '10.0.0.10',
            'da_port' => '2222',
            'ssh_username' => 'root',
            'ssh_password' => 'ssh-secret',
            'da_admin_username' => 'admin',
            'da_login_key' => 'login-key-secret',
            'nameserver_1' => ' ns1.example.com ',
            'nameserver_2' => 'ns2.example.com',
            'region' => 'US-East',
            'datacenter' => 'DC1',
            'description' => 'Primary DA node',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.nodes.index'));

        $node = Node::query()->where('hostname', 'da-east.example.com')->first();

        $this->assertNotNull($node);
        $this->assertSame('directadmin', $node->type);
        $this->assertSame(0, $node->cpu_cores);
        $this->assertSame(0, $node->ram_gb);
        $this->assertSame(0, $node->storage_gb);
        $this->assertSame('2222', $node->da_port);
        $this->assertSame('2222', $node->ssh_port);
        $this->assertSame('https://da-east.example.com:2222', $node->api_url);
        $this->assertSame('ns1.example.com', $node->nameserver_1);
        $this->assertSame('ns2.example.com', $node->nameserver_2);
        $this->assertSame('US-East', $node->region);
        $this->assertSame('DC1', $node->datacenter);
        $this->assertSame('Primary DA node', $node->description);
        $this->assertTrue($node->is_active);
        $this->assertSame('admin', $node->da_admin_username);
        $this->assertSame('login-key-secret', $node->da_login_key);
        $this->assertSame('ssh-secret', $node->ssh_password);
    }

    public function test_directadmin_node_update_preserves_secrets_and_rebuilds_api_url(): void
    {
        $node = Node::factory()->directAdmin()->create([
            'hostname' => 'da-old.example.com',
            'ip_address' => '10.0.0.11',
            'api_url' => 'https://da-old.example.com:2222',
            'nameserver_1' => 'ns1.old.com',
            'nameserver_2' => 'ns2.old.com',
        ]);

        $response = $this->actingAs($this->adminUser())->put(route('admin.nodes.update', $node), [
            'name' => 'DA Updated',
            'hostname' => 'da-new.example.com',
            'ip_address' => '10.0.0.12',
            'type' => 'directadmin',
            'status' => 'online',
            'cpu_cores' => 0,
            'ram_gb' => 0,
            'storage_gb' => 0,
            'da_port' => '3333',
            'ssh_username' => 'root',
            'ssh_password' => '',
            'da_admin_username' => 'admin2',
            'da_login_key' => '',
            'nameserver_1' => ' ns1.new.com ',
            'nameserver_2' => 'ns2.new.com',
            'region' => 'EU-West',
            'datacenter' => 'DC2',
            'description' => 'Updated notes',
            'verify_ssl' => '1',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.nodes.show', $node));

        $node->refresh();

        $this->assertSame('DA Updated', $node->name);
        $this->assertSame('da-new.example.com', $node->hostname);
        $this->assertSame('10.0.0.12', $node->ip_address);
        $this->assertSame('online', $node->status);
        $this->assertSame('3333', $node->da_port);
        $this->assertSame('3333', $node->ssh_port);
        $this->assertSame('https://da-new.example.com:3333', $node->api_url);
        $this->assertSame('ns1.new.com', $node->nameserver_1);
        $this->assertSame('ns2.new.com', $node->nameserver_2);
        $this->assertSame('EU-West', $node->region);
        $this->assertSame('DC2', $node->datacenter);
        $this->assertSame('Updated notes', $node->description);
        $this->assertTrue($node->verify_ssl);
        $this->assertTrue($node->is_active);
        $this->assertSame('admin2', $node->da_admin_username);
        $this->assertSame('test-login-key', $node->da_login_key);
        $this->assertSame('ssh-secret', $node->ssh_password);
    }
}

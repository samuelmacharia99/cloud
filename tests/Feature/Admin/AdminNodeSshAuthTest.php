<?php

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\User;
use App\Services\Provisioning\NodeHardwareProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\EC;
use Tests\TestCase;

class AdminNodeSshAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_offers_password_or_key_for_a_mailcow_node(): void
    {
        $node = Node::factory()->mailcow()->create(['ssh_username' => null, 'ssh_password' => null]);

        $this->actingAs($this->admin())
            ->get(route('admin.nodes.edit', $node))
            ->assertOk()
            ->assertSee('SSH Authentication')
            ->assertSee('SSH private key')
            ->assertSee('name="ssh_private_key"', false)
            ->assertSee('No SSH secret is stored');
    }

    public function test_mailcow_node_update_stores_a_private_key_and_keeps_the_api_token(): void
    {
        $node = Node::factory()->mailcow()->create(['api_token' => 'keep-me', 'ssh_password' => null]);
        $key = EC::createKey('Ed25519')->toString('OpenSSH');

        $this->actingAs($this->admin())
            ->put(route('admin.nodes.update', $node), $this->payload($node, [
                'ssh_username' => 'root',
                'ssh_auth_method' => 'key',
                'ssh_private_key' => $key,
                'ssh_key_passphrase' => '',
                'ssh_password' => '',
            ]))
            ->assertRedirect(route('admin.nodes.show', $node))
            ->assertSessionHasNoErrors();

        $node->refresh();
        $this->assertSame('key', $node->ssh_auth_method);
        $this->assertSame(trim($key), $node->ssh_private_key);
        $this->assertNull($node->ssh_key_passphrase);
        $this->assertSame('keep-me', $node->api_token);
        $this->assertSame('root', $node->ssh_username);
        $this->assertTrue($node->hasSshCredentials());
    }

    public function test_blank_secrets_keep_what_is_stored_and_the_method_can_switch(): void
    {
        $key = trim(EC::createKey('Ed25519')->toString('OpenSSH'));
        $node = Node::factory()->containerHost()->create([
            'ssh_username' => 'root',
            'ssh_auth_method' => 'password',
            'ssh_password' => 'stored-password',
            'ssh_private_key' => $key,
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.nodes.update', $node), $this->payload($node, [
                'ssh_auth_method' => 'key',
                'ssh_private_key' => '',
                'ssh_password' => '',
            ]))
            ->assertRedirect(route('admin.nodes.show', $node))
            ->assertSessionHasNoErrors();

        $node->refresh();
        $this->assertSame('key', $node->ssh_auth_method);
        $this->assertSame(trim($key), $node->ssh_private_key);
        $this->assertSame('stored-password', $node->ssh_password);
    }

    public function test_a_key_that_does_not_parse_is_rejected(): void
    {
        $node = Node::factory()->containerHost()->create(['ssh_username' => 'root', 'ssh_password' => 'stored']);

        $this->actingAs($this->admin())
            ->from(route('admin.nodes.edit', $node))
            ->put(route('admin.nodes.update', $node), $this->payload($node, [
                'ssh_auth_method' => 'key',
                'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nnope\n-----END OPENSSH PRIVATE KEY-----",
            ]))
            ->assertRedirect(route('admin.nodes.edit', $node))
            ->assertSessionHasErrors('ssh_private_key');

        $this->assertNull($node->fresh()->ssh_private_key);
        $this->assertSame('password', $node->fresh()->sshAuthMethod());
    }

    public function test_container_host_cannot_choose_key_auth_without_a_key(): void
    {
        $node = Node::factory()->containerHost()->create(['ssh_username' => 'root', 'ssh_password' => 'stored', 'ssh_private_key' => null]);

        $this->actingAs($this->admin())
            ->from(route('admin.nodes.edit', $node))
            ->put(route('admin.nodes.update', $node), $this->payload($node, [
                'ssh_auth_method' => 'key',
                'ssh_private_key' => '',
            ]))
            ->assertRedirect(route('admin.nodes.edit', $node))
            ->assertSessionHasErrors('ssh_private_key');

        $this->assertSame('password', $node->fresh()->sshAuthMethod());
    }

    public function test_container_host_can_be_created_with_a_key_and_no_password(): void
    {
        $this->mock(NodeHardwareProbeService::class, function ($mock) {
            $mock->shouldReceive('probeAndPersist')->once()->andReturnUsing(function (Node $node) {
                $node->update(['cpu_cores' => 2, 'ram_gb' => 4, 'storage_gb' => 40, 'status' => 'online']);

                return ['success' => true, 'reason' => 'ok', 'message' => 'ok', 'cpu_cores' => 2, 'ram_gb' => 4, 'storage_gb' => 40, 'ram_used_gb' => 1, 'storage_used_gb' => 5, 'cpu_used' => 1, 'uptime' => 'up', 'load_average' => 0.1];
            });
        });
        $key = EC::createKey('Ed25519')->toString('OpenSSH');

        $this->actingAs($this->admin())->post(route('admin.nodes.store'), [
            'type' => 'container_host',
            'name' => 'App Host 02',
            'hostname' => 'app-02.example.com',
            'ip_address' => '10.10.10.12',
            'ssh_port' => '22',
            'ssh_username' => 'deploy',
            'ssh_auth_method' => 'key',
            'ssh_private_key' => $key,
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $node = Node::query()->where('hostname', 'app-02.example.com')->firstOrFail();
        $this->assertSame('key', $node->ssh_auth_method);
        $this->assertSame(trim($key), $node->ssh_private_key);
        $this->assertNull($node->ssh_password);
        $this->assertTrue($node->hasSshCredentials());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Node $node, array $overrides = []): array
    {
        return array_merge([
            'name' => $node->name,
            'hostname' => $node->hostname,
            'ip_address' => $node->ip_address,
            'type' => $node->type,
            'status' => 'online',
            'cpu_cores' => $node->type === 'mailcow' ? 0 : 2,
            'ram_gb' => $node->type === 'mailcow' ? 0 : 4,
            'storage_gb' => $node->type === 'mailcow' ? 0 : 40,
            'ssh_port' => '22',
            'ssh_username' => $node->ssh_username ?? 'root',
            'api_url' => $node->api_url,
            'api_token' => '',
            'verify_ssl' => '1',
            'is_active' => '1',
        ], $overrides);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }
}

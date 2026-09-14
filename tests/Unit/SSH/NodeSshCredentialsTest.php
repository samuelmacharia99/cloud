<?php

namespace Tests\Unit\SSH;

use App\Models\Node;
use App\Services\SSH\NodeSshCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use Tests\TestCase;

class NodeSshCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_method_tries_the_key_first_then_falls_back_to_the_password(): void
    {
        $node = Node::factory()->mailcow()->create([
            'ssh_username' => 'root',
            'ssh_auth_method' => 'key',
            'ssh_password' => 'old-password',
            'ssh_private_key' => $this->privateKey(),
        ]);

        $candidates = NodeSshCredentials::loginCandidates($node);

        $this->assertCount(2, $candidates);
        $this->assertInstanceOf(PrivateKey::class, $candidates[0]);
        $this->assertSame('old-password', $candidates[1]);
        $this->assertSame('key', $node->sshAuthMethod());
        $this->assertTrue($node->hasSshCredentials());
        $this->assertNotNull(NodeSshCredentials::fingerprint($node));
    }

    public function test_password_method_tries_the_password_first(): void
    {
        $node = Node::factory()->containerHost()->create([
            'ssh_username' => 'root',
            'ssh_auth_method' => 'password',
            'ssh_password' => 'secret',
            'ssh_private_key' => $this->privateKey(),
        ]);

        $candidates = NodeSshCredentials::loginCandidates($node);

        $this->assertSame('secret', $candidates[0]);
        $this->assertInstanceOf(PrivateKey::class, $candidates[1]);
    }

    public function test_legacy_rows_infer_the_method_and_accept_a_key_in_the_directadmin_column(): void
    {
        $legacy = Node::factory()->containerHost()->create([
            'ssh_username' => 'root',
            'ssh_auth_method' => null,
            'ssh_password' => null,
            'da_login_key' => $this->privateKey(),
        ]);
        $this->assertSame('key', $legacy->sshAuthMethod());
        $this->assertTrue($legacy->hasSshPrivateKey());
        $this->assertTrue($legacy->hasSshCredentials());
        $this->assertInstanceOf(PrivateKey::class, NodeSshCredentials::loginCandidates($legacy)[0]);

        $directAdmin = Node::factory()->directAdmin()->create([
            'ssh_username' => 'root',
            'ssh_auth_method' => null,
            'ssh_password' => 'da-root',
            'da_login_key' => 'not-a-key-just-an-api-login-key',
        ]);
        $this->assertSame('password', $directAdmin->sshAuthMethod());
        $this->assertFalse($directAdmin->hasSshPrivateKey());
        $this->assertSame(['da-root'], NodeSshCredentials::loginCandidates($directAdmin));
    }

    public function test_a_node_without_any_secret_has_no_credentials(): void
    {
        $node = Node::factory()->mailcow()->create([
            'ssh_username' => 'root',
            'ssh_password' => null,
            'ssh_private_key' => null,
            'da_login_key' => null,
        ]);

        $this->assertFalse($node->hasSshCredentials());
        $this->assertSame([], NodeSshCredentials::loginCandidates($node));
    }

    public function test_an_unparseable_key_is_reported_as_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSH key format invalid');

        NodeSshCredentials::loadPrivateKey('-----BEGIN OPENSSH PRIVATE KEY-----'."\n".'garbage'."\n".'-----END OPENSSH PRIVATE KEY-----');
    }

    public function test_a_public_key_is_rejected(): void
    {
        $public = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('public key');

        NodeSshCredentials::loadPrivateKey($public);
    }

    public function test_an_encrypted_key_needs_its_passphrase(): void
    {
        $encrypted = EC::createKey('Ed25519')->withPassword('pass-1')->toString('OpenSSH');

        $this->assertInstanceOf(PrivateKey::class, NodeSshCredentials::loadPrivateKey($encrypted, 'pass-1'));

        $this->expectException(\InvalidArgumentException::class);
        NodeSshCredentials::loadPrivateKey($encrypted, 'wrong');
    }

    private function privateKey(): string
    {
        return EC::createKey('Ed25519')->toString('OpenSSH');
    }
}

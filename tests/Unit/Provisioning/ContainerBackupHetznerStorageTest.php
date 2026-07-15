<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\ContainerBackupService;
use App\Services\Provisioning\HetznerStorageBoxClient;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ContainerBackupHetznerStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_hetzner_client_builds_remote_path_and_reads_driver(): void
    {
        Setting::setValue('backup_storage_driver', 'hetzner');
        Setting::setValue('hetzner_storage_path', '/backups/containers');

        $client = new HetznerStorageBoxClient;

        $this->assertTrue($client->usesHetzner());
        $this->assertSame('/backups/containers/backup-1.tar.gz', $client->remotePathFor('backup-1.tar.gz'));
    }

    public function test_hetzner_client_decrypts_stored_password(): void
    {
        Setting::setValue('hetzner_storage_host', 'u123.your-storagebox.de');
        Setting::setValue('hetzner_storage_username', 'u123');
        Setting::setValue('hetzner_storage_password', Crypt::encryptString('secret-pass'));

        $client = new HetznerStorageBoxClient;
        $method = new ReflectionMethod(HetznerStorageBoxClient::class, 'password');
        $method->setAccessible(true);

        $this->assertTrue($client->isConfigured());
        $this->assertSame('secret-pass', $method->invoke($client));
    }

    public function test_delete_backup_removes_file_from_hetzner(): void
    {
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
        ]);
        $backup = ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $service->id,
            'node_id' => $node->id,
            'backup_path' => '/backups/containers/backup-x.tar.gz',
            'storage_driver' => 'hetzner',
            'status' => 'completed',
        ]);

        $hetzner = Mockery::mock(HetznerStorageBoxClient::class);
        $hetzner->shouldReceive('delete')->once()->with('/backups/containers/backup-x.tar.gz');
        $hetzner->shouldReceive('disconnect')->once();

        $service = new ContainerBackupService($hetzner);
        $service->deleteBackup($backup);

        $this->assertSame('deleted', $backup->fresh()->status);
    }

    public function test_offload_to_hetzner_uploads_and_deletes_local_node_copy(): void
    {
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $serviceModel = Service::factory()->create([
            'product_id' => $product->id,
            'node_id' => $node->id,
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $serviceModel->id,
            'node_id' => $node->id,
        ]);
        $backup = ContainerBackup::factory()->create([
            'container_deployment_id' => $deployment->id,
            'service_id' => $serviceModel->id,
            'node_id' => $node->id,
            'backup_name' => 'backup-9-test',
            'backup_path' => '/opt/talksasa/backups/backup-9-test.tar.gz',
            'storage_driver' => 'node',
            'status' => 'running',
        ]);

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('downloadToLocal')
            ->once()
            ->withArgs(function (string $remote, string $local) {
                touch($local);

                return $remote === '/opt/talksasa/backups/backup-9-test.tar.gz';
            });
        $ssh->shouldReceive('reconnect')->once();
        $ssh->shouldReceive('exec')
            ->once()
            ->withArgs(fn (string $cmd) => str_contains($cmd, 'rm -f'));

        $hetzner = Mockery::mock(HetznerStorageBoxClient::class);
        $hetzner->shouldReceive('isConfigured')->andReturn(true);
        $hetzner->shouldReceive('remotePathFor')
            ->with('backup-9-test.tar.gz')
            ->andReturn('/backups/containers/backup-9-test.tar.gz');
        $hetzner->shouldReceive('uploadFromLocal')
            ->once()
            ->withArgs(fn (string $local, string $remote) => $remote === '/backups/containers/backup-9-test.tar.gz' && is_file($local));

        $backupService = new ContainerBackupService($hetzner);
        $method = new ReflectionMethod(ContainerBackupService::class, 'offloadToHetzner');
        $method->setAccessible(true);

        $remote = $method->invoke($backupService, $ssh, $backup, '/opt/talksasa/backups/backup-9-test.tar.gz');

        $this->assertSame('/backups/containers/backup-9-test.tar.gz', $remote);
    }
}

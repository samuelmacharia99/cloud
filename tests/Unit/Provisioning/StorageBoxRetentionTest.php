<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerBackup;
use App\Models\Node;
use App\Models\Setting;
use App\Services\Provisioning\ContainerBackupService;
use App\Services\Provisioning\HetznerStorageBoxClient;
use App\Services\Provisioning\StorageBoxRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class StorageBoxRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_hetzner_client_parses_df_output(): void
    {
        $client = new HetznerStorageBoxClient;
        $method = new ReflectionMethod(HetznerStorageBoxClient::class, 'parseDfOutput');
        $method->setAccessible(true);

        $parsed = $method->invoke($client, <<<'TXT'
Filesystem      Size    Used   Avail Capacity  Mounted on
u123456         1.0T    5.0G 1023G     1%    /home
TXT);

        $this->assertSame('u123456', $parsed['filesystem']);
        $this->assertSame('1.0T', $parsed['size']);
        $this->assertSame('5.0G', $parsed['used']);
        $this->assertSame('1023G', $parsed['avail']);
        $this->assertSame(1, $parsed['capacity_percent']);
        $this->assertSame('/home', $parsed['mount']);
    }

    public function test_retention_service_purges_old_completed_backups(): void
    {
        Setting::setValue('hetzner_storage_retention_days', '30');

        $node = Node::factory()->containerHost()->create();
        $old = ContainerBackup::factory()->create([
            'node_id' => $node->id,
            'storage_driver' => 'hetzner',
            'status' => 'completed',
            'backup_path' => 'backups/containers/old.tar.gz',
            'completed_at' => now()->subDays(45),
        ]);
        $recent = ContainerBackup::factory()->create([
            'node_id' => $node->id,
            'storage_driver' => 'hetzner',
            'status' => 'completed',
            'backup_path' => 'backups/containers/recent.tar.gz',
            'completed_at' => now()->subDays(5),
        ]);

        $hetzner = Mockery::mock(HetznerStorageBoxClient::class);
        $hetzner->shouldReceive('isConfigured')->andReturn(true);
        $hetzner->shouldReceive('delete')->once()->with('backups/containers/old.tar.gz');
        $hetzner->shouldReceive('disconnect')->once();

        $service = new StorageBoxRetentionService(new ContainerBackupService($hetzner), $hetzner);

        $result = $service->purgeOlderThan(30);

        $this->assertSame(1, $result['purged_count']);
        $this->assertNull(ContainerBackup::find($old->id));
        $this->assertNotNull(ContainerBackup::find($recent->id));
    }
}

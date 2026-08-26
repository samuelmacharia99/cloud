<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerBackup;
use App\Models\Node;
use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\HetznerStorageBoxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

class AdminStorageBoxRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function configureBox(): void
    {
        Setting::setValue('backup_storage_driver', 'hetzner');
        Setting::setValue('hetzner_storage_host', 'u456789.your-storagebox.de');
        Setting::setValue('hetzner_storage_username', 'u456789');
        Setting::setValue('hetzner_storage_password', Crypt::encryptString('secret-pass'));
        Setting::setValue('hetzner_storage_retention_days', '30');
    }

    public function test_storage_box_show_page_displays_capacity_and_retention(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->configureBox();

        $mock = Mockery::mock(HetznerStorageBoxClient::class)->makePartial();
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('diskUsage')->andReturn([
            'available' => true,
            'total_human' => '1.0T',
            'used_human' => '12G',
            'available_human' => '1012G',
            'capacity_percent' => 2,
            'total_bytes' => 1099511627776,
            'used_bytes' => 12884901888,
            'available_bytes' => 1086626725888,
            'filesystem' => 'u456789',
            'mount_point' => '/home',
            'provider' => 'Hetzner Storage Box',
            'access' => 'SFTP/SSH port 23',
            'fetched_at' => now()->toIso8601String(),
        ]);
        $this->app->instance(HetznerStorageBoxClient::class, $mock);

        $this->actingAs($admin)
            ->get(route('admin.storage-boxes.show', 'hetzner-primary'))
            ->assertOk()
            ->assertSee('Live capacity')
            ->assertSee('1.0T')
            ->assertSee('1012G')
            ->assertSee('Purge old backups')
            ->assertSee('30 days');
    }

    public function test_admin_can_purge_old_storage_box_backups(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->configureBox();

        $node = Node::factory()->containerHost()->create();
        ContainerBackup::factory()->create([
            'node_id' => $node->id,
            'storage_driver' => 'hetzner',
            'status' => 'completed',
            'backup_path' => 'backups/containers/stale.tar.gz',
            'size_bytes' => 1024,
            'completed_at' => now()->subDays(40),
        ]);

        $hetzner = Mockery::mock(HetznerStorageBoxClient::class)->makePartial();
        $hetzner->shouldReceive('delete')->once()->with('backups/containers/stale.tar.gz');
        $hetzner->shouldReceive('disconnect')->once();
        $hetzner->shouldReceive('clearDiskUsageCache')->once();
        $hetzner->shouldReceive('diskUsage')->andReturn(['available' => false, 'error' => 'skipped in test']);
        $this->app->instance(HetznerStorageBoxClient::class, $hetzner);

        $this->actingAs($admin)
            ->post(route('admin.storage-boxes.purge-retention', 'hetzner-primary'), [
                'days' => 30,
                'confirm' => '1',
            ])
            ->assertRedirect(route('admin.storage-boxes.show', 'hetzner-primary'))
            ->assertSessionHas('success');

        $this->assertSame(0, ContainerBackup::count());
    }
}

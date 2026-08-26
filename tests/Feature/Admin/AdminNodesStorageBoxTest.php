<?php

namespace Tests\Feature\Admin;

use App\Models\ContainerBackup;
use App\Models\Node;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AdminNodesStorageBoxTest extends TestCase
{
    use RefreshDatabase;

    public function test_nodes_index_lists_configured_storage_box_from_provisioning_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Setting::setValue('backup_storage_driver', 'hetzner');
        Setting::setValue('hetzner_storage_host', 'u456789.your-storagebox.de');
        Setting::setValue('hetzner_storage_port', '23');
        Setting::setValue('hetzner_storage_username', 'u456789');
        Setting::setValue('hetzner_storage_password', Crypt::encryptString('secret-pass'));
        Setting::setValue('hetzner_storage_path', 'backups/containers');

        $this->actingAs($admin)
            ->get(route('admin.nodes.index'))
            ->assertOk()
            ->assertSee('Storage Boxes')
            ->assertSee('u456789 @ u456789.your-storagebox.de')
            ->assertSee('Active backup target')
            ->assertSee('backups/containers');
    }

    public function test_storage_box_filter_shows_only_storage_boxes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Setting::setValue('backup_storage_driver', 'hetzner');
        Setting::setValue('hetzner_storage_host', 'u456789.your-storagebox.de');
        Setting::setValue('hetzner_storage_username', 'u456789');
        Setting::setValue('hetzner_storage_password', Crypt::encryptString('secret-pass'));

        $this->actingAs($admin)
            ->get(route('admin.nodes.index', ['type' => 'storage_box']))
            ->assertOk()
            ->assertSee('u456789 @ u456789.your-storagebox.de');
    }

    public function test_nodes_index_shows_backup_usage_for_storage_box(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Setting::setValue('backup_storage_driver', 'hetzner');
        Setting::setValue('hetzner_storage_host', 'u456789.your-storagebox.de');
        Setting::setValue('hetzner_storage_username', 'u456789');
        Setting::setValue('hetzner_storage_password', Crypt::encryptString('secret-pass'));

        $node = Node::factory()->containerHost()->create();

        ContainerBackup::factory()->create([
            'node_id' => $node->id,
            'storage_driver' => 'hetzner',
            'status' => 'completed',
            'size_bytes' => 5 * 1024 * 1024,
            'completed_at' => now()->subHour(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.nodes.index'))
            ->assertOk()
            ->assertSee('Archives:')
            ->assertSee('1')
            ->assertSee('5 MB');
    }
}

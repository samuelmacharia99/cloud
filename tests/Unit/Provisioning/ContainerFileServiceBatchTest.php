<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerFileAuditLog;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerFileService;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerFileServiceBatchTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/opt/talksasa/containers/user-1-service-1-laravel/app';

    public function test_batch_delete_runs_one_rm_per_chunk_refuses_the_root_and_audits(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('execWithStatus')
            ->once()
            ->withArgs(function (string $command) {
                return str_starts_with($command, 'rm -rf -- ')
                    && str_contains($command, escapeshellarg(self::BASE.'/old'))
                    && str_contains($command, escapeshellarg(self::BASE.'/cache/a b.log'));
            })
            ->andReturn(['output' => '', 'status' => 0]);

        $result = (new ContainerFileService($ssh))->batchDelete($service, $deployment, ['/old', '/cache/a b.log', '/', '/..'], $user, '10.0.0.1');

        $this->assertSame(['/old', '/cache/a b.log'], $result['deleted']);
        $this->assertArrayHasKey('/', $result['failed']);
        $this->assertStringContainsString('root cannot be deleted', $result['failed']['/']);
        // ".." is normalised away, so this also resolves to the root and is refused.
        $this->assertArrayHasKey('/..', $result['failed']);

        $log = ContainerFileAuditLog::query()->where('action', 'batch_delete')->first();
        $this->assertNotNull($log);
        $this->assertSame(2, $log->metadata['count']);
        $this->assertSame(['/old', '/cache/a b.log'], $log->metadata['paths']);
    }

    public function test_batch_delete_reports_survivors_when_rm_fails(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('execWithStatus')->once()->andReturn(['output' => 'rm: cannot remove: Permission denied', 'status' => 1]);
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/\[ -e .*gone/'), 10)->andReturn('no');
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/\[ -e .*stuck/'), 10)->andReturn('yes');

        $result = (new ContainerFileService($ssh))->batchDelete($service, $deployment, ['/gone', '/stuck'], $user, '10.0.0.1');

        $this->assertSame(['/gone'], $result['deleted']);
        $this->assertSame(['/stuck' => 'rm: cannot remove: Permission denied'], $result['failed']);
    }

    public function test_move_skips_conflicts_and_self_nesting_and_moves_the_rest(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/^\[ -d .*archive\' \] && echo yes/'), 10)->andReturn('yes');
        // "exists" probes: notes.txt already at the destination, photo.jpg does not.
        $ssh->shouldReceive('exec')->with(Mockery::pattern('#\[ -e .*archive/notes\.txt#'), 10)->andReturn('yes');
        $ssh->shouldReceive('exec')->with(Mockery::pattern('#\[ -e .*archive/photo\.jpg#'), 10)->andReturn('no');
        $ssh->shouldReceive('execWithStatus')
            ->once()
            ->withArgs(fn (string $command) => str_starts_with($command, 'mv -n -- ') && str_contains($command, 'photo.jpg'))
            ->andReturn(['output' => '', 'status' => 0]);

        $result = (new ContainerFileService($ssh))->moveOrCopy(
            $service,
            $deployment,
            ['/photo.jpg', '/notes.txt', '/archive', '/archive/sub'],
            '/archive',
            false,
            $user,
            '10.0.0.1',
        );

        $this->assertSame(['/photo.jpg'], $result['done']);
        $this->assertSame(['/notes.txt'], $result['conflicts']);
        $this->assertStringContainsString('into itself', $result['failed']['/archive']);
        $this->assertStringContainsString('Already in that folder', $result['failed']['/archive/sub']);
        $this->assertSame('/archive', $result['destination']);
        $this->assertSame('move', ContainerFileAuditLog::query()->latest('id')->value('action'));
    }

    public function test_copy_uses_cp_and_refuses_a_missing_destination(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/^\[ -d .*nowhere/'), 10)->andReturn('no');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Destination folder does not exist');

        (new ContainerFileService($ssh))->moveOrCopy($service, $deployment, ['/a'], '/nowhere', true, $user, '10.0.0.1');
    }

    public function test_assert_has_room_checks_host_free_space_then_the_plan(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture(diskGb: 1.0);
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^df -B1/'), 20)->andReturn(['output' => (string) (50 * 1024 * 1024 * 1024), 'status' => 0]);
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^du -sb/'), 60)->andReturn(['output' => (string) (900 * 1024 * 1024), 'status' => 0]);

        $files = new ContainerFileService($ssh);
        $files->assertHasRoom($deployment, 100 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough space on your plan');
        $files->assertHasRoom($deployment, 200 * 1024 * 1024);
    }

    public function test_assert_has_room_refuses_when_the_host_is_full(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^df -B1/'), 20)->andReturn(['output' => '1024', 'status' => 0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not enough space on the host');

        (new ContainerFileService($ssh))->assertHasRoom($deployment, 4096);
    }

    public function test_streamed_upload_writes_from_the_temp_file_and_chowns_for_www_data_stacks(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^df -B1/'), 20)->andReturn(['output' => '999999999', 'status' => 0]);
        $file = UploadedFile::fake()->create('Site Backup.zip', 12);
        $ssh->shouldReceive('uploadFromLocal')
            ->once()
            ->with($file->getRealPath(), self::BASE.'/uploads/Site Backup.zip', null, 900);
        $ssh->shouldReceive('exec')->once()->with("chown -R '33:33' '".self::BASE."/uploads/Site Backup.zip'", 300)->andReturn('');

        $relPath = (new ContainerFileService($ssh))->uploadStreamed($service, $deployment, '/uploads', $file, $user, '10.0.0.1');

        $this->assertSame('/uploads/Site Backup.zip', $relPath);
        $this->assertSame('upload', ContainerFileAuditLog::query()->latest('id')->value('action'));
    }

    public function test_extract_archive_translates_coded_failures_into_sentences(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/^\[ -f .*site\.zip/'), 10)->andReturn('file');
        $ssh->shouldReceive('exec')->with('command -v python3 >/dev/null 2>&1 && echo yes || echo no', 10)->andReturn('yes');
        $ssh->shouldReceive('execWithStatus')
            ->with(Mockery::pattern('/^python3 - \'inspect\'/'), 600)
            ->andReturn(['output' => 'UNSAFE ../../etc/cron.d/x', 'status' => 3]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('would write outside the destination (../../etc/cron.d/x)');

        (new ContainerFileService($ssh))->extractArchive($service, $deployment, '/site.zip', '/', false, $user, '10.0.0.1');
    }

    public function test_extract_archive_runs_validate_extract_chown_and_removes_the_archive(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/^\[ -f .*site\.zip/'), 10)->andReturn('file');
        $ssh->shouldReceive('exec')->with('command -v python3 >/dev/null 2>&1 && echo yes || echo no', 10)->andReturn('yes');
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^python3 - \'inspect\'/'), 600)->andReturn(['output' => 'OK 4096 7', 'status' => 0]);
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^df -B1/'), 20)->andReturn(['output' => '999999999', 'status' => 0]);
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^mkdir -p .*&& python3 - \'extract\'/s'), 1500)->andReturn(['output' => 'OK 4096 7', 'status' => 0]);
        $ssh->shouldReceive('exec')->with("chown -R '33:33' '".self::BASE."/public'", 300)->andReturn('');
        $ssh->shouldReceive('deleteFile')->once()->with(self::BASE.'/site.zip');

        $labels = [];
        $result = (new ContainerFileService($ssh))->extractArchive(
            $service,
            $deployment,
            '/site.zip',
            '/public',
            true,
            $user,
            '10.0.0.1',
            function (int $percent, string $label) use (&$labels) {
                $labels[] = $percent.' '.$label;
            },
        );

        $this->assertSame(['bytes' => 4096, 'count' => 7, 'destination' => '/public'], $result);
        $this->assertStringContainsString('7 file(s)', implode("\n", $labels));
        $this->assertSame('extract', ContainerFileAuditLog::query()->latest('id')->value('action'));
    }

    public function test_listing_hides_the_scratch_directory_and_flags_archives(): void
    {
        [$service, $deployment, $user, $ssh] = $this->fixture();
        $this->expectBaseProbe($ssh);
        $ssh->shouldReceive('listDir')->once()->andReturn([
            ['name' => '.file-manager-tmp', 'type' => 'dir', 'size' => 0, 'modified' => 1],
            ['name' => 'public', 'type' => 'dir', 'size' => 0, 'modified' => 1],
            ['name' => 'site.tar.gz', 'type' => 'file', 'size' => 10, 'modified' => 1],
            ['name' => 'index.php', 'type' => 'file', 'size' => 10, 'modified' => 1],
        ]);

        $result = (new ContainerFileService($ssh))->listDirectory($service, $deployment, '/', $user, '10.0.0.1');

        $names = array_column($result['entries'], 'name');
        $this->assertSame(['public', 'site.tar.gz', 'index.php'], $names);
        $this->assertTrue($result['entries'][1]['archive']);
        $this->assertFalse($result['entries'][2]['archive']);
    }

    private function expectBaseProbe(MockInterface $ssh): void
    {
        $ssh->shouldReceive('exec')
            ->with("[ -d '".self::BASE."' ] && echo yes || echo no")
            ->andReturn('yes');
        // The template fixture carries a disk allowance, so room checks also measure usage.
        $ssh->shouldReceive('execWithStatus')
            ->with(Mockery::pattern('/^du -sb/'), 60)
            ->andReturn(['output' => '0', 'status' => 0])
            ->byDefault();
    }

    /**
     * @return array{0: Service, 1: ContainerDeployment, 2: User, 3: SSHService&MockInterface}
     */
    private function fixture(float $diskGb = 0.0): array
    {
        $user = User::factory()->create();
        $template = ContainerTemplate::query()->firstOrCreate(['slug' => 'laravel'], [
            'name' => 'Laravel',
            'docker_image' => 'php:8.3',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create([
            'container_template_id' => $template->id,
            'resource_limits' => $diskGb > 0 ? ['disk' => $diskGb] : [],
        ]);
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'provisioning_driver_key' => 'container',
        ]);
        $deployment = ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => 'user-1-service-1-laravel',
        ]);
        $deployment->setRelation('service', $service->fresh(['product.containerTemplate']));

        /** @var SSHService&MockInterface $ssh */
        $ssh = Mockery::mock(SSHService::class);

        return [$service, $deployment, $user, $ssh];
    }
}

<?php

namespace Tests\Feature\Customer;

use App\Jobs\BuildContainerArchiveJob;
use App\Jobs\ExtractContainerArchiveJob;
use App\Models\ContainerDeployment;
use App\Models\ContainerTemplate;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerFileOperationProgress;
use App\Services\Provisioning\ContainerFileService;
use App\Services\Provisioning\ContainerFileServiceFactory;
use App\Services\SSH\SSHService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ContainerFileManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_delete_removes_the_selection_in_one_request(): void
    {
        [$customer, $service] = $this->deployedService();
        $ssh = $this->fakeSsh();
        $ssh->shouldReceive('execWithStatus')->once()->with(Mockery::pattern('/^rm -rf -- /'), 300)->andReturn(['output' => '', 'status' => 0]);

        $this->actingAs($customer)
            ->deleteJson(route('customer.services.container.files.batch-delete', $service), ['paths' => ['/old', '/logs/app.log']])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deleted', ['/old', '/logs/app.log']);
    }

    public function test_batch_delete_refuses_the_application_root(): void
    {
        [$customer, $service] = $this->deployedService();
        $this->fakeSsh();

        $this->actingAs($customer)
            ->deleteJson(route('customer.services.container.files.batch-delete', $service), ['paths' => ['/']])
            ->assertStatus(422)
            ->assertJsonPath('deleted', []);
    }

    public function test_move_reports_conflicts_and_moves_the_rest(): void
    {
        [$customer, $service] = $this->deployedService();
        $ssh = $this->fakeSsh();
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/^\[ -d .*archive/'), 10)->andReturn('yes');
        $ssh->shouldReceive('exec')->with(Mockery::pattern('#\[ -e .*archive/a\.txt#'), 10)->andReturn('no');
        $ssh->shouldReceive('exec')->with(Mockery::pattern('#\[ -e .*archive/b\.txt#'), 10)->andReturn('yes');
        $ssh->shouldReceive('execWithStatus')->once()->with(Mockery::pattern('/^cp -a -- /'), 600)->andReturn(['output' => '', 'status' => 0]);

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.files.move', $service), [
                'paths' => ['/a.txt', '/b.txt'],
                'destination' => '/archive',
                'mode' => 'copy',
            ])
            ->assertOk()
            ->assertJsonPath('done', ['/a.txt'])
            ->assertJsonPath('conflicts', ['/b.txt'])
            ->assertJsonPath('success', false);
    }

    public function test_extract_dispatches_a_job_and_returns_an_operation_token(): void
    {
        Bus::fake();
        [$customer, $service] = $this->deployedService();

        $response = $this->actingAs($customer)
            ->postJson(route('customer.services.container.files.extract', $service), ['path' => '/site.zip', 'delete_archive' => true])
            ->assertStatus(202)
            ->assertJsonPath('operation.status', 'queued')
            ->assertJsonPath('operation.type', 'extract');

        $token = $response->json('operation.token');
        Bus::assertDispatched(ExtractContainerArchiveJob::class, fn ($job) => $job->token === $token
            && $job->archivePath === '/site.zip'
            && $job->destination === '/'
            && $job->deleteArchive === true
            && $job->serviceId === $service->id);

        $this->actingAs($customer)
            ->getJson(route('customer.services.container.files.operation', [$service, $token]))
            ->assertOk()
            ->assertJsonPath('token', $token);
    }

    public function test_extract_refuses_non_archives(): void
    {
        Bus::fake();
        [$customer, $service] = $this->deployedService();

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.files.extract', $service), ['path' => '/index.php'])
            ->assertStatus(422);

        Bus::assertNotDispatched(ExtractContainerArchiveJob::class);
    }

    public function test_archive_dispatches_a_build_job(): void
    {
        Bus::fake();
        [$customer, $service] = $this->deployedService();

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.files.archive', $service), ['paths' => ['/public', '/composer.json']])
            ->assertStatus(202)
            ->assertJsonPath('operation.type', 'archive');

        Bus::assertDispatched(BuildContainerArchiveJob::class, fn ($job) => $job->paths === ['/public', '/composer.json']);
    }

    public function test_operations_are_not_visible_across_services_or_customers(): void
    {
        [$customer, $service] = $this->deployedService();
        [$otherCustomer, $otherService] = $this->deployedService();
        $token = app(ContainerFileOperationProgress::class)->start($service, $service->containerDeployment, 'extract')['token'];

        $this->actingAs($customer)
            ->getJson(route('customer.services.container.files.operation', [$otherService, $token]))
            ->assertForbidden();

        $this->actingAs($otherCustomer)
            ->getJson(route('customer.services.container.files.operation', [$otherService, $token]))
            ->assertNotFound();
    }

    public function test_upload_streams_several_files_and_can_queue_extraction(): void
    {
        Bus::fake();
        [$customer, $service] = $this->deployedService();
        $ssh = $this->fakeSsh();
        $ssh->shouldReceive('mkdirp')->once();
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^df -B1/'), 20)->andReturn(['output' => '999999999', 'status' => 0]);
        $ssh->shouldReceive('uploadFromLocal')->twice();
        $ssh->shouldReceive('exec')->with(Mockery::pattern('/^chown -R/'), 300)->andReturn('');

        $response = $this->actingAs($customer)
            ->post(route('customer.services.container.files.upload', $service), [
                'path' => '/uploads',
                'extract' => '1',
                'files' => [
                    UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
                    UploadedFile::fake()->create('site.zip', 40, 'application/zip'),
                ],
            ], ['Accept' => 'application/json']);
        $this->assertSame(200, $response->status(), $response->getContent());
        $response
            ->assertJsonPath('success', true)
            ->assertJsonPath('files.0.status', 'uploaded')
            ->assertJsonPath('files.0.path', '/uploads/notes.txt')
            ->assertJsonPath('files.1.status', 'extracting');

        Bus::assertDispatched(ExtractContainerArchiveJob::class, fn ($job) => $job->archivePath === '/uploads/site.zip' && $job->destination === '/uploads');
        $this->assertSame('queued', $response->json('files.1.operation.status'));
    }

    public function test_upload_rejects_dangerous_names_and_extensions(): void
    {
        [$customer, $service] = $this->deployedService();

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.files.upload', $service), [
                'path' => '/',
                'files' => [UploadedFile::fake()->create('shell.phtml', 1, 'text/plain')],
            ])
            ->assertStatus(422);
    }

    public function test_another_customer_cannot_touch_the_files(): void
    {
        [, $service] = $this->deployedService();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->deleteJson(route('customer.services.container.files.batch-delete', $service), ['paths' => ['/x']])
            ->assertForbidden();
    }

    public function test_partial_renders_the_new_controls(): void
    {
        [, $service] = $this->deployedService();

        $html = view('customer.services.partials.file-manager', ['service' => $service])->render();

        $this->assertStringContainsString('Extract archives after upload', $html);
        $this->assertStringContainsString('Download zip', $html);
        $this->assertStringContainsString('title="Select all"', $html);
        $this->assertStringContainsString('extractEntry(entry.name)', $html);

        // The Alpine component is pushed to the scripts stack, which a bare render() flushes,
        // so the endpoints it calls are checked in the source.
        $source = file_get_contents(resource_path('views/customer/services/partials/file-manager.blade.php'));
        foreach (['files.batch-delete', 'files.move', 'files.extract', 'files.archive', 'files.operation', 'files.archive-download'] as $name) {
            $this->assertStringContainsString("container_route('{$name}'", $source);
        }
    }

    /**
     * @return SSHService&MockInterface
     */
    private function fakeSsh(): MockInterface
    {
        /** @var SSHService&MockInterface $ssh */
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->with(Mockery::pattern("#^\\[ -d '/opt/talksasa/containers/[^']+/app' \\] && echo yes#"))->andReturn('yes');
        $ssh->shouldReceive('execWithStatus')->with(Mockery::pattern('/^du -sb/'), 60)->andReturn(['output' => '0', 'status' => 0])->byDefault();
        $this->mock(ContainerFileServiceFactory::class, function (MockInterface $factory) use ($ssh) {
            $factory->shouldReceive('make')->andReturn(new ContainerFileService($ssh));
        });

        return $ssh;
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function deployedService(): array
    {
        $customer = User::factory()->create();
        $template = ContainerTemplate::query()->firstOrCreate(['slug' => 'laravel'], [
            'name' => 'Laravel',
            'docker_image' => 'php:8.3',
            'is_active' => true,
        ]);
        $product = Product::factory()->containerHosting()->create(['container_template_id' => $template->id]);
        $node = Node::factory()->containerHost()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'node_id' => $node->id,
            'status' => 'active',
            'provisioning_driver_key' => 'container',
        ]);
        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'container_name' => 'user-'.$customer->id.'-service-'.$service->id.'-laravel',
        ]);

        return [$customer, $service->fresh(['product.containerTemplate', 'containerDeployment.node'])];
    }
}

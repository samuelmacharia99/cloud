<?php

namespace Tests\Feature\Console;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDoctorService;
use App\Services\Provisioning\WordPressDatabaseConfigAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A bulk repair that recreates containers across a live fleet has to be harder
 * to misfire than it is to run. These tests pin the three things that make it
 * safe: it only touches sites it read as broken, it believes the file rather
 * than the repair's own verdict, and one failure stops the sweep.
 */
class RepairWordPressDatabaseHostCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_dry_run_names_the_change_and_repairs_nothing(): void
    {
        $this->wordPressDeployment('user-5-service-97-wordpress');
        $this->analyzerReturning(['DB_HOST' => 'mysql']);
        $this->doctorExpectingNoTreatment();

        $this->artisan('containers:repair-wordpress-db-host', ['--dry-run' => true])
            ->expectsOutputToContain('user-5-service-97-wordpress-mysql')
            ->expectsOutputToContain('Nothing was modified.')
            ->assertExitCode(0);
    }

    #[Test]
    public function declining_the_confirmation_repairs_nothing(): void
    {
        $this->wordPressDeployment('user-5-service-98-wordpress');
        $this->analyzerReturning(['DB_HOST' => 'mysql']);
        $this->doctorExpectingNoTreatment();

        $this->artisan('containers:repair-wordpress-db-host')
            ->expectsConfirmation('Repair 1 site(s) now?', 'no')
            ->expectsOutputToContain('Nothing was modified.')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_site_already_pinned_is_never_touched(): void
    {
        $this->wordPressDeployment('user-5-service-99-wordpress');
        $this->analyzerReturning(['DB_HOST' => 'user-5-service-99-wordpress-mysql']);
        $this->doctorExpectingNoTreatment();

        $this->artisan('containers:repair-wordpress-db-host', ['--force' => true])
            ->expectsOutputToContain('No WordPress site is pointing at a shared database hostname.')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_repaired_site_is_confirmed_by_re_reading_the_file(): void
    {
        $this->wordPressDeployment('user-5-service-100-wordpress');
        $this->analyzerReadingInTurn([
            ['DB_HOST' => 'mysql'],
            ['DB_HOST' => 'user-5-service-100-wordpress-mysql'],
        ]);
        $this->doctorReturning(['success' => true, 'message' => 'Database credentials synced.']);

        $this->artisan('containers:repair-wordpress-db-host', ['--force' => true])
            ->expectsOutputToContain('pinned to user-5-service-100-wordpress-mysql')
            ->expectsOutputToContain('Repaired: 1')
            ->expectsOutputToContain('Failed: 0')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_repair_that_claims_success_but_left_the_file_alone_is_a_failure(): void
    {
        // Doctor logs a failed wp-config rewrite as a warning and still returns
        // success. Taking it at its word is how a fleet gets reported fixed
        // while every site still dials the shared name.
        $this->wordPressDeployment('user-5-service-101-wordpress');
        $this->analyzerReturning(['DB_HOST' => 'mysql']);
        $this->doctorReturning(['success' => true, 'message' => 'Database credentials synced.']);

        $this->artisan('containers:repair-wordpress-db-host', ['--force' => true])
            ->expectsOutputToContain('still points at a shared hostname')
            ->expectsOutputToContain('Repaired: 0')
            ->expectsOutputToContain('Failed: 1')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_first_failure_stops_the_sweep(): void
    {
        $node = Node::factory()->containerHost()->create();
        $this->wordPressDeployment('user-5-service-110-wordpress', $node);
        $this->wordPressDeployment('user-5-service-111-wordpress', $node);
        $this->analyzerReturning(['DB_HOST' => 'mysql']);

        $doctor = Mockery::mock(ContainerDoctorService::class)->makePartial();
        $doctor->shouldReceive('treat')->once()->andReturn(['success' => false, 'message' => 'Application is not deployed.']);
        $this->app->instance(ContainerDoctorService::class, $doctor);

        $this->artisan('containers:repair-wordpress-db-host', ['--force' => true])
            ->expectsOutputToContain('Application is not deployed.')
            ->expectsOutputToContain('Stopping here rather than marching through the rest of the fleet.')
            ->expectsOutputToContain('Failed: 1')
            ->assertExitCode(1);
    }

    #[Test]
    public function continue_on_failure_works_through_the_rest(): void
    {
        $node = Node::factory()->containerHost()->create();
        $this->wordPressDeployment('user-5-service-120-wordpress', $node);
        $this->wordPressDeployment('user-5-service-121-wordpress', $node);
        $this->analyzerReturning(['DB_HOST' => 'mysql']);

        $doctor = Mockery::mock(ContainerDoctorService::class)->makePartial();
        $doctor->shouldReceive('treat')->twice()->andReturn(['success' => false, 'message' => 'Application is not deployed.']);
        $this->app->instance(ContainerDoctorService::class, $doctor);

        $this->artisan('containers:repair-wordpress-db-host', ['--force' => true, '--continue-on-failure' => true])
            ->expectsOutputToContain('Failed: 2')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_service_filter_narrows_the_repair_to_one_site(): void
    {
        $node = Node::factory()->containerHost()->create();
        $wanted = $this->wordPressDeployment('user-5-service-130-wordpress', $node);
        $this->wordPressDeployment('user-5-service-131-wordpress', $node);
        $this->analyzerReturning(['DB_HOST' => 'mysql']);
        $this->doctorExpectingNoTreatment();

        $this->artisan('containers:repair-wordpress-db-host', [
            '--service' => [(string) $wanted->service_id],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('1 site(s) would be repaired')
            ->doesntExpectOutputToContain('user-5-service-131-wordpress')
            ->assertExitCode(0);
    }

    #[Test]
    public function the_limit_caps_how_much_of_the_fleet_moves_at_once(): void
    {
        $node = Node::factory()->containerHost()->create();
        $this->wordPressDeployment('user-5-service-140-wordpress', $node);
        $this->wordPressDeployment('user-5-service-141-wordpress', $node);
        $this->wordPressDeployment('user-5-service-142-wordpress', $node);
        $this->analyzerReturning(['DB_HOST' => 'mysql']);
        $this->doctorExpectingNoTreatment();

        $this->artisan('containers:repair-wordpress-db-host', ['--limit' => 2, '--dry-run' => true])
            ->expectsOutputToContain('2 site(s) would be repaired')
            ->assertExitCode(0);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function analyzerReturning(array $credentials): void
    {
        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldReceive('effectiveCredentials')->andReturn($credentials);
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);
    }

    /**
     * @param  list<array<string, string>>  $reads
     */
    private function analyzerReadingInTurn(array $reads): void
    {
        $analyzer = Mockery::mock(WordPressDatabaseConfigAnalyzer::class)->makePartial();
        $analyzer->shouldReceive('effectiveCredentials')
            ->andReturnUsing(function () use (&$reads): array {
                return array_shift($reads) ?? [];
            });
        $this->app->instance(WordPressDatabaseConfigAnalyzer::class, $analyzer);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function doctorReturning(array $result): void
    {
        $doctor = Mockery::mock(ContainerDoctorService::class)->makePartial();
        $doctor->shouldReceive('treat')->andReturn($result);
        $this->app->instance(ContainerDoctorService::class, $doctor);
    }

    private function doctorExpectingNoTreatment(): void
    {
        $doctor = Mockery::mock(ContainerDoctorService::class)->makePartial();
        $doctor->shouldNotReceive('treat');
        $this->app->instance(ContainerDoctorService::class, $doctor);
    }

    private function wordPressDeployment(string $containerName, ?Node $node = null): ContainerDeployment
    {
        $node ??= Node::factory()->containerHost()->create();

        $service = Service::factory()->create([
            'user_id' => User::factory()->customer()->create()->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
            'node_id' => $node->id,
            'service_meta' => ['provision_template_slug' => 'wordpress'],
        ]);

        return ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'container_name' => $containerName,
            'status' => 'running',
        ]);
    }
}

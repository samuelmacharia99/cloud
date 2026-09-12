<?php

namespace Tests\Feature\Database;

use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerEnvironmentSeed;
use App\Services\Provisioning\ContainerSecretsAtRestBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rows are written raw, the way they sat in production before the encrypted
 * casts existed, then the backfill is run twice: once to prove every copy
 * of a secret ends up as ciphertext the models still read correctly, and
 * again to prove the pass is a no-op on rows it has already handled.
 */
class EncryptContainerSecretsAtRestBackfillTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function plaintext_columns_become_ciphertext_the_models_still_read(): void
    {
        $service = Service::factory()->create();
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id]);

        DB::table('container_deployments')->where('id', $deployment->id)->update([
            'env_values' => json_encode(['DB_PASSWORD' => 'plain-pw']),
            'docker_compose_content' => "services:\n  db:\n    environment:\n      MYSQL_ROOT_PASSWORD: root-pw\n",
        ]);
        DB::table('services')->where('id', $service->id)->update([
            'credentials' => json_encode(['username' => 'u1', 'password' => 'cred-pw']),
        ]);

        $report = (new ContainerSecretsAtRestBackfill)->run();

        $this->assertSame(1, $report['encrypted']['container_deployments.env_values']);
        $this->assertSame(1, $report['encrypted']['container_deployments.docker_compose_content']);
        $this->assertSame(1, $report['encrypted']['services.credentials']);

        $rawDeployment = DB::table('container_deployments')->where('id', $deployment->id)->first();
        $this->assertStringNotContainsString('plain-pw', (string) $rawDeployment->env_values);
        $this->assertStringNotContainsString('root-pw', (string) $rawDeployment->docker_compose_content);
        $this->assertSame('{"DB_PASSWORD":"plain-pw"}', Crypt::decryptString($rawDeployment->env_values));

        $rawService = DB::table('services')->where('id', $service->id)->first();
        $this->assertStringNotContainsString('cred-pw', (string) $rawService->credentials);

        $this->assertSame(['DB_PASSWORD' => 'plain-pw'], $deployment->fresh()->env_values);
        $this->assertStringContainsString('MYSQL_ROOT_PASSWORD: root-pw', $deployment->fresh()->docker_compose_content);
        $this->assertSame('cred-pw', $service->fresh()->getHostingCredentials()['password']);
    }

    #[Test]
    public function the_deployment_row_wins_over_the_mirror_and_the_mirror_is_removed(): void
    {
        $service = Service::factory()->create();
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id]);

        DB::table('container_deployments')->where('id', $deployment->id)->update([
            'env_values' => json_encode(['SHARED' => 'from-row']),
        ]);
        DB::table('services')->where('id', $service->id)->update([
            'service_meta' => json_encode([
                'env_values' => ['SHARED' => 'from-mirror', 'MIRROR_ONLY' => 'kept'],
                'domain' => 'example.com',
            ]),
        ]);

        $report = (new ContainerSecretsAtRestBackfill)->run();

        $this->assertSame(1, $report['mirrors_folded']);
        $this->assertSame(0, $report['seeds_created']);
        $this->assertEquals(
            ['SHARED' => 'from-row', 'MIRROR_ONLY' => 'kept'],
            $deployment->fresh()->env_values
        );

        $meta = $service->fresh()->service_meta;
        $this->assertSame('example.com', $meta['domain']);
        $this->assertArrayNotHasKey('env_values', $meta);
        $this->assertArrayNotHasKey(ContainerEnvironmentSeed::META_KEY, $meta);
    }

    #[Test]
    public function an_undeployed_service_keeps_its_checkout_values_in_the_encrypted_seed(): void
    {
        $pending = Service::factory()->create();
        $terminatedOnly = Service::factory()->create();
        ContainerDeployment::factory()->create(['service_id' => $terminatedOnly->id, 'status' => 'terminated']);

        foreach ([$pending, $terminatedOnly] as $service) {
            DB::table('services')->where('id', $service->id)->update([
                'service_meta' => json_encode(['env_values' => ['API_KEY' => 'k-'.$service->id]]),
            ]);
        }

        $report = (new ContainerSecretsAtRestBackfill)->run();

        $this->assertSame(2, $report['mirrors_folded']);
        $this->assertSame(2, $report['seeds_created']);

        $seed = new ContainerEnvironmentSeed;
        foreach ([$pending, $terminatedOnly] as $service) {
            $meta = $service->fresh()->service_meta;
            $this->assertArrayNotHasKey('env_values', $meta);
            $this->assertStringNotContainsString('k-'.$service->id, json_encode($meta));
            $this->assertSame(['API_KEY' => 'k-'.$service->id], $seed->read($service->fresh()));
        }
    }

    #[Test]
    public function a_second_run_changes_nothing(): void
    {
        $service = Service::factory()->create();
        $deployment = ContainerDeployment::factory()->create(['service_id' => $service->id]);
        DB::table('container_deployments')->where('id', $deployment->id)->update([
            'env_values' => json_encode(['A' => '1']),
            'docker_compose_content' => 'services: {}',
        ]);
        DB::table('services')->where('id', $service->id)->update([
            'credentials' => json_encode(['username' => 'u', 'password' => 'p']),
            'service_meta' => json_encode(['env_values' => ['B' => '2']]),
        ]);

        // A row already written through the cast must be left exactly as it is.
        $alreadyEncrypted = ContainerDeployment::factory()->create([
            'service_id' => Service::factory()->create()->id,
            'env_values' => ['C' => '3'],
        ]);
        $rawBefore = DB::table('container_deployments')->where('id', $alreadyEncrypted->id)->value('env_values');

        (new ContainerSecretsAtRestBackfill)->run();
        $snapshot = [
            DB::table('container_deployments')->orderBy('id')->get(['env_values', 'docker_compose_content'])->toArray(),
            DB::table('services')->orderBy('id')->get(['credentials', 'service_meta'])->toArray(),
        ];

        $second = (new ContainerSecretsAtRestBackfill)->run();

        $this->assertSame(['encrypted' => [
            'container_deployments.env_values' => 0,
            'container_deployments.docker_compose_content' => 0,
            'services.credentials' => 0,
        ], 'mirrors_folded' => 0, 'seeds_created' => 0], $second);
        $this->assertEquals($snapshot, [
            DB::table('container_deployments')->orderBy('id')->get(['env_values', 'docker_compose_content'])->toArray(),
            DB::table('services')->orderBy('id')->get(['credentials', 'service_meta'])->toArray(),
        ]);
        $this->assertSame($rawBefore, DB::table('container_deployments')->where('id', $alreadyEncrypted->id)->value('env_values'));
        $this->assertEquals(['A' => '1', 'B' => '2'], $deployment->fresh()->env_values);
    }
}

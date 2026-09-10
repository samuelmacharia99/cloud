<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerDatabaseMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Running migrations is one click, so the typed database username is what
 * stands between a stray click and someone else's schema. It is checked on the
 * server: a browser that skips the field must not get a run.
 */
class ContainerDatabaseMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wrong_username_never_reaches_the_database(): void
    {
        $customer = User::factory()->create();
        $service = $this->containerServiceFor($customer);

        $this->mock(ContainerDatabaseMigrationService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
            $mock->shouldNotReceive('plan');
        });

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.database.migrate', $service), [
                'confirm_username' => 'u000_s000',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_username');
    }

    public function test_the_username_field_cannot_simply_be_omitted(): void
    {
        $customer = User::factory()->create();
        $service = $this->containerServiceFor($customer);

        $this->mock(ContainerDatabaseMigrationService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.database.migrate', $service), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm_username');
    }

    public function test_another_customer_cannot_migrate_this_database(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $service = $this->containerServiceFor($owner);

        $this->mock(ContainerDatabaseMigrationService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('run');
        });

        $this->actingAs($stranger)
            ->postJson(route('customer.services.container.database.migrate', $service), [
                'confirm_username' => 'u1_s1',
            ])
            ->assertForbidden();
    }

    private function containerServiceFor(User $customer): Service
    {
        $node = Node::factory()->containerHost()->create();
        $product = Product::factory()->containerHosting()->create();
        $service = Service::factory()->create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'provisioning_driver_key' => 'container',
            'status' => 'active',
        ]);

        ContainerDeployment::factory()->create([
            'service_id' => $service->id,
            'node_id' => $node->id,
            'status' => 'running',
            'env_values' => [
                'DB_CONNECTION' => 'pgsql',
                'DB_HOST' => 'user-1-service-1-nodejs-db',
                'DB_PORT' => '5432',
                'DB_DATABASE' => 's1_db',
                'DB_USERNAME' => 'u1_s1',
                'DB_PASSWORD' => 'secret-value',
                'POSTGRES_DB' => 's1_db',
            ],
        ]);

        return $service->fresh(['containerDeployment', 'product']);
    }
}

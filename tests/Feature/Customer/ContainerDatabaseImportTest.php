<?php

namespace Tests\Feature\Customer;

use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ContainerDatabaseImportTest extends TestCase
{
    use RefreshDatabase;

    private function runningServiceWithMysql(User $customer): Service
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
                'DB_DATABASE' => 's426_db',
                'DB_USERNAME' => 'u483_s426',
                'MYSQL_PASSWORD' => 'secret',
                'MYSQL_DATABASE' => 's426_db',
            ],
        ]);

        return $service->fresh(['containerDeployment.node', 'product']);
    }

    public function test_import_without_file_returns_json_error(): void
    {
        $customer = User::factory()->customer()->create();
        $service = $this->runningServiceWithMysql($customer);

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.database.import', $service), [])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Choose a .sql file to import.');
    }

    public function test_import_rejects_non_sql_extension(): void
    {
        $customer = User::factory()->customer()->create();
        $service = $this->runningServiceWithMysql($customer);

        $this->actingAs($customer)
            ->postJson(route('customer.services.container.database.import', $service), [
                'file' => UploadedFile::fake()->create('dump.zip', 20),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only .sql files are supported for database import.');
    }
}

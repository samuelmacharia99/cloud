<?php

namespace Database\Factories;

use App\Models\ContainerDeployment;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContainerDeploymentFactory extends Factory
{
    protected $model = ContainerDeployment::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'node_id' => null,
            'container_name' => 'talksasa-test-'.fake()->unique()->regexify('[a-z0-9]{10}'),
            'status' => 'running',
            'assigned_port' => fake()->unique()->numberBetween(30000, 39999),
            'auto_restart' => true,
            'restart_policy' => 'unless-stopped',
            'restart_attempts' => 0,
            'cpu_limit' => 1.0,
            'memory_limit_mb' => 512,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ContainerDeployment $deployment): void {
            if ($deployment->node_id === null && $deployment->service?->node_id) {
                $deployment->update(['node_id' => $deployment->service->node_id]);
            }
        });
    }
}

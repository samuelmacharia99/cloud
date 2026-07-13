<?php

namespace Database\Factories;

use App\Models\ContainerBackup;
use App\Models\ContainerDeployment;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContainerBackupFactory extends Factory
{
    protected $model = ContainerBackup::class;

    public function definition(): array
    {
        return [
            'container_deployment_id' => ContainerDeployment::factory(),
            'service_id' => null,
            'node_id' => null,
            'backup_name' => 'backup-'.fake()->unique()->regexify('[a-z0-9]{8}'),
            'backup_path' => '/var/backups/'.fake()->uuid(),
            'storage_driver' => 'node',
            'size_bytes' => fake()->numberBetween(1024, 10485760),
            'status' => 'completed',
            'type' => 'manual',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ContainerBackup $backup): void {
            if ($backup->service_id !== null && $backup->node_id !== null) {
                return;
            }

            $deployment = null;
            if ($backup->container_deployment_id) {
                $deployment = ContainerDeployment::query()->find($backup->container_deployment_id);
            }

            if ($deployment) {
                $backup->service_id ??= $deployment->service_id;
                $backup->node_id ??= $deployment->node_id ?? $deployment->service?->node_id;
            }

            if ($backup->node_id === null && $backup->service_id) {
                $backup->node_id = Service::query()->find($backup->service_id)?->node_id;
            }
        });
    }
}

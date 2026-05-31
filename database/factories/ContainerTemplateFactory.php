<?php

namespace Database\Factories;

use App\Models\ContainerTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContainerTemplateFactory extends Factory
{
    protected $model = ContainerTemplate::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => ucfirst($name),
            'description' => fake()->sentence(),
            'category' => 'web',
            'docker_image' => 'nginx:latest',
            'default_port' => 80,
            'required_ram_mb' => 512,
            'required_cpu_cores' => 1.0,
            'required_storage_gb' => 2,
            'environment_variables' => [],
            'volume_paths' => [],
            'is_active' => true,
            'order' => 0,
        ];
    }
}

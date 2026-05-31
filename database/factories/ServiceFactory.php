<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'name' => fake()->words(3, true),
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'next_due_date' => now()->addMonth(),
            'suspend_date' => null,
            'terminate_date' => null,
            'provisioning_driver_key' => 'cpanel',
            'service_meta' => ['custom_field' => fake()->word()],
            'external_reference' => fake()->unique()->regexify('[A-Za-z0-9]{12}'),
            'credentials' => null,
        ];
    }
}

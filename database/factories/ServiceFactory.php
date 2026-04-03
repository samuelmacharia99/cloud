<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'status' => fake()->randomElement(['pending', 'provisioning', 'active', 'suspended', 'terminated', 'failed']),
            'billing_cycle' => fake()->randomElement(['monthly', 'yearly', 'one-time']),
            'next_due_date' => fake()->dateTimeBetween('now', '+1 year'),
            'suspend_date' => fake()->randomElement([null, fake()->dateTimeBetween('now', '+3 months')]),
            'terminate_date' => null,
            'provisioning_driver_key' => fake()->randomElement(['cpanel', 'directadmin', 'plesk', null]),
            'service_meta' => json_encode(['custom_field' => fake()->word()]),
            'external_reference' => fake()->unique()->alphaNumeric(12),
            'credentials' => null,
        ];
    }
}

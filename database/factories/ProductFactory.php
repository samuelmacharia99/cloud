<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 99999),
            'description' => fake()->sentences(2, true),
            'category' => 'Hosting',
            'type' => 'shared_hosting',
            'price' => fake()->randomFloat(2, 5, 100),
            'monthly_price' => 9.99,
            'yearly_price' => 99.99,
            'billing_cycle' => 'monthly',
            'setup_fee' => 0,
            'provisioning_driver_key' => 'cpanel',
            'resource_limits' => [
                'disk_space' => '10GB',
                'bandwidth' => '100GB',
                'databases' => 5,
                'email_accounts' => 10,
            ],
            'is_active' => true,
            'visible_to_resellers' => false,
            'featured' => false,
            'order' => 0,
        ];
    }

    public function containerHosting(): static
    {
        return $this->state(fn () => [
            'type' => 'container_hosting',
            'provisioning_driver_key' => 'container',
        ]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(['shared_hosting', 'container_hosting', 'domain', 'ssl', 'email_hosting', 'sms_bundle', 'hotspot_plan']);
        $name = fake()->words(3, true);

        $pricing = match ($type) {
            'shared_hosting' => ['monthly' => 5.99, 'yearly' => 59.99],
            'container_hosting' => ['monthly' => 19.99, 'yearly' => 199.99],
            'domain' => ['monthly' => null, 'yearly' => 12.99],
            'ssl' => ['monthly' => null, 'yearly' => 9.99],
            'email_hosting' => ['monthly' => 4.99, 'yearly' => 49.99],
            'sms_bundle' => ['monthly' => null, 'yearly' => null],
            'hotspot_plan' => ['monthly' => null, 'yearly' => null],
            default => ['monthly' => 10.00, 'yearly' => 100.00],
        };

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentences(3, true),
            'type' => $type,
            'price' => fake()->randomFloat(2, 5, 100),
            'monthly_price' => $pricing['monthly'],
            'yearly_price' => $pricing['yearly'],
            'billing_cycle' => fake()->randomElement(['monthly', 'yearly', 'one-time']),
            'features' => json_encode(fake()->words(5)),
            'setup_fee' => fake()->randomElement([null, 0, 9.99]),
            'provisioning_driver_key' => fake()->randomElement(['cpanel', 'directadmin', 'plesk', 'custom', null]),
            'resource_limits' => json_encode([
                'disk_space' => fake()->numberBetween(1, 500) . 'GB',
                'bandwidth' => fake()->numberBetween(1, 1000) . 'GB',
                'databases' => fake()->numberBetween(1, 50),
                'email_accounts' => fake()->numberBetween(1, 100),
            ]),
            'is_active' => true,
            'visible_to_resellers' => fake()->boolean(),
            'featured' => fake()->boolean(10),
        ];
    }
}

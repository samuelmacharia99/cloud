<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'remember_token' => Str::random(10),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'country' => fake()->country(),
            'address' => fake()->address(),
            'city' => fake()->city(),
            'postal_code' => fake()->postcode(),
            'vat_number' => fake()->numerify('###########'),
            'notes' => fake()->sentence(),
            'is_admin' => false,
            'is_reseller' => false,
            'status' => 'active',
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'is_reseller' => false,
        ]);
    }

    public function reseller(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => false,
            'is_reseller' => true,
            'company' => fake()->companySuffix(),
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => false,
            'is_reseller' => false,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 500);
        $tax = $subtotal * 0.16;
        $total = $subtotal + $tax;

        $count = Order::count() + 1;
        $orderNumber = 'ORD-' . now()->format('Ymd') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

        return [
            'user_id' => User::factory(),
            'order_number' => $orderNumber,
            'status' => fake()->randomElement(['pending', 'paid', 'cancelled', 'failed']),
            'payment_status' => fake()->randomElement(['unpaid', 'paid', 'refunded']),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'notes' => fake()->sentence(),
        ];
    }
}

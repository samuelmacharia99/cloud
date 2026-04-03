<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        $status = fake()->randomElement(['pending', 'completed', 'failed', 'reversed']);
        $paidAt = $status === 'completed' ? fake()->dateTimeBetween('-30 days', 'now') : null;

        // 60% M-PESA, 20% card, 20% bank
        $paymentMethod = fake()->randomElement(
            array_merge(
                array_fill(0, 6, 'mpesa'),
                array_fill(0, 2, 'card'),
                array_fill(0, 2, 'bank_transfer')
            )
        );

        return [
            'user_id' => User::factory(),
            'invoice_id' => fake()->randomElement([null, Invoice::factory()]),
            'amount' => fake()->randomFloat(2, 10, 500),
            'currency' => 'KES',
            'payment_method' => $paymentMethod,
            'transaction_reference' => fake()->unique()->bothify('??###-##??-####'),
            'status' => $status,
            'paid_at' => $paidAt,
            'notes' => fake()->sentence(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 500);
        $tax = $subtotal * 0.16;
        $total = $subtotal + $tax;

        $count = Invoice::count() + 1;
        $invoiceNumber = 'INV-' . now()->format('Ymd') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

        $status = fake()->randomElement(['draft', 'unpaid', 'paid', 'overdue', 'cancelled']);
        $paidDate = $status === 'paid' ? fake()->dateTimeBetween('-30 days', 'now') : null;

        return [
            'user_id' => User::factory(),
            'invoice_number' => $invoiceNumber,
            'status' => $status,
            'due_date' => fake()->dateTimeBetween('now', '+60 days'),
            'paid_date' => $paidDate,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'notes' => fake()->sentence(),
        ];
    }
}

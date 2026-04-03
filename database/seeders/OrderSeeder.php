<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Product;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $customers = User::where('is_admin', false)
            ->where('is_reseller', false)
            ->get();

        $products = Product::all();
        $statuses = ['pending', 'paid', 'cancelled', 'failed'];

        foreach ($customers as $customerId => $customer) {
            // Create 1-2 orders per customer
            $orderCount = ($customerId % 2) + 1;

            for ($i = 0; $i < $orderCount; $i++) {
                $count = Order::count() + 1;
                $orderNumber = 'ORD-' . now()->format('Ymd') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

                // 70% chance of paid status
                $status = ($customerId + $i) % 10 <= 6 ? 'paid' : $statuses[($customerId + $i) % count($statuses)];
                $paymentStatus = $status === 'paid' ? 'paid' : ($status === 'pending' ? 'unpaid' : 'failed');

                // Create order
                $order = Order::create([
                    'user_id' => $customer->id,
                    'order_number' => $orderNumber,
                    'status' => $status,
                    'payment_status' => $paymentStatus,
                    'subtotal' => 0,
                    'tax' => 0,
                    'total' => 0,
                    'notes' => 'Order created via seeder',
                ]);

                // Create 1-3 order items
                $itemCount = (($customerId + $i) % 3) + 1;
                $subtotal = 0;

                for ($j = 0; $j < $itemCount; $j++) {
                    $product = $products->get(($customerId + $i + $j) % $products->count());
                    $quantity = (($j % 2) + 1);
                    $unitPrice = $product->monthly_price ?? $product->yearly_price ?? $product->price ?? 10.00;
                    $amount = $unitPrice * $quantity;

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'description' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'amount' => $amount,
                        'billing_cycle' => $product->billing_cycle,
                    ]);

                    $subtotal += $amount;
                }

                // Calculate tax (16%)
                $tax = $subtotal * 0.16;
                $total = $subtotal + $tax;

                // Update order totals
                $order->update([
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);
            }
        }
    }
}

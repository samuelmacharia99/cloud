<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $customers = User::where('is_admin', false)
            ->where('is_reseller', false)
            ->get();

        $products = Product::all();
        $statuses = ['active', 'pending', 'provisioning', 'suspended', 'terminated', 'failed'];
        $externalRefIndex = 100;

        foreach ($customers as $customerId => $customer) {
            // Create 1-2 services per customer
            $serviceCount = ($customerId % 2) + 1;

            for ($i = 0; $i < $serviceCount; $i++) {
                $product = $products->get($i % $products->count());

                // Bias toward active status (70% chance based on modulo)
                if ($customerId % 10 <= 6) {
                    $status = 'active';
                } else {
                    $status = $statuses[($customerId + $i) % count($statuses)];
                }

                $billingCycle = $product->billing_cycle;
                $nextDueDate = match ($billingCycle) {
                    'monthly' => now()->addMonth(),
                    'yearly' => now()->addYear(),
                    'one-time' => null,
                    default => now()->addMonth(),
                };

                Service::create([
                    'user_id' => $customer->id,
                    'product_id' => $product->id,
                    'name' => $product->name . ' #' . ($i + 1),
                    'provisioning_driver_key' => $product->provisioning_driver_key,
                    'status' => $status,
                    'billing_cycle' => $billingCycle,
                    'next_due_date' => $nextDueDate,
                    'termination_date' => $status === 'terminated' ? now()->subDays(($customerId % 10) + 1) : null,
                    'custom_fields' => json_encode([
                        'created_via' => 'seeder',
                        'provisioning_status' => $status === 'provisioning' ? 'in_progress' : 'completed',
                    ]),
                    'notes' => 'Service created via seeder',
                ]);
            }
        }
    }
}

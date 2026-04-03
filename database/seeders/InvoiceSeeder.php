<?php

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Models\Service;
use App\Models\Product;
use Illuminate\Database\Seeder;

class InvoiceSeeder extends Seeder
{
    public function run(): void
    {
        $customers = User::where('is_admin', false)
            ->where('is_reseller', false)
            ->get();

        $statuses = ['draft', 'unpaid', 'paid', 'overdue', 'cancelled'];

        foreach ($customers as $customerId => $customer) {
            // Create 1-2 invoices per customer
            $invoiceCount = ($customerId % 2) + 1;

            for ($i = 0; $i < $invoiceCount; $i++) {
                $count = Invoice::count() + 1;
                $invoiceNumber = 'INV-' . now()->format('Ymd') . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

                // 60% chance of paid status
                $status = ($customerId + $i) % 10 <= 5 ? 'paid' : $statuses[($customerId + $i) % count($statuses)];
                $paidDate = $status === 'paid' ? now()->subDays(($customerId + $i) % 30) : null;
                $dueDate = now()->addDays(30 + (($customerId + $i) % 30));

                // If unpaid and overdue, set older due date
                if ($status === 'overdue') {
                    $dueDate = now()->subDays(($customerId + $i) % 30);
                }

                // Create invoice
                $invoice = Invoice::create([
                    'user_id' => $customer->id,
                    'invoice_number' => $invoiceNumber,
                    'status' => $status,
                    'due_date' => $dueDate,
                    'paid_date' => $paidDate,
                    'subtotal' => 0,
                    'tax' => 0,
                    'total' => 0,
                    'notes' => 'Invoice created via seeder',
                ]);

                // Create 1-3 invoice items from customer's services
                $services = Service::where('user_id', $customer->id)->get();
                $itemCount = min(3, max(1, $services->count()));
                $subtotal = 0;

                for ($j = 0; $j < $itemCount; $j++) {
                    $service = $services->isNotEmpty() ? $services->get($j % $services->count()) : null;
                    $product = $service ? $service->product : Product::first();
                    $quantity = 1;
                    $unitPrice = $product->monthly_price ?? $product->yearly_price ?? $product->price ?? 10.00;
                    $amount = $unitPrice * $quantity;

                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'service_id' => $service?->id,
                        'product_id' => $product->id,
                        'description' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'amount' => $amount,
                    ]);

                    $subtotal += $amount;
                }

                // Calculate tax (16%)
                $tax = $subtotal * 0.16;
                $total = $subtotal + $tax;

                // Update invoice totals
                $invoice->update([
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);
            }
        }
    }
}

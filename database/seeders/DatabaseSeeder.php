<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            SettingSeeder::class,
            ProductSeeder::class,
            DomainExtensionSeeder::class,
            NodeSeeder::class,
            NodeMonitoringSeeder::class,
            ServiceSeeder::class,
            OrderSeeder::class,
            InvoiceSeeder::class,
            PaymentSeeder::class,
        ]);
    }
}

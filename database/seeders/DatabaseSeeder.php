<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'DatabaseSeeder cannot run in production. Use db:seed --class=CronJobSeeder or another allowlisted seeder.'
            );
        }

        $this->call([
            UserSeeder::class,
            SettingSeeder::class,
            EmailTemplateSeeder::class,
            CronJobSeeder::class,
            ContainerTemplateSeeder::class,
            DatabaseTemplateSeeder::class,
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

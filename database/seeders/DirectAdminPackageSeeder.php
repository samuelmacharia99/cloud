<?php

namespace Database\Seeders;

use App\Models\DirectAdminPackage;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DirectAdminPackageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Starter Package',
                'description' => 'Perfect for small websites and blogs',
                'package_key' => 'starter',
                'disk_quota' => 25.00,
                'bandwidth_quota' => 250.00,
                'num_domains' => 5,
                'num_ftp' => 5,
                'num_email_accounts' => 10,
                'num_databases' => 2,
                'num_subdomains' => 25,
                'features' => json_encode(['php' => true, 'cron_jobs' => true, 'ssl' => true]),
                'is_active' => true,
            ],
            [
                'name' => 'Professional Package',
                'description' => 'Ideal for growing businesses',
                'package_key' => 'professional',
                'disk_quota' => 100.00,
                'bandwidth_quota' => 1000.00,
                'num_domains' => 25,
                'num_ftp' => 25,
                'num_email_accounts' => 50,
                'num_databases' => 10,
                'num_subdomains' => 100,
                'features' => json_encode(['php' => true, 'node' => true, 'cron_jobs' => true, 'ssl' => true, 'git' => true]),
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise Package',
                'description' => 'For high-traffic sites and applications',
                'package_key' => 'enterprise',
                'disk_quota' => 500.00,
                'bandwidth_quota' => 5000.00,
                'num_domains' => 100,
                'num_ftp' => 100,
                'num_email_accounts' => 200,
                'num_databases' => 50,
                'num_subdomains' => -1,
                'features' => json_encode(['php' => true, 'python' => true, 'node' => true, 'ruby' => true, 'cron_jobs' => true, 'ssl' => true, 'git' => true, 'api_access' => true]),
                'is_active' => true,
            ],
            [
                'name' => 'Development Package',
                'description' => 'For development and testing purposes',
                'package_key' => 'development',
                'disk_quota' => 50.00,
                'bandwidth_quota' => 500.00,
                'num_domains' => 10,
                'num_ftp' => 10,
                'num_email_accounts' => 25,
                'num_databases' => 5,
                'num_subdomains' => 50,
                'features' => json_encode(['php' => true, 'node' => true, 'cron_jobs' => true, 'ssl' => true]),
                'is_active' => true,
            ],
        ];

        foreach ($packages as $package) {
            DirectAdminPackage::create($package);
        }
    }
}

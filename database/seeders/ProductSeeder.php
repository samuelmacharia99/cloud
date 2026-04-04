<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'name' => 'Shared Hosting Starter',
                'slug' => 'shared-hosting-starter',
                'description' => 'Perfect for beginners and small websites. Includes 10GB storage, unlimited bandwidth, and 25 email accounts.',
                'category' => 'Hosting',
                'type' => 'shared_hosting',
                'price' => 2.99,
                'monthly_price' => 2.99,
                'yearly_price' => 29.99,
                'billing_cycle' => 'monthly',
                'features' => ['10GB Storage', 'Unlimited Bandwidth', '25 Email Accounts', 'One-Click Installer', 'Free SSL'],
                'setup_fee' => 0,
                'provisioning_driver_key' => 'cpanel',
                'is_active' => true,
                'visible_to_resellers' => true,
                'featured' => true,
                'order' => 1,
            ],
            [
                'name' => 'Shared Hosting Pro',
                'slug' => 'shared-hosting-pro',
                'description' => 'Ideal for growing websites. Includes 50GB storage, unlimited bandwidth, and 100 email accounts.',
                'category' => 'Hosting',
                'type' => 'shared_hosting',
                'price' => 7.99,
                'monthly_price' => 7.99,
                'yearly_price' => 79.99,
                'billing_cycle' => 'monthly',
                'features' => ['50GB Storage', 'Unlimited Bandwidth', '100 Email Accounts', 'One-Click Installer', 'Free SSL', 'Priority Support'],
                'setup_fee' => 5.00,
                'provisioning_driver_key' => 'cpanel',
                'is_active' => true,
                'visible_to_resellers' => true,
                'featured' => true,
                'order' => 2,
            ],
            [
                'name' => 'Cloud VPS 1',
                'slug' => 'cloud-vps-1',
                'description' => 'Powerful virtual private server. 2GB RAM, 2 vCPU, 50GB SSD storage, and 2TB bandwidth.',
                'category' => 'Cloud',
                'type' => 'container_hosting',
                'price' => 19.99,
                'monthly_price' => 19.99,
                'yearly_price' => 199.99,
                'billing_cycle' => 'monthly',
                'features' => ['2GB RAM', '2 vCPU', '50GB SSD', '2TB Bandwidth', 'Root Access', 'Snapshots'],
                'setup_fee' => 10.00,
                'provisioning_driver_key' => 'proxmox',
                'resource_limits' => ['cpu' => 2, 'memory' => 2048, 'disk' => 50],
                'is_active' => true,
                'visible_to_resellers' => true,
                'featured' => false,
                'order' => 3,
            ],
            [
                'name' => '.com Domain',
                'slug' => 'com-domain',
                'description' => 'Register your .com domain. Includes free WHOIS privacy, DNS management, and auto-renewal.',
                'category' => 'Domains',
                'type' => 'domain',
                'price' => 12.00,
                'yearly_price' => 12.00,
                'billing_cycle' => 'annual',
                'features' => ['WHOIS Privacy', 'DNS Management', 'Email Forwarding', 'Auto-renewal'],
                'setup_fee' => 0,
                'provisioning_driver_key' => 'namecheap',
                'is_active' => true,
                'visible_to_resellers' => true,
                'featured' => false,
                'order' => 4,
            ],
            [
                'name' => 'SSL Certificate',
                'slug' => 'ssl-certificate',
                'description' => 'Secure your website with an SSL certificate. Includes installation and renewal reminders.',
                'category' => 'Security',
                'type' => 'ssl',
                'price' => 9.99,
                'yearly_price' => 9.99,
                'billing_cycle' => 'annual',
                'features' => ['256-bit Encryption', 'Installation Support', 'Renewal Reminders', 'Browser Compatibility'],
                'setup_fee' => 0,
                'provisioning_driver_key' => 'letsencrypt',
                'is_active' => true,
                'visible_to_resellers' => true,
                'featured' => false,
                'order' => 5,
            ],
            [
                'name' => 'Business Email',
                'slug' => 'business-email',
                'description' => 'Professional email hosting for your business. Includes 50GB mailbox, calendar, and contacts.',
                'category' => 'Email',
                'type' => 'email_hosting',
                'price' => 4.99,
                'monthly_price' => 4.99,
                'yearly_price' => 49.99,
                'billing_cycle' => 'monthly',
                'features' => ['50GB Mailbox', 'Calendar', 'Contacts', 'Mobile Sync', 'Spam Filter'],
                'setup_fee' => 0,
                'provisioning_driver_key' => 'roundcube',
                'is_active' => true,
                'visible_to_resellers' => true,
                'featured' => false,
                'order' => 6,
            ],
        ];

        foreach ($products as $product) {
            Product::create($product);
        }
    }
}

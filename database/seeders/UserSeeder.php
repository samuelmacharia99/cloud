<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@talksasa.cloud',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'phone' => '+254712345678',
            'company' => 'Talksasa Cloud',
            'country' => 'Kenya',
            'address' => '123 Tech Street',
            'city' => 'Nairobi',
            'postal_code' => '00100',
            'vat_number' => '254567890',
            'notes' => 'System administrator',
            'is_admin' => true,
            'is_reseller' => false,
            'status' => 'active',
        ]);

        // Staff user
        User::create([
            'name' => 'Staff User',
            'email' => 'staff@talksasa.cloud',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'phone' => '+254723456789',
            'company' => 'Talksasa Cloud',
            'country' => 'Kenya',
            'address' => '123 Tech Street',
            'city' => 'Nairobi',
            'postal_code' => '00100',
            'vat_number' => '254567890',
            'notes' => 'Support staff member',
            'is_admin' => true,
            'is_reseller' => false,
            'status' => 'active',
        ]);

        // Reseller user
        User::create([
            'name' => 'James Otieno',
            'email' => 'james.otieno@techsolutions.co.ke',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'phone' => '+254733456789',
            'company' => 'Tech Solutions Ltd',
            'country' => 'Kenya',
            'address' => '456 Business Avenue',
            'city' => 'Mombasa',
            'postal_code' => '80100',
            'vat_number' => '254789456',
            'notes' => 'Premium reseller partner',
            'is_admin' => false,
            'is_reseller' => true,
            'status' => 'active',
        ]);

        // 5 Customer users
        $customers = [
            [
                'name' => 'David Kipchoge',
                'email' => 'david.kipchoge@example.com',
                'company' => 'Kipchoge & Sons Ltd',
                'city' => 'Nairobi',
            ],
            [
                'name' => 'Mary Wanjiru',
                'email' => 'mary.wanjiru@example.com',
                'company' => 'Wanjiru Enterprises',
                'city' => 'Kisumu',
            ],
            [
                'name' => 'Samuel Kariuki',
                'email' => 'samuel.kariuki@example.com',
                'company' => 'Kariuki Tech Solutions',
                'city' => 'Nakuru',
            ],
            [
                'name' => 'Grace Mwangi',
                'email' => 'grace.mwangi@example.com',
                'company' => 'Mwangi Digital Services',
                'city' => 'Eldoret',
            ],
            [
                'name' => 'John Ochieng',
                'email' => 'john.ochieng@example.com',
                'company' => 'Ochieng Consulting',
                'city' => 'Dar es Salaam',
            ],
        ];

        $phonePrefix = ['712', '713', '714', '715', '716', '717', '718', '719', '720', '721', '722'];
        $countries = ['Kenya', 'Uganda', 'Tanzania'];
        $addresses = ['Nairobi Road', 'Kampala Street', 'Dar Street', 'Mombasa Avenue', 'Kampala Road'];
        $postalCodes = ['00100', '80100', '40100', '41600', '50100'];
        $index = 0;

        foreach ($customers as $customer) {
            User::create([
                'name' => $customer['name'],
                'email' => $customer['email'],
                'email_verified_at' => now(),
                'password' => bcrypt('password'),
                'phone' => '+' . $phonePrefix[$index % count($phonePrefix)] . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT),
                'company' => $customer['company'],
                'country' => $countries[$index % count($countries)],
                'address' => $addresses[$index % count($addresses)] . ' ' . ($index + 1),
                'city' => $customer['city'],
                'postal_code' => $postalCodes[$index % count($postalCodes)],
                'vat_number' => '254' . str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                'notes' => 'Active customer account',
                'is_admin' => false,
                'is_reseller' => false,
                'status' => 'active',
            ]);
            $index++;
        }
    }
}

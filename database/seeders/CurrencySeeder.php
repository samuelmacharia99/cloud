<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        // Base currency - Kenya Shilling
        Currency::updateOrCreate(['code' => 'KES'], [
            'name' => 'Kenya Shilling',
            'symbol' => 'KES',
            'exchange_rate' => 1.0,
            'is_active' => true,
            'order' => 1,
        ]);

        // Other major currencies
        $currencies = [
            ['code' => 'USD', 'name' => 'United States Dollar', 'symbol' => '$', 'order' => 2],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'order' => 3],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'order' => 4],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'order' => 5],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'order' => 6],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'order' => 7],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'order' => 8],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'order' => 9],
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦', 'order' => 10],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'order' => 11],
            ['code' => 'UGX', 'name' => 'Uganda Shilling', 'symbol' => 'UGX', 'order' => 12],
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'order' => 13],
            ['code' => 'BTC', 'name' => 'Bitcoin', 'symbol' => '₿', 'order' => 14],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                array_merge($currency, [
                    'exchange_rate' => 1.0, // Will be updated by cron
                    'is_active' => true,
                ])
            );
        }
    }
}

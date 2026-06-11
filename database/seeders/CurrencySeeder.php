<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * @return list<array{code: string, name: string, symbol: string, order: int}>
     */
    public static function catalog(): array
    {
        return [
            ['code' => 'KES', 'name' => 'Kenya Shilling', 'symbol' => 'KES', 'order' => 1],
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
            ['code' => 'GHS', 'name' => 'Ghanaian Cedi', 'symbol' => 'GH₵', 'order' => 14],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£', 'order' => 15],
            ['code' => 'ETB', 'name' => 'Ethiopian Birr', 'symbol' => 'Br', 'order' => 16],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED', 'order' => 17],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'order' => 18],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'order' => 19],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'order' => 20],
        ];
    }

    public function run(): void
    {
        foreach (self::catalog() as $currency) {
            $this->upsertCurrency($currency);
        }
    }

    /**
     * @param  array{code: string, name: string, symbol: string, order: int}  $currency
     */
    private function upsertCurrency(array $currency): void
    {
        if (app()->environment('production')) {
            Currency::firstOrCreate(
                ['code' => $currency['code']],
                array_merge($currency, [
                    'exchange_rate' => 1.0,
                    'is_active' => true,
                ])
            );

            return;
        }

        Currency::updateOrCreate(
            ['code' => $currency['code']],
            array_merge($currency, [
                'exchange_rate' => 1.0,
                'is_active' => true,
            ])
        );
    }
}

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
            // East Africa (EAC, Horn of Africa, and Indian Ocean)
            ['code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh', 'order' => 1],
            ['code' => 'TZS', 'name' => 'Tanzanian Shilling', 'symbol' => 'TSh', 'order' => 2],
            ['code' => 'UGX', 'name' => 'Ugandan Shilling', 'symbol' => 'USh', 'order' => 3],
            ['code' => 'RWF', 'name' => 'Rwandan Franc', 'symbol' => 'FRw', 'order' => 4],
            ['code' => 'BIF', 'name' => 'Burundian Franc', 'symbol' => 'FBu', 'order' => 5],
            ['code' => 'SSP', 'name' => 'South Sudanese Pound', 'symbol' => 'SSP', 'order' => 6],
            ['code' => 'ETB', 'name' => 'Ethiopian Birr', 'symbol' => 'Br', 'order' => 7],
            ['code' => 'SOS', 'name' => 'Somali Shilling', 'symbol' => 'Sh.So.', 'order' => 8],
            ['code' => 'DJF', 'name' => 'Djiboutian Franc', 'symbol' => 'Fdj', 'order' => 9],
            ['code' => 'ERN', 'name' => 'Eritrean Nakfa', 'symbol' => 'Nfk', 'order' => 10],
            ['code' => 'CDF', 'name' => 'Congolese Franc', 'symbol' => 'FC', 'order' => 11],
            ['code' => 'MGA', 'name' => 'Malagasy Ariary', 'symbol' => 'Ar', 'order' => 12],
            ['code' => 'MWK', 'name' => 'Malawian Kwacha', 'symbol' => 'MK', 'order' => 13],
            ['code' => 'MUR', 'name' => 'Mauritian Rupee', 'symbol' => 'Rs', 'order' => 14],
            ['code' => 'SCR', 'name' => 'Seychellois Rupee', 'symbol' => 'SR', 'order' => 15],
            // International
            ['code' => 'USD', 'name' => 'United States Dollar', 'symbol' => '$', 'order' => 20],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'order' => 21],
            ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'order' => 22],
            ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'order' => 23],
            ['code' => 'INR', 'name' => 'Indian Rupee', 'symbol' => '₹', 'order' => 24],
            ['code' => 'AUD', 'name' => 'Australian Dollar', 'symbol' => 'A$', 'order' => 25],
            ['code' => 'CAD', 'name' => 'Canadian Dollar', 'symbol' => 'C$', 'order' => 26],
            ['code' => 'SGD', 'name' => 'Singapore Dollar', 'symbol' => 'S$', 'order' => 27],
            ['code' => 'NGN', 'name' => 'Nigerian Naira', 'symbol' => '₦', 'order' => 30],
            ['code' => 'ZAR', 'name' => 'South African Rand', 'symbol' => 'R', 'order' => 31],
            ['code' => 'GHS', 'name' => 'Ghanaian Cedi', 'symbol' => 'GH₵', 'order' => 32],
            ['code' => 'EGP', 'name' => 'Egyptian Pound', 'symbol' => 'E£', 'order' => 33],
            ['code' => 'AED', 'name' => 'UAE Dirham', 'symbol' => 'AED', 'order' => 40],
            ['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SAR', 'order' => 41],
            ['code' => 'CNY', 'name' => 'Chinese Yuan', 'symbol' => '¥', 'order' => 42],
            ['code' => 'HKD', 'name' => 'Hong Kong Dollar', 'symbol' => 'HK$', 'order' => 43],
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

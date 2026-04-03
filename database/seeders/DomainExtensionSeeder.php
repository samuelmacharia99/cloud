<?php

namespace Database\Seeders;

use App\Models\DomainExtension;
use App\Models\DomainPricing;
use Illuminate\Database\Seeder;

class DomainExtensionSeeder extends Seeder
{
    public function run(): void
    {
        $extensions = [
            [
                'extension' => '.com',
                'description' => 'Commercial domain',
                'registrar' => 'ICANN',
                'retail' => [1 => 9.99, 2 => 18.99, 3 => 27.99, 5 => 45.99, 10 => 89.99],
                'wholesale' => [1 => 5.99, 2 => 11.99, 3 => 17.99, 5 => 29.99, 10 => 59.99],
            ],
            [
                'extension' => '.co.ke',
                'description' => 'Kenya Country Code',
                'registrar' => 'KENIC',
                'retail' => [1 => 12.99, 2 => 24.99, 3 => 36.99, 5 => 60.99, 10 => 119.99],
                'wholesale' => [1 => 7.99, 2 => 15.99, 3 => 23.99, 5 => 39.99, 10 => 79.99],
            ],
            [
                'extension' => '.org',
                'description' => 'Organization',
                'registrar' => 'ICANN',
                'retail' => [1 => 8.99, 2 => 16.99, 3 => 24.99, 5 => 40.99, 10 => 79.99],
                'wholesale' => [1 => 5.49, 2 => 10.99, 3 => 16.49, 5 => 26.99, 10 => 52.99],
            ],
            [
                'extension' => '.net',
                'description' => 'Network',
                'registrar' => 'ICANN',
                'retail' => [1 => 9.49, 2 => 17.99, 3 => 26.99, 5 => 44.99, 10 => 87.99],
                'wholesale' => [1 => 5.99, 2 => 11.49, 3 => 17.49, 5 => 28.99, 10 => 57.99],
            ],
            [
                'extension' => '.io',
                'description' => 'Tech/Startup',
                'registrar' => 'ICANN',
                'retail' => [1 => 34.99, 2 => 64.99, 3 => 94.99, 5 => 154.99, 10 => 299.99],
                'wholesale' => [1 => 19.99, 2 => 37.99, 3 => 55.99, 5 => 89.99, 10 => 179.99],
            ],
        ];

        foreach ($extensions as $data) {
            $ext = DomainExtension::firstOrCreate(
                ['extension' => $data['extension']],
                [
                    'description' => $data['description'],
                    'registrar' => $data['registrar'],
                    'enabled' => true,
                    'dns_management' => true,
                    'auto_renewal' => true,
                ]
            );

            // Create retail pricing
            foreach ($data['retail'] as $period => $price) {
                DomainPricing::updateOrCreate(
                    [
                        'domain_extension_id' => $ext->id,
                        'period_years' => $period,
                        'tier' => 'retail',
                    ],
                    [
                        'price' => $price,
                        'setup_fee' => 0,
                        'enabled' => true,
                    ]
                );
            }

            // Create wholesale pricing
            foreach ($data['wholesale'] as $period => $price) {
                DomainPricing::updateOrCreate(
                    [
                        'domain_extension_id' => $ext->id,
                        'period_years' => $period,
                        'tier' => 'wholesale',
                    ],
                    [
                        'price' => $price,
                        'setup_fee' => 0,
                        'enabled' => true,
                    ]
                );
            }
        }
    }
}

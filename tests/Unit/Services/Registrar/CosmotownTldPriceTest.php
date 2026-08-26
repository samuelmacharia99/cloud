<?php

namespace Tests\Unit\Services\Registrar;

use App\Enums\RegistrarDriver;
use App\Models\Registrar;
use App\Services\Registrar\Cosmotown\CosmotownClient;
use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\Cosmotown\CosmotownTldPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CosmotownTldPriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_flat_price_fields(): void
    {
        $price = CosmotownTldPrice::fromPayload('com', [
            'register' => 7.59,
            'renew' => 11.08,
            'transfer' => 11.08,
            'currency' => 'USD',
        ]);

        $this->assertSame('com', $price->tld);
        $this->assertSame(7.59, $price->registerUsd);
        $this->assertSame(11.08, $price->renewUsd);
        $this->assertSame(11.08, $price->transferUsd);
        $this->assertSame('USD', $price->currency);
    }

    public function test_parses_nested_price_fields_and_falls_back_renew_and_transfer_to_register(): void
    {
        $price = CosmotownTldPrice::fromPayload('.net', [
            'pricing' => [
                'registration_price' => '9.97',
                'currency_code' => 'usd',
            ],
        ]);

        $this->assertSame('net', $price->tld);
        $this->assertSame(9.97, $price->registerUsd);
        $this->assertSame(9.97, $price->renewUsd);
        $this->assertSame(9.97, $price->transferUsd);
    }

    public function test_parses_year_maps_and_nested_amount_objects(): void
    {
        $price = CosmotownTldPrice::fromPayload('com', [
            'register' => ['1' => '7.59', '2' => '15.18'],
            'renew' => ['price' => 11.08],
            'transfer' => ['amount' => '11.08 USD'],
        ]);

        $this->assertSame(7.59, $price->registerUsd);
        $this->assertSame(11.08, $price->renewUsd);
        $this->assertSame(11.08, $price->transferUsd);
    }

    public function test_parses_tld_keyed_payload(): void
    {
        $price = CosmotownTldPrice::fromPayload('.com', [
            'com' => [
                'Register' => 7.59,
                'Renew' => 11.08,
                'Transfer' => 11.08,
                'currency' => 'USD',
            ],
        ]);

        $this->assertSame('com', $price->tld);
        $this->assertSame(7.59, $price->registerUsd);
        $this->assertSame(11.08, $price->renewUsd);
    }

    public function test_parses_result_data_reg_price_shape(): void
    {
        $price = CosmotownTldPrice::fromPayload('shop', [
            'resultData' => [
                'tld' => 'shop',
                'regPrice' => '1.18',
                'renewPrice' => '32.18',
                'transPrice' => '32.18',
            ],
        ]);

        $this->assertSame(1.18, $price->registerUsd);
        $this->assertSame(32.18, $price->renewUsd);
        $this->assertSame(32.18, $price->transferUsd);
    }

    public function test_parses_typed_price_list(): void
    {
        $price = CosmotownTldPrice::fromPayload('io', [
            'prices' => [
                ['type' => 'register', 'price' => 32.99],
                ['action' => 'renew', 'amount' => 32.99],
                ['operation' => 'transfer', 'cost' => 32.99],
            ],
        ]);

        $this->assertSame(32.99, $price->registerUsd);
        $this->assertSame(32.99, $price->renewUsd);
        $this->assertSame(32.99, $price->transferUsd);
    }

    public function test_catalog_indexes_tld_keyed_prices(): void
    {
        $catalog = CosmotownTldPrice::catalogFromPayload([
            'com' => ['register' => 7.59, 'renew' => 11.08, 'transfer' => 11.08],
            'net' => ['register' => 9.97, 'renew' => 9.97, 'transfer' => 9.97],
        ]);

        $this->assertArrayHasKey('com', $catalog);
        $this->assertArrayHasKey('net', $catalog);
        $this->assertSame(7.59, $catalog['com']->registerUsd);
        $this->assertSame(9.97, $catalog['net']->registerUsd);
    }

    public function test_rejects_payload_without_price_amounts_and_includes_keys(): void
    {
        try {
            CosmotownTldPrice::fromPayload('com', [
                'registrant' => [
                    'FirstName' => 'Example',
                ],
            ]);
            $this->fail('Expected CosmotownException');
        } catch (CosmotownException $e) {
            $this->assertStringContainsString('Response keys: registrant', $e->getMessage());
            $this->assertStringContainsString('contact fields instead of TLD prices', $e->getMessage());
        }
    }

    public function test_client_falls_back_to_catalog_when_per_tld_body_has_no_amounts(): void
    {
        Http::fake([
            'sandbox.cosmotown.com/v1/reseller/tldprice*' => Http::sequence()
                ->push(['registrant' => ['FirstName' => 'ad']], 200)
                ->push([
                    'com' => [
                        'register' => ['1' => 7.59],
                        'renew' => ['1' => 11.08],
                        'transfer' => ['1' => 11.08],
                    ],
                ], 200),
        ]);

        $registrar = Registrar::query()->create([
            'name' => 'Cosmotown',
            'slug' => 'cosmotown-price-'.uniqid(),
            'driver' => RegistrarDriver::Cosmotown,
            'environment' => 'sandbox',
            'is_active' => true,
            'is_default' => false,
            'config' => ['api_token' => 'test-token'],
            'sort_order' => 0,
        ]);

        $price = CosmotownClient::forRegistrar($registrar)->getTldPrice('com');

        $this->assertSame(7.59, $price->registerUsd);
        $this->assertSame(11.08, $price->renewUsd);
        $this->assertSame(11.08, $price->transferUsd);
    }
}

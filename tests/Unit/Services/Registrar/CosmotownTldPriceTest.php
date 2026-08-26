<?php

namespace Tests\Unit\Services\Registrar;

use App\Services\Registrar\Cosmotown\CosmotownException;
use App\Services\Registrar\Cosmotown\CosmotownTldPrice;
use Tests\TestCase;

class CosmotownTldPriceTest extends TestCase
{
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

    public function test_rejects_payload_without_price_amounts(): void
    {
        $this->expectException(CosmotownException::class);

        CosmotownTldPrice::fromPayload('com', [
            'registrant' => [
                'FirstName' => 'Example',
            ],
        ]);
    }
}

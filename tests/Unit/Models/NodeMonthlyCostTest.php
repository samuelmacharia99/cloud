<?php

namespace Tests\Unit\Models;

use App\Models\Currency;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeMonthlyCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_cost_kes_converts_with_the_live_usd_rate(): void
    {
        $this->seedUsdRate(0.0077);

        $node = Node::factory()->create(['monthly_cost_usd' => 100]);

        $this->assertEqualsWithDelta(12987.01, $node->monthlyCostKes(), 0.01);
    }

    public function test_monthly_cost_kes_is_null_when_spend_is_not_set(): void
    {
        $this->seedUsdRate(0.0077);

        $node = Node::factory()->create(['monthly_cost_usd' => null]);

        $this->assertNull($node->monthlyCostKes());
    }

    public function test_monthly_cost_kes_is_null_when_usd_rate_is_missing(): void
    {
        $node = Node::factory()->create(['monthly_cost_usd' => 50]);

        $this->assertNull(Node::usdToKesRate());
        $this->assertNull($node->monthlyCostKes());
    }

    public function test_monthly_cost_kes_is_null_when_usd_rate_is_zero(): void
    {
        Currency::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'symbol' => '$',
            'exchange_rate' => 0,
            'is_active' => true,
            'order' => 20,
        ]);

        $node = Node::factory()->create(['monthly_cost_usd' => 50]);

        $this->assertNull($node->monthlyCostKes());
    }

    private function seedUsdRate(float $usdPerKes): void
    {
        Currency::create([
            'code' => 'KES',
            'name' => 'Kenyan Shilling',
            'symbol' => 'KSh',
            'exchange_rate' => 1.0,
            'is_active' => true,
            'order' => 1,
        ]);
        Currency::create([
            'code' => 'USD',
            'name' => 'United States Dollar',
            'symbol' => '$',
            'exchange_rate' => $usdPerKes,
            'is_active' => true,
            'order' => 20,
        ]);
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\ResellerDomainOrder;
use Tests\TestCase;

class ResellerDomainOrderTest extends TestCase
{
    public function test_full_domain_name_avoids_double_dot_for_dotted_extension(): void
    {
        $order = new ResellerDomainOrder([
            'domain_name' => 'example',
            'extension' => '.co.ke',
        ]);

        $this->assertSame('example.co.ke', $order->fullDomainName());
    }

    public function test_full_domain_name_adds_dot_when_extension_omits_it(): void
    {
        $order = new ResellerDomainOrder([
            'domain_name' => 'example',
            'extension' => 'com',
        ]);

        $this->assertSame('example.com', $order->fullDomainName());
    }

    public function test_full_domain_name_trims_trailing_dot_from_label(): void
    {
        $order = new ResellerDomainOrder([
            'domain_name' => 'example.',
            'extension' => '.co.ke',
        ]);

        $this->assertSame('example.co.ke', $order->fullDomainName());
    }
}

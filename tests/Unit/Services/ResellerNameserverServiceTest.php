<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\NodeNameserverService;
use App\Services\ResellerNameserverService;
use Tests\TestCase;

class ResellerNameserverServiceTest extends TestCase
{
    public function test_defaults_for_reseller_use_platform_when_configured(): void
    {
        $reseller = new User([
            'settings' => [
                'nameservers' => [
                    'use_platform_defaults' => true,
                    'ns1' => 'ns1.custom.test',
                ],
            ],
        ]);

        $nodeService = $this->createMock(NodeNameserverService::class);
        $nodeService->method('platformDefaults')->willReturn([
            'ns1' => 'ns1.platform.test',
            'ns2' => 'ns2.platform.test',
            'ns3' => null,
            'ns4' => null,
        ]);

        $service = new ResellerNameserverService($nodeService);

        $this->assertSame([
            'ns1' => 'ns1.platform.test',
            'ns2' => 'ns2.platform.test',
            'ns3' => null,
            'ns4' => null,
        ], $service->defaultsForReseller($reseller));
    }

    public function test_defaults_for_reseller_use_custom_nameservers_when_configured(): void
    {
        $reseller = new User([
            'settings' => [
                'nameservers' => [
                    'use_platform_defaults' => false,
                    'ns1' => 'ns1.reseller.test',
                    'ns2' => 'ns2.reseller.test',
                ],
            ],
        ]);

        $service = new ResellerNameserverService($this->createMock(NodeNameserverService::class));

        $this->assertSame([
            'ns1' => 'ns1.reseller.test',
            'ns2' => 'ns2.reseller.test',
            'ns3' => null,
            'ns4' => null,
        ], $service->defaultsForReseller($reseller));
    }

    public function test_defaults_for_customer_use_reseller_nameservers_when_configured(): void
    {
        $reseller = new User([
            'id' => 10,
            'settings' => [
                'nameservers' => [
                    'use_platform_defaults' => false,
                    'ns1' => 'ns1.reseller.test',
                    'ns2' => 'ns2.reseller.test',
                ],
            ],
        ]);

        $customer = new User([
            'reseller_id' => 10,
        ]);
        $customer->setRelation('reseller', $reseller);

        $service = new ResellerNameserverService($this->createMock(NodeNameserverService::class));

        $this->assertSame([
            'ns1' => 'ns1.reseller.test',
            'ns2' => 'ns2.reseller.test',
            'ns3' => null,
            'ns4' => null,
        ], $service->defaultsForCustomer($customer));
    }

    public function test_resolve_for_customer_item_honors_reseller_default_toggle(): void
    {
        $reseller = new User([
            'id' => 10,
            'settings' => [
                'nameservers' => [
                    'use_platform_defaults' => false,
                    'ns1' => 'ns1.reseller.test',
                    'ns2' => 'ns2.reseller.test',
                ],
            ],
        ]);

        $customer = new User([
            'reseller_id' => 10,
        ]);
        $customer->setRelation('reseller', $reseller);

        $service = new ResellerNameserverService($this->createMock(NodeNameserverService::class));

        $resolved = $service->resolveForCustomerItem($customer, [
            'type' => 'domain',
            'nameservers' => [
                'use_default' => true,
                'ns1' => 'ns1.stale.test',
            ],
        ]);

        $this->assertSame('ns1.reseller.test', $resolved['ns1']);
        $this->assertSame('ns2.reseller.test', $resolved['ns2']);
    }

    public function test_defaults_for_customer_fall_back_to_platform(): void
    {
        $customer = new User([
            'reseller_id' => null,
        ]);

        $nodeService = $this->createMock(NodeNameserverService::class);
        $nodeService->method('platformDefaults')->willReturn([
            'ns1' => 'ns1.platform.test',
            'ns2' => 'ns2.platform.test',
            'ns3' => null,
            'ns4' => null,
        ]);

        $service = new ResellerNameserverService($nodeService);

        $this->assertSame([
            'ns1' => 'ns1.platform.test',
            'ns2' => 'ns2.platform.test',
            'ns3' => null,
            'ns4' => null,
        ], $service->defaultsForCustomer($customer));
    }

    public function test_parse_submitted_custom_nameservers(): void
    {
        $reseller = new User([
            'settings' => [
                'nameservers' => [
                    'use_platform_defaults' => true,
                ],
            ],
        ]);

        $service = new ResellerNameserverService($this->createMock(NodeNameserverService::class));

        $parsed = $service->parseSubmitted([
            'use_default' => false,
            'ns1' => 'NS1.CUSTOM.TEST',
            'ns2' => 'ns2.custom.test',
        ], $reseller);

        $this->assertFalse($parsed['use_default']);
        $this->assertSame('ns1.custom.test', $parsed['ns1']);
        $this->assertSame('ns2.custom.test', $parsed['ns2']);
    }
}

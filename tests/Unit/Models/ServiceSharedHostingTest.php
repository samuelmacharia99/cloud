<?php

namespace Tests\Unit\Models;

use App\Models\Node;
use App\Models\Product;
use App\Models\Service;
use PHPUnit\Framework\TestCase;

class ServiceSharedHostingTest extends TestCase
{
    public function test_legacy_shared_hosting_with_username_is_recognized_without_driver_key(): void
    {
        $product = new Product(['type' => 'shared_hosting', 'provisioning_driver_key' => null]);
        $service = new Service([
            'provisioning_driver_key' => null,
            'external_reference' => 'interfin',
            'service_meta' => ['username' => 'interfin', 'package' => 'silver'],
        ]);
        $service->setRelation('product', $product);

        $this->assertTrue($service->isSharedHosting());
    }

    public function test_shared_hosting_on_directadmin_node_is_recognized_without_driver_key(): void
    {
        $product = new Product(['type' => 'shared_hosting']);
        $node = new Node(['type' => 'directadmin']);
        $service = new Service(['provisioning_driver_key' => null, 'node_id' => 1]);
        $service->setRelation('product', $product);
        $service->setRelation('node', $node);

        $this->assertTrue($service->isSharedHosting());
    }

    public function test_shared_hosting_with_node_id_but_unloaded_relation_is_recognized(): void
    {
        $product = new Product(['type' => 'shared_hosting']);
        $service = new Service(['provisioning_driver_key' => null, 'node_id' => 1]);
        $service->setRelation('product', $product);

        $this->assertTrue($service->isSharedHosting());
    }
}

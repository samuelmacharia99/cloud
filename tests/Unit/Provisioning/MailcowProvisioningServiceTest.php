<?php

namespace Tests\Unit\Provisioning;

use App\Models\Product;
use App\Services\Provisioning\MailcowProvisioningService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailcowProvisioningServiceTest extends TestCase
{
    #[Test]
    public function it_reads_limits_from_product_resource_limits(): void
    {
        $product = new Product([
            'resource_limits' => [
                'mailboxes' => 5,
                'aliases' => 15,
                'quota_mb' => 10240,
                'mailbox_quota_mb' => 2048,
            ],
        ]);

        $limits = (new MailcowProvisioningService)->limitsForProduct($product);

        $this->assertSame(5, $limits['mailboxes']);
        $this->assertSame(15, $limits['aliases']);
        $this->assertSame(10240, $limits['quota_mb']);
        $this->assertSame(2048, $limits['mailbox_quota_mb']);
    }
}

<?php

namespace Tests\Unit\Services\Provisioning;

use App\Services\Provisioning\DirectAdminDomainValidator;
use Tests\TestCase;

class DirectAdminDomainValidatorTest extends TestCase
{
    public function test_rejects_placeholder_local_domains(): void
    {
        $validator = new DirectAdminDomainValidator;

        $this->assertFalse($validator->isValid('customer.local'));
        $this->assertTrue($validator->isPlaceholder('customer.local'));
    }

    public function test_accepts_valid_fqdn(): void
    {
        $validator = new DirectAdminDomainValidator;

        $this->assertSame('example.co.ke', $validator->assertValid('Example.CO.KE'));
        $this->assertTrue($validator->isValid('mysite.com'));
    }

    public function test_split_fqdn(): void
    {
        $validator = new DirectAdminDomainValidator;

        $parts = $validator->splitFqdn('shop.example.co.ke');

        $this->assertSame('shop', $parts['name']);
        $this->assertSame('.example.co.ke', $parts['extension']);
    }
}

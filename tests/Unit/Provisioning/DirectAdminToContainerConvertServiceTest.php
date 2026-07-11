<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\DirectAdminToContainerConvertService;
use Tests\TestCase;

class DirectAdminToContainerConvertServiceTest extends TestCase
{
    public function test_classify_mailboxes_treats_username_as_default(): void
    {
        $service = app(DirectAdminToContainerConvertService::class);

        $result = $service->classifyMailboxes('acmeuser', [
            ['account' => 'acmeuser', 'email' => 'acmeuser@example.com'],
            ['account' => 'info', 'email' => 'info@example.com'],
            ['account' => 'sales@example.com', 'email' => 'sales@example.com'],
        ]);

        $this->assertTrue($result['has_extra_mailboxes']);
        $this->assertCount(1, $result['default_mailboxes']);
        $this->assertSame('acmeuser@example.com', $result['default_mailboxes'][0]['email']);
        $this->assertCount(2, $result['extra_mailboxes']);
    }

    public function test_classify_mailboxes_only_default(): void
    {
        $service = app(DirectAdminToContainerConvertService::class);

        $result = $service->classifyMailboxes('acmeuser', [
            ['account' => 'acmeuser', 'email' => 'acmeuser@example.com'],
        ]);

        $this->assertFalse($result['has_extra_mailboxes']);
        $this->assertCount(1, $result['default_mailboxes']);
        $this->assertSame([], $result['extra_mailboxes']);
    }
}

<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerGitPull;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerGitPullTest extends TestCase
{
    #[Test]
    public function it_truncates_oversized_error_messages_before_persist(): void
    {
        $pull = new ContainerGitPull;
        $pull->error_message = str_repeat('npm warn deprecated pkg@1.0.0: x', 2000);

        $this->assertNotNull($pull->error_message);
        $this->assertLessThanOrEqual(ContainerGitPull::ERROR_MESSAGE_MAX_BYTES + 10, strlen($pull->error_message));
        $this->assertStringContainsString('...', $pull->error_message);
        $this->assertStringContainsString('npm warn deprecated', $pull->error_message);
    }

    #[Test]
    public function it_keeps_short_error_messages_intact(): void
    {
        $pull = new ContainerGitPull;
        $pull->error_message = 'Could not clone https://github.com/acme/app: missing token.';

        $this->assertSame(
            'Could not clone https://github.com/acme/app: missing token.',
            $pull->error_message
        );
    }
}

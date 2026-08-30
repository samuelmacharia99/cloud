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
    public function it_redacts_database_credentials_when_storing_error_messages(): void
    {
        $password = 'superSecretDbPass99';
        $pull = new ContainerGitPull;
        $pull->error_message = "Node post-pull step failed: DATABASE_URL=mysql://u1_s1:{$password}@app-db:3306/s1_db";

        $this->assertStringNotContainsString($password, (string) $pull->error_message);
        $this->assertStringContainsString('mysql://[credentials]@app-db:3306/s1_db', (string) $pull->error_message);
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

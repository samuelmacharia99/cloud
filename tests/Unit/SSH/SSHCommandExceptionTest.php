<?php

namespace Tests\Unit\SSH;

use App\Exceptions\SSH\SSHCommandException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SSHCommandExceptionTest extends TestCase
{
    #[Test]
    public function it_redacts_repository_credentials_and_tokens_everywhere(): void
    {
        $token = 'ghp_superSecretToken123';
        $exception = new SSHCommandException(
            "git clone https://x-access-token:{$token}@github.com/acme/app.git",
            "fatal: https://x-access-token:{$token}@github.com/acme/app.git failed",
            'request token='.$token
        );

        $this->assertStringNotContainsString($token, $exception->getMessage());
        $this->assertStringNotContainsString($token, $exception->command);
        $this->assertStringNotContainsString($token, $exception->output);
        $this->assertStringContainsString('[credentials]@github.com', $exception->getMessage());
    }

    #[Test]
    public function it_redacts_database_url_credentials(): void
    {
        $password = 'superSecretDbPass99';
        $exception = new SSHCommandException(
            "docker run sh -c 'DATABASE_URL=mysql://u30_s165:{$password}@app-db:3306/s165_db next build'",
            "▲ Next.js 14.2.35\nPrisma could not connect",
            'Command exited with status 1'
        );

        $this->assertStringNotContainsString($password, $exception->getMessage());
        $this->assertStringContainsString('mysql://[credentials]@app-db:3306/s165_db', $exception->getMessage());
    }
}

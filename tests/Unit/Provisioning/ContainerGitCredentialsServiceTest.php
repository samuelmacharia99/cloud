<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ContainerGitCredentialsService;
use Illuminate\Support\Facades\Crypt;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerGitCredentialsServiceTest extends TestCase
{
    #[Test]
    public function it_strips_credentials_from_repository_urls(): void
    {
        $service = new ContainerGitCredentialsService;

        [$cleanUrl, $token] = $service->stripUrlCredentials('https://user:secret@github.com/acme/app.git');

        $this->assertSame('https://github.com/acme/app.git', $cleanUrl);
        $this->assertSame('secret', $token);
    }

    #[Test]
    public function it_builds_authenticated_clone_urls_from_encrypted_tokens(): void
    {
        $service = new ContainerGitCredentialsService;
        $model = new Service([
            'service_meta' => [
                'source_repo_url' => 'https://github.com/acme/app.git',
                'source_repo_token_encrypted' => Crypt::encryptString('ghp_testtoken'),
            ],
        ]);

        $url = $service->authenticatedCloneUrl($model, 'https://github.com/acme/app.git');

        $this->assertStringContainsString('x-access-token', $url);
        $this->assertStringContainsString('ghp_testtoken', $url);
        $this->assertStringContainsString('github.com/acme/app.git', $url);
    }

    #[Test]
    public function it_masks_repository_urls_without_exposing_tokens(): void
    {
        $service = new ContainerGitCredentialsService;

        $this->assertSame(
            'https://github.com/acme/app.git',
            $service->maskRepositoryUrl('https://token@github.com/acme/app.git')
        );
    }
}

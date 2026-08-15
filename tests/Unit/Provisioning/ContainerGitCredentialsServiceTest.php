<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ContainerAppDirectoryService;
use App\Services\Provisioning\ContainerGitCredentialsService;
use App\Services\Provisioning\ContainerGitRepositoryService;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class ContainerGitCredentialsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    #[Test]
    public function existing_pull_uses_askpass_without_putting_the_pat_in_the_command(): void
    {
        $token = 'ghp_privateTokenThatMustNeverLeak';
        $model = new Service([
            'service_meta' => [
                'source_repo_token_encrypted' => Crypt::encryptString($token),
            ],
        ]);
        $ssh = Mockery::mock(SSHService::class);
        $capturedCommand = '';

        $ssh->shouldReceive('upload')
            ->once()
            ->withArgs(function (string $contents, string $path) use ($token): bool {
                return str_contains($contents, $token)
                    && str_starts_with($path, '/tmp/talksasa-git-askpass-');
            });
        $ssh->shouldReceive('exec')->andReturnUsing(
            function (string $command, int $timeout) use (&$capturedCommand): string {
                if (str_contains($command, 'git clone')) {
                    $capturedCommand = $command;

                    return 'cloned';
                }

                if (str_starts_with($command, 'find ')) {
                    return '/opt/talksasa/containers/app/.git';
                }

                return '';
            }
        );

        $repository = new ContainerGitRepositoryService(new ContainerAppDirectoryService);
        $method = new ReflectionMethod($repository, 'syncToHost');
        $result = $method->invoke(
            $repository,
            $ssh,
            $model,
            '/opt/talksasa/containers/app',
            'https://github.com/acme/private.git',
            'feature/new',
        );

        $this->assertSame('cloned', $result['output']);
        $this->assertStringNotContainsString($token, $capturedCommand);
        $this->assertStringContainsString('GIT_ASKPASS=', $capturedCommand);
        $this->assertStringContainsString('git clone --depth=1 --branch', $capturedCommand);
        $this->assertNotNull($result['previous_path']);
    }
}

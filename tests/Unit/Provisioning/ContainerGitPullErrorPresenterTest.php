<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerGitPull;
use App\Services\Provisioning\ContainerGitPullErrorPresenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerGitPullErrorPresenterTest extends TestCase
{
    private ContainerGitPullErrorPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new ContainerGitPullErrorPresenter;
    }

    #[Test]
    public function it_explains_git_authentication_failures_without_discarding_details(): void
    {
        $pull = $this->failedPull(
            'sync',
            'Sync repository',
            'SSH command failed: git clone. Error: fatal: Authentication failed for repository.',
        );

        $error = $this->presenter->present($pull);

        $this->assertSame('Couldn’t authenticate with the Git host.', $error['title']);
        $this->assertStringContainsString('personal access token', $error['guidance']);
        $this->assertSame($pull->error_message, $error['details']);
    }

    #[Test]
    public function it_uses_the_failed_pipeline_step_for_actionable_guidance(): void
    {
        $pull = $this->failedPull(
            'migrations',
            'Run database migrations',
            'SQLSTATE[42S02]: Base table or view not found.',
        );

        $error = $this->presenter->present($pull);

        $this->assertSame('Database migrations failed.', $error['title']);
        $this->assertStringContainsString('migration error', $error['guidance']);
    }

    #[Test]
    public function it_does_not_misclassify_build_permission_errors_as_git_authentication(): void
    {
        $pull = $this->failedPull(
            'frontend',
            'Build frontend assets',
            'npm error EACCES: permission denied, mkdir /app/node_modules.',
        );

        $error = $this->presenter->present($pull);

        $this->assertSame('The application build failed.', $error['title']);
    }

    #[Test]
    public function it_returns_no_error_for_a_successful_pull(): void
    {
        $pull = new ContainerGitPull([
            'status' => ContainerGitPull::STATUS_COMPLETED,
        ]);

        $this->assertNull($this->presenter->present($pull));
    }

    private function failedPull(string $stepKey, string $stepLabel, string $details): ContainerGitPull
    {
        return new ContainerGitPull([
            'status' => ContainerGitPull::STATUS_FAILED,
            'steps' => [[
                'key' => $stepKey,
                'label' => $stepLabel,
                'status' => 'failed',
            ]],
            'error_message' => $details,
        ]);
    }
}

<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerCommandOutputPresenter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every failure this session began as a message that showed everything except
 * the reason: Compose's narration first, then npm's epilogue, with the one line
 * that explained the failure squeezed out between them.
 */
class ContainerCommandOutputPresenterTest extends TestCase
{
    #[Test]
    public function it_keeps_the_programs_own_words_out_of_npms_epilogue(): void
    {
        $summary = $this->presenter()->summary(<<<'OUT'
        SSH command failed: cd /opt/talksasa/containers/x && docker compose exec -T app sh -lc 'npm run db:migrate'
        Error: Command exited with status 1
        Output: Error: connect ECONNREFUSED 10.0.0.4:5432
        npm error code 1
        npm error path /app/apps/api
        npm error workspace @sameplan/api@0.1.0
        npm error command failed
        npm error command sh -c node scripts/migrate.mjs
        OUT);

        $this->assertStringContainsString('ECONNREFUSED', $summary);
        $this->assertStringNotContainsString('npm error', $summary);
    }

    #[Test]
    public function it_falls_back_to_the_epilogue_when_the_program_said_nothing(): void
    {
        $summary = $this->presenter()->summary("npm error code 1\nnpm error command failed\n");

        $this->assertStringContainsString('npm error', $summary);
    }

    #[Test]
    public function it_drops_compose_narration_that_used_to_fill_the_line(): void
    {
        $summary = $this->presenter()->summary(<<<'OUT'
        time="2026-09-10T12:09:40+02:00" level=warning msg="volume \"db_data\" already exists but was not created by Docker Compose"
        Container user-493-service-454-nodejs-db Running
        Container user-493-service-454-nodejs-api Started
        ERROR: extension "postgis" is not available
        OUT);

        $this->assertSame('ERROR: extension "postgis" is not available', $summary);
    }

    #[Test]
    public function it_never_hands_back_a_password_or_a_host_path(): void
    {
        $summary = $this->presenter()->summary(
            "cd /opt/talksasa/containers/user-1-app && docker compose exec -T -e PGPASSWORD=hunter2 db psql\n"
            ."FATAL: password authentication failed\n"
        );

        $this->assertStringNotContainsString('hunter2', $summary);
        $this->assertStringNotContainsString('/opt/talksasa', $summary);
        $this->assertStringContainsString('password authentication failed', $summary);
    }

    #[Test]
    public function the_summary_reads_from_the_end_where_the_error_is(): void
    {
        $noise = str_repeat("Container app Running\n", 40);
        $summary = $this->presenter()->summary($noise.'relation "users" does not exist');

        $this->assertSame('relation "users" does not exist', $summary);
    }

    #[Test]
    public function the_full_output_keeps_the_epilogue_the_summary_left_out(): void
    {
        $full = $this->presenter()->full("Error: boom\nnpm error code 1\nnpm error command failed\n");

        $this->assertStringContainsString('Error: boom', $full);
        $this->assertStringContainsString('npm error code 1', $full);
    }

    #[Test]
    public function nothing_to_say_is_reported_as_nothing(): void
    {
        $this->assertSame('', $this->presenter()->summary("Container app Running\n"));
    }

    private function presenter(): ContainerCommandOutputPresenter
    {
        return app(ContainerCommandOutputPresenter::class);
    }
}

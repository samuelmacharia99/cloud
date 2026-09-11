<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerRuntimeCrashReader;
use App\Services\Provisioning\ContainerRuntimeInspector;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform learned to read a Python crash months ago and then used that
 * knowledge in exactly one place, inside the split web/API readiness loop. A
 * single-container Python app never benefited from any of it. This is the seam
 * that lets every stack ask the same question, and the inspector field that
 * lets a caller tell "running" from "running again".
 */
class ContainerRuntimeCrashReaderTest extends TestCase
{
    #[Test]
    public function a_python_crash_is_read_down_to_the_variable_names(): void
    {
        $crash = $this->reader()->read('python', <<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 2 validation errors for Settings
        SECRET_KEY
          Field required [type=missing, input_value={'DB_HOST': 'db'}, input_type=dict]
        NES_API_KEY
          Field required [type=missing, input_value={'DB_HOST': 'db'}, input_type=dict]
        LOG);

        $this->assertSame(['SECRET_KEY', 'NES_API_KEY'], $crash['missing_variables']);
        $this->assertTrue($crash['recognised']);
    }

    #[Test]
    public function a_stack_with_no_presenter_still_gets_the_end_of_its_log(): void
    {
        // Go has no presenter yet, so the honest answer is what the application
        // said, not a diagnosis for a language nothing here has learned to read.
        $crash = $this->reader()->read('go', "goroutine 1 [running]:\npanic: missing config for TZ\n");

        $this->assertSame([], $crash['missing_variables']);
        $this->assertFalse($crash['recognised']);
        $this->assertStringContainsString('panic: missing config for TZ', $crash['message']);
    }

    #[Test]
    public function a_python_log_the_presenter_cannot_read_falls_back_rather_than_guessing(): void
    {
        $crash = $this->reader()->read('python', "something went wrong at 03:14\nand again at 03:15\n");

        $this->assertSame([], $crash['missing_variables']);
        $this->assertFalse($crash['recognised']);
        $this->assertStringContainsString('something went wrong', $crash['message']);
    }

    #[Test]
    public function an_empty_log_never_tells_anybody_to_go_and_read_it(): void
    {
        $crash = $this->reader()->read('python', '   ');

        $this->assertStringNotContainsString('check the log', strtolower($crash['message']));
        $this->assertStringContainsString('before your code ran', $crash['message']);
    }

    #[Test]
    public function the_inspector_reports_a_restart_count_without_losing_its_old_answers(): void
    {
        $inspect = $this->inspect('restarting|false|false|1|true|7');

        $this->assertSame(7, $inspect['restart_count']);
        $this->assertTrue($inspect['restarting']);
        $this->assertFalse($inspect['running']);
        $this->assertSame('restarting', $inspect['state']);
        $this->assertSame(1, $inspect['exit_code']);
        $this->assertFalse($inspect['oom_killed']);
        $this->assertFalse($inspect['missing']);
    }

    #[Test]
    public function a_node_answering_with_the_old_four_fields_still_works(): void
    {
        // Padding rather than throwing: a docker that answers short should
        // degrade to what the platform knew before, not take a deploy down.
        $inspect = $this->inspect('running|true|false|0');

        $this->assertTrue($inspect['running']);
        $this->assertSame(0, $inspect['restart_count']);
        $this->assertFalse($inspect['restarting']);
    }

    #[Test]
    public function a_missing_container_reports_no_restarts_rather_than_none_at_all(): void
    {
        $inspect = $this->inspect('');

        $this->assertTrue($inspect['missing']);
        $this->assertSame(0, $inspect['restart_count']);
        $this->assertFalse($inspect['restarting']);
    }

    /**
     * @return array<string, mixed>
     */
    private function inspect(string $dockerOutput): array
    {
        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturn($dockerOutput);

        return app(ContainerRuntimeInspector::class)->inspect($ssh, 'user-1-service-457-python');
    }

    private function reader(): ContainerRuntimeCrashReader
    {
        return app(ContainerRuntimeCrashReader::class);
    }
}

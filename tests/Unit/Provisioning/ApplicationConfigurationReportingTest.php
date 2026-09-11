<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ApplicationEnvironmentRequirements;
use App\Services\Provisioning\ContainerApplicationRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Two ways the platform used to describe a crashed application badly: it kept
 * demanding variables the customer had already deleted from their code, and it
 * reported the top of a Python traceback, which is paths, rather than the
 * bottom, which is the reason.
 */
class ApplicationConfigurationReportingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_required_list_is_what_the_last_crash_said(): void
    {
        $service = Service::factory()->create();
        $requirements = app(ApplicationEnvironmentRequirements::class);

        $requirements->rememberRequired($service, ['MPESA_SHORTCODE', 'NES_API_KEY']);
        // The customer deleted the M-Pesa integration; the app now wants one key.
        $requirements->rememberRequired($service->fresh(), ['NES_API_KEY']);

        $this->assertSame(['NES_API_KEY'], $requirements->required($service->fresh()));
    }

    #[Test]
    public function a_hold_with_nothing_to_report_leaves_the_last_answer_alone(): void
    {
        $service = Service::factory()->create();
        $requirements = app(ApplicationEnvironmentRequirements::class);

        $requirements->rememberRequired($service, ['NES_API_KEY']);
        $requirements->rememberRequired($service->fresh(), []);

        $this->assertSame(['NES_API_KEY'], $requirements->required($service->fresh()));
    }

    #[Test]
    public function a_variable_the_customer_supplied_stops_being_outstanding(): void
    {
        $service = Service::factory()->create();
        $requirements = app(ApplicationEnvironmentRequirements::class);
        $requirements->rememberRequired($service, ['NES_API_KEY', 'STRIPE_KEY']);

        $outstanding = $requirements->unsatisfied(
            $requirements->required($service->fresh()),
            ['NES_API_KEY' => 'set-by-customer'],
        );

        $this->assertSame(['STRIPE_KEY'], $outstanding);
    }

    #[Test]
    public function a_long_traceback_keeps_the_line_that_names_the_exception(): void
    {
        $traceback = "Traceback (most recent call last):\n";
        for ($i = 0; $i < 400; $i++) {
            $traceback .= '  File "/usr/local/lib/python3.11/site-packages/very/deep/module'.$i.".py\", line {$i}, in _serve\n";
        }
        $traceback .= "pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings\n";

        $summary = app(ContainerApplicationRuntimeService::class)->summarizePythonContainerLogs($traceback, 1000);

        $this->assertStringContainsString('ValidationError: 1 validation error for Settings', $summary);
        $this->assertLessThanOrEqual(1000, mb_strlen($summary));
    }

    #[Test]
    public function a_short_log_is_returned_whole(): void
    {
        $summary = app(ContainerApplicationRuntimeService::class)
            ->summarizePythonContainerLogs("Traceback (most recent call last):\nModuleNotFoundError: No module named 'app'\n");

        $this->assertStringStartsWith('Traceback', $summary);
        $this->assertStringContainsString("No module named 'app'", $summary);
    }

    #[Test]
    public function pip_noise_never_crowds_out_the_cause(): void
    {
        $log = '';
        for ($i = 0; $i < 300; $i++) {
            $log .= "Requirement already satisfied: package{$i} in /usr/local/lib/python3.11/site-packages\n";
        }
        $log .= "ModuleNotFoundError: No module named 'app.core'\n";

        $summary = app(ContainerApplicationRuntimeService::class)->summarizePythonContainerLogs($log, 500);

        $this->assertStringContainsString("No module named 'app.core'", $summary);
        $this->assertStringNotContainsString('Requirement already satisfied', $summary);
    }

    #[Test]
    public function the_summary_keeps_the_setting_names_and_drops_pydantics_link_farm(): void
    {
        // pydantic prints a documentation URL under every validation error. The
        // URL contains the word "errors", so the priority pass scored it as a
        // cause, and four of them filled the budget while the lines naming the
        // fields were dropped. The customer was told there were four problems
        // and never which settings they were.
        $log = <<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 4 validation errors for Settings
        ENABLE_SMS
          Input should be a valid boolean, unable to interpret input [type=bool_parsing, input_value='', input_type=str]
            For further information visit https://errors.pydantic.dev/2.13/v/bool_parsing
        RIDER_LOCATION_MAX_AGE_SECONDS
          Input should be a valid integer, unable to parse string as an integer [type=int_parsing, input_value='', input_type=str]
            For further information visit https://errors.pydantic.dev/2.13/v/int_parsing
        LOG;

        $summary = app(ContainerApplicationRuntimeService::class)->summarizePythonContainerLogs($log, 3000);

        $this->assertStringContainsString('ENABLE_SMS', $summary);
        $this->assertStringContainsString('RIDER_LOCATION_MAX_AGE_SECONDS', $summary);
        $this->assertStringContainsString('valid boolean', $summary);
        $this->assertStringNotContainsString('errors.pydantic.dev', $summary);
    }
}

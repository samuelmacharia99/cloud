<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PythonRuntimeErrorPresenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A model-level pydantic validator has no field to blame, so every reader that
 * looked for a field name followed by a message found nothing.
 *
 * The customer got fifty frames of uvicorn and click instead of the one
 * sentence their own code had written for exactly this moment: "Production
 * config must use the M-Pesa production environment".
 */
class PythonOwnConfigRuleTest extends TestCase
{
    private PythonRuntimeErrorPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new PythonRuntimeErrorPresenter;
    }

    #[Test]
    public function it_surfaces_the_rule_the_application_wrote_for_itself(): void
    {
        $result = $this->presenter->present(<<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings
          Value error, Production config must use the M-Pesa production environment [type=value_error, input_value={'ENABLE_API_DOCS': 'false'}, input_type=dict]
            For further information visit https://errors.pydantic.dev/2.13/v/value_error
        LOG);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Production config must use the M-Pesa production environment', $result['message']);
        $this->assertStringContainsString('written in your code', $result['message']);
    }

    #[Test]
    public function it_never_prints_the_settings_back_into_the_message(): void
    {
        // pydantic echoes input_value, which is the customer's configuration.
        $result = $this->presenter->present(
            'pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings'."\n"
            ."  Value error, Bad combination [type=value_error, input_value={'MPESA_KEY': 'abc123secret'}, input_type=dict]"
        );

        $this->assertStringNotContainsString('abc123secret', $result['message']);
        $this->assertStringNotContainsString('input_value', $result['message']);
    }

    #[Test]
    public function a_named_field_still_wins_over_a_rule_about_several(): void
    {
        // A field the customer can point at is more actionable than a sentence
        // about the combination, and it is the one that parks the site.
        $result = $this->presenter->present(<<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 2 validation errors for Settings
        NES_API_KEY
          Field required [type=missing, input_value={}, input_type=dict]
          Value error, Production config must use the M-Pesa production environment [type=value_error]
        LOG);

        $this->assertSame(['NES_API_KEY'], $result['missing_variables']);
    }

    #[Test]
    public function several_rules_are_all_reported(): void
    {
        $result = $this->presenter->present(<<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 2 validation errors for Settings
          Value error, Production config must use the M-Pesa production environment [type=value_error]
          Value error, A callback URL is required in production [type=value_error]
        LOG);

        $this->assertStringContainsString('M-Pesa production environment', $result['message']);
        $this->assertStringContainsString('callback URL is required', $result['message']);
    }

    #[Test]
    public function a_log_with_no_validation_error_is_still_unrecognised(): void
    {
        // The phrase pydantic prints above the rule is the guard. Without it,
        // any line beginning "Value error," in any library's output would be
        // reported to a customer as their own configuration rule.
        $this->assertNull($this->presenter->present("Value error, something\nfrom some other library\n"));
    }
}

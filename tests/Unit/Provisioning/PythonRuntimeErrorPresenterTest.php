<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\PythonRuntimeErrorPresenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PythonRuntimeErrorPresenterTest extends TestCase
{
    private PythonRuntimeErrorPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presenter = new PythonRuntimeErrorPresenter;
    }

    #[Test]
    public function it_names_every_environment_variable_a_settings_model_requires(): void
    {
        $result = $this->presenter->present($this->pydanticSettingsCrash());

        $this->assertNotNull($result);
        $this->assertSame(
            ['SECRET_KEY', 'SYNC_DATABASE_URL', 'AT_USERNAME', 'AT_API_KEY'],
            $result['missing_variables'],
        );
        $this->assertStringContainsString('SECRET_KEY, SYNC_DATABASE_URL, AT_USERNAME, AT_API_KEY', $result['message']);
        $this->assertStringContainsString('Environment', $result['message']);
    }

    #[Test]
    public function it_reports_each_missing_variable_once(): void
    {
        $output = <<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 2 validation errors for Settings
        SECRET_KEY
          Field required [type=missing, input_value={}, input_type=dict]
        SECRET_KEY
          Field required [type=missing, input_value={}, input_type=dict]
        LOG;

        $result = $this->presenter->present($output);

        $this->assertNotNull($result);
        $this->assertSame(['SECRET_KEY'], $result['missing_variables']);
    }

    #[Test]
    public function it_ignores_a_bare_line_that_is_not_a_missing_field(): void
    {
        $output = <<<'LOG'
        Settings
        pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings
        DATABASE_URL
          Field required [type=missing, input_value={}, input_type=dict]
        LOG;

        $result = $this->presenter->present($output);

        $this->assertNotNull($result);
        $this->assertSame(['DATABASE_URL'], $result['missing_variables']);
    }

    #[Test]
    public function it_explains_a_module_python_cannot_import(): void
    {
        $output = <<<'LOG'
          File "/app/apps/backend/main.py", line 13, in <module>
            from app.api.v1.endpoints.admin import router as admin_router
        ModuleNotFoundError: No module named 'shared'
        LOG;

        $result = $this->presenter->present($output);

        $this->assertNotNull($result);
        $this->assertSame([], $result['missing_variables']);
        $this->assertStringContainsString('shared', $result['message']);
        $this->assertStringContainsString('requirements.txt', $result['message']);
    }

    #[Test]
    public function it_explains_an_entrypoint_uvicorn_cannot_load(): void
    {
        $result = $this->presenter->present('ERROR:    Error loading ASGI app. Could not import module "main".');

        $this->assertNotNull($result);
        $this->assertStringContainsString('main', $result['message']);
    }

    #[Test]
    public function it_prefers_the_missing_settings_cause_over_a_nested_import_error(): void
    {
        $output = $this->pydanticSettingsCrash()."\nModuleNotFoundError: No module named 'app'";

        $result = $this->presenter->present($output);

        $this->assertNotNull($result);
        $this->assertNotSame([], $result['missing_variables']);
    }

    #[Test]
    public function it_returns_null_when_nothing_python_specific_is_recognisable(): void
    {
        $this->assertNull($this->presenter->present(''));
        $this->assertNull($this->presenter->present('   '));
        $this->assertNull($this->presenter->present(
            'nginx: [notice] 1#1: using the "epoll" event method'
        ));
    }

    /**
     * Trimmed from the real crash loop on service 457, pip transcript included.
     */
    private function pydanticSettingsCrash(): string
    {
        return <<<'LOG'
        Collecting fastapi==0.115.0
          Downloading fastapi-0.115.0-py3-none-any.whl (94 kB)
        Installing collected packages: fastapi
        Traceback (most recent call last):
          File "<frozen importlib._bootstrap_external>", line 940, in exec_module
          File "/app/apps/backend/main.py", line 13, in <module>
            from app.api.v1.endpoints.admin import router as admin_router
          File "/app/apps/backend/app/core/config.py", line 85, in get_settings
            return Settings()
        pydantic_core._pydantic_core.ValidationError: 4 validation errors for Settings
        SECRET_KEY
          Field required [type=missing, input_value={'DATABASE_URL': 'postgre...python-db:5432/s457_db'}, input_type=dict]
            For further information visit https://errors.pydantic.dev/2.13/v/missing
        SYNC_DATABASE_URL
          Field required [type=missing, input_value={'DATABASE_URL': 'postgre...python-db:5432/s457_db'}, input_type=dict]
            For further information visit https://errors.pydantic.dev/2.13/v/missing
        AT_USERNAME
          Field required [type=missing, input_value={'DATABASE_URL': 'postgre...python-db:5432/s457_db'}, input_type=dict]
            For further information visit https://errors.pydantic.dev/2.13/v/missing
        AT_API_KEY
          Field required [type=missing, input_value={'DATABASE_URL': 'postgre...python-db:5432/s457_db'}, input_type=dict]
            For further information visit https://errors.pydantic.dev/2.13/v/missing
        LOG;
    }
}

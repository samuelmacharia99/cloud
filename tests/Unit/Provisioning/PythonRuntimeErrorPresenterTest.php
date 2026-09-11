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

    #[Test]
    public function it_reads_an_application_that_lists_its_own_missing_configuration(): void
    {
        $result = $this->presenter()->present(<<<'LOG'
        Traceback (most recent call last):
          File "/app/apps/backend/app/core/config.py", line 106, in get_settings
          File "/usr/local/lib/python3.11/site-packages/pydantic/main.py", line 263, in __init__
        pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings
          Value error, Missing required production config: MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_SHORTCODE, MPESA_PASSKEY, MPESA_CALLBACK_URL, NES_API_KEY [type=value_error, input_value={'SECRET_KEY': 'tlSVdtmEd...python-db:5432/s457_db'}, input_type=dict]
        LOG);

        $this->assertNotNull($result);
        $this->assertSame([
            'MPESA_CONSUMER_KEY',
            'MPESA_CONSUMER_SECRET',
            'MPESA_SHORTCODE',
            'MPESA_PASSKEY',
            'MPESA_CALLBACK_URL',
            'NES_API_KEY',
        ], $result['missing_variables']);
    }

    #[Test]
    public function it_never_reports_a_value_the_customer_already_supplied(): void
    {
        // pydantic echoes the settings it DID receive in input_value. Reading
        // past "[type=" would name SECRET_KEY as missing when it is present.
        $result = $this->presenter()->present(
            'ValidationError: 1 validation error for Settings'."\n"
            .'  Value error, Missing required production config: NES_API_KEY '
            .'[type=value_error, input_value={\'SECRET_KEY\': \'abc\', \'DATABASE_URL\': \'postgres://x\'}, input_type=dict]'
        );

        $this->assertSame(['NES_API_KEY'], $result['missing_variables']);
    }

    #[Test]
    public function it_reads_django_asking_for_an_environment_variable(): void
    {
        $result = $this->presenter()->present(
            "django.core.exceptions.ImproperlyConfigured: Set the SECRET_KEY environment variable\n"
        );

        $this->assertNotNull($result);
        $this->assertSame(['SECRET_KEY'], $result['missing_variables']);
    }

    #[Test]
    public function it_reads_a_bare_environment_lookup_that_raised(): void
    {
        $result = $this->presenter()->present(<<<'LOG'
        Traceback (most recent call last):
          File "/app/settings.py", line 12, in <module>
            STRIPE_KEY = os.environ['STRIPE_SECRET_KEY']
        KeyError: 'STRIPE_SECRET_KEY'
        LOG);

        $this->assertNotNull($result);
        $this->assertSame(['STRIPE_SECRET_KEY'], $result['missing_variables']);
    }

    #[Test]
    public function a_key_error_on_an_ordinary_dictionary_is_left_alone(): void
    {
        // An application bug, not a missing setting. Naming it would send the
        // customer looking for a variable that was never meant to exist.
        $result = $this->presenter()->present(<<<'LOG'
        Traceback (most recent call last):
          File "/app/apps/backend/app/services/pricing.py", line 40, in rate
            return table['PREMIUM_TIER']
        KeyError: 'PREMIUM_TIER'
        LOG);

        $this->assertNull($result);
    }

    private function presenter(): PythonRuntimeErrorPresenter
    {
        return app(PythonRuntimeErrorPresenter::class);
    }

    #[Test]
    public function it_names_a_setting_that_is_present_and_unreadable(): void
    {
        // pydantic raises a different exception for a value it cannot parse
        // than for a value that is absent, and the traceback is fifteen frames
        // of importlib with the cause on the last line. Nothing in it says the
        // word "environment", so the customer sees a library crash rather than
        // their own value.
        $result = $this->presenter->present(<<<'LOG'
          File "/app/apps/backend/app/core/config.py", line 106, in get_settings
          File "/usr/local/lib/python3.11/site-packages/pydantic_settings/sources/base.py", line 610, in __call__
            raise SettingsError(
        pydantic_settings.exceptions.SettingsError: error parsing value for field "ALLOWED_ORIGINS" from source "EnvSettingsSource"
        LOG);

        $this->assertNotNull($result);
        $this->assertStringContainsString('ALLOWED_ORIGINS', $result['message']);
        $this->assertStringContainsString('JSON', $result['message']);
    }

    #[Test]
    public function an_unreadable_setting_is_never_reported_as_a_missing_one(): void
    {
        // The hold lists what is unset, and this name is set. Wrongly, but set.
        // Parking the stack on it would show a setup notice listing nothing.
        $result = $this->presenter->present(
            'pydantic_settings.exceptions.SettingsError: error parsing value for field "ALLOWED_ORIGINS" from source "EnvSettingsSource"'
        );

        $this->assertSame([], $result['missing_variables']);
    }

    #[Test]
    public function an_absent_setting_still_wins_over_an_unreadable_one(): void
    {
        // Both can appear in one boot. A name nobody has set is the one the
        // customer has to act on first, and it is the one that parks the stack.
        $result = $this->presenter->present(<<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings
        NES_API_KEY
          Field required [type=missing, input_value={}, input_type=dict]
        pydantic_settings.exceptions.SettingsError: error parsing value for field "ALLOWED_ORIGINS" from source "EnvSettingsSource"
        LOG);

        $this->assertSame(['NES_API_KEY'], $result['missing_variables']);
    }

    #[Test]
    public function it_says_a_setting_is_empty_rather_than_counting_four_problems(): void
    {
        // The shape a customer cannot see from the outside: an absent setting
        // and one present with an empty string look identical in a settings
        // list, and pydantic treats them completely differently.
        $result = $this->presenter->present(<<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 4 validation errors for Settings
        ENABLE_SMS
          Input should be a valid boolean, unable to interpret input [type=bool_parsing, input_value='', input_type=str]
            For further information visit https://errors.pydantic.dev/2.13/v/bool_parsing
        RIDER_LOCATION_MAX_AGE_SECONDS
          Input should be a valid integer, unable to parse string as an integer [type=int_parsing, input_value='', input_type=str]
            For further information visit https://errors.pydantic.dev/2.13/v/int_parsing
        LOG);

        $this->assertNotNull($result);
        $this->assertStringContainsString('present but empty', $result['message']);
        $this->assertStringContainsString('ENABLE_SMS', $result['message']);
        $this->assertStringContainsString('RIDER_LOCATION_MAX_AGE_SECONDS', $result['message']);

        // Reported as missing, because to the customer they are: an empty box
        // and no box at all are the same request. Reporting them parks the site
        // on a notice naming them, which beats a crash loop the visitor reads
        // as a broken site.
        $this->assertSame(['ENABLE_SMS', 'RIDER_LOCATION_MAX_AGE_SECONDS'], $result['missing_variables']);
    }

    #[Test]
    public function a_value_of_the_wrong_kind_is_not_reported_as_an_empty_one(): void
    {
        $result = $this->presenter->present(<<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 1 validation error for Settings
        RIDER_LOCATION_MAX_AGE_SECONDS
          Input should be a valid integer, unable to parse string as an integer [type=int_parsing, input_value='two minutes', input_type=str]
        LOG);

        $this->assertStringContainsString('wrong kind', $result['message']);
        $this->assertStringNotContainsString('present but empty', $result['message']);
        $this->assertStringContainsString('RIDER_LOCATION_MAX_AGE_SECONDS', $result['message']);
    }

    #[Test]
    public function an_absent_setting_still_outranks_an_empty_one(): void
    {
        // A name nobody has set parks the stack; a name set to nothing fails
        // the pull. When both appear, the one that parks it wins.
        $result = $this->presenter->present(<<<'LOG'
        pydantic_core._pydantic_core.ValidationError: 2 validation errors for Settings
        NES_API_KEY
          Field required [type=missing, input_value={}, input_type=dict]
        ENABLE_SMS
          Input should be a valid boolean, unable to interpret input [type=bool_parsing, input_value='', input_type=str]
        LOG);

        $this->assertSame(['NES_API_KEY'], $result['missing_variables']);
    }

    #[Test]
    public function it_names_the_async_driver_crash_as_the_platform_s_own_to_fix(): void
    {
        $result = $this->presenter->present(<<<'LOG'
          File "/app/apps/backend/app/db/session.py", line 22, in <module>
          File "/usr/local/lib/python3.11/site-packages/sqlalchemy/ext/asyncio/engine.py", line 121, in create_async_engine
            raise exc.InvalidRequestError(
        sqlalchemy.exc.InvalidRequestError: The asyncio extension requires an async driver to be used. The loaded 'psycopg2' is not async.
        LOG);

        $this->assertNotNull($result);
        $this->assertStringContainsString('psycopg2', $result['message']);
        $this->assertStringContainsString('asyncpg', $result['message']);
        // DATABASE_URL is the platform's to write, so nothing here may be
        // reported as a setting the customer has to type. Doing so parks the
        // site on a notice telling somebody to fix a row they cannot edit.
        $this->assertSame([], $result['missing_variables']);
        $this->assertSame([], $result['unparsable_variables']);
    }

    #[Test]
    public function it_does_not_read_an_unrelated_import_error_as_the_async_driver_crash(): void
    {
        $result = $this->presenter->present(<<<'LOG'
        ModuleNotFoundError: No module named 'asyncpg'
        LOG);

        $this->assertNotNull($result);
        $this->assertStringNotContainsString('asynchronous database connection', $result['message']);
    }
}

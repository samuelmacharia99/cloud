<?php

namespace Tests\Unit\Provisioning;

use App\Models\Service;
use App\Services\Provisioning\ContainerTemplateEnvironmentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerPythonEnvironmentTest extends TestCase
{
    private ContainerTemplateEnvironmentService $environment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->environment = new ContainerTemplateEnvironmentService;
    }

    #[Test]
    public function it_generates_a_signing_key_python_apps_require_at_import_time(): void
    {
        $env = $this->prepare([]);

        $this->assertArrayHasKey('SECRET_KEY', $env);
        $this->assertSame(50, strlen($env['SECRET_KEY']));
    }

    #[Test]
    public function it_keeps_the_signing_key_a_previous_deploy_generated(): void
    {
        $env = $this->prepare(['SECRET_KEY' => 'already-issued-key']);

        $this->assertSame('already-issued-key', $env['SECRET_KEY']);
    }

    #[Test]
    public function it_derives_a_synchronous_database_url_from_the_platform_url(): void
    {
        $env = $this->prepare([
            'DATABASE_URL' => 'postgresql://appuser:secret@user-1-service-2-python-db:5432/s2_db',
        ]);

        $this->assertSame(
            'postgresql://appuser:secret@user-1-service-2-python-db:5432/s2_db',
            $env['SYNC_DATABASE_URL'],
        );
    }

    #[Test]
    public function it_strips_an_async_driver_when_deriving_the_synchronous_url(): void
    {
        $env = $this->prepare([
            'DATABASE_URL' => 'postgresql+asyncpg://appuser:secret@db:5432/appdb',
        ]);

        $this->assertSame('postgresql://appuser:secret@db:5432/appdb', $env['SYNC_DATABASE_URL']);
    }

    #[Test]
    public function it_never_overwrites_a_synchronous_url_the_customer_set(): void
    {
        $env = $this->prepare([
            'DATABASE_URL' => 'postgresql+asyncpg://appuser:secret@db:5432/appdb',
            'SYNC_DATABASE_URL' => 'postgresql+psycopg://appuser:secret@db:5432/appdb',
        ]);

        $this->assertSame('postgresql+psycopg://appuser:secret@db:5432/appdb', $env['SYNC_DATABASE_URL']);
    }

    #[Test]
    public function it_leaves_the_synchronous_url_unset_when_no_database_is_attached(): void
    {
        $env = $this->prepare([]);

        $this->assertArrayNotHasKey('SYNC_DATABASE_URL', $env);
    }

    #[Test]
    public function it_defaults_unbuffered_output_without_replacing_a_chosen_value(): void
    {
        $this->assertSame('1', $this->prepare([])['PYTHONUNBUFFERED']);
        $this->assertSame('0', $this->prepare(['PYTHONUNBUFFERED' => '0'])['PYTHONUNBUFFERED']);
    }

    #[Test]
    public function it_leaves_other_stacks_untouched(): void
    {
        $env = $this->environment->prepare(
            $this->template('nodejs'),
            ['DATABASE_URL' => 'postgresql+asyncpg://appuser:secret@db:5432/appdb'],
            new Service,
            3000,
        );

        $this->assertArrayNotHasKey('SECRET_KEY', $env);
        $this->assertArrayNotHasKey('SYNC_DATABASE_URL', $env);
    }

    #[Test]
    public function it_keeps_customer_variables_a_stack_template_does_not_declare(): void
    {
        $values = $this->environment->normalizeCustomerValues([
            'AT_API_KEY' => 'live-key',
            'MAX_WORKERS' => 4,
            'DEBUG_MODE' => true,
        ]);

        $this->assertSame(
            ['AT_API_KEY' => 'live-key', 'MAX_WORKERS' => '4', 'DEBUG_MODE' => '1'],
            $values,
        );
    }

    #[Test]
    public function it_drops_values_that_cannot_reach_a_container_environment(): void
    {
        $values = $this->environment->normalizeCustomerValues([
            'VALID_KEY' => 'kept',
            '9_LEADING_DIGIT' => 'dropped',
            'has-dash' => 'dropped',
            '' => 'dropped',
            'NESTED' => ['dropped'],
            'NULLED' => null,
        ]);

        $this->assertSame(['VALID_KEY' => 'kept'], $values);
    }

    /**
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function prepare(array $env): array
    {
        return $this->environment->prepare($this->template('python'), $env, new Service, 8000);
    }

    private function template(string $slug): object
    {
        return new class($slug)
        {
            /** @var array<int, array<string, mixed>> */
            public array $environment_variables = [];

            public function __construct(public string $slug) {}
        };
    }
}

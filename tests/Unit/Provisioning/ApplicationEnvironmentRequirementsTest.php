<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ApplicationEnvironmentRequirements;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ApplicationEnvironmentRequirementsTest extends TestCase
{
    private ApplicationEnvironmentRequirements $requirements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requirements = new ApplicationEnvironmentRequirements;
    }

    #[Test]
    public function it_reads_the_keys_an_example_file_declares(): void
    {
        $example = <<<'ENV'
        # Application
        SECRET_KEY=changeme
        AT_USERNAME=
        export AT_API_KEY=your-key-here

        # commented out on purpose
        # OPTIONAL_FLAG=1
        not a variable line
        ENV;

        $this->assertSame(
            ['SECRET_KEY', 'AT_USERNAME', 'AT_API_KEY'],
            $this->requirements->parse($example),
        );
    }

    #[Test]
    public function it_ignores_keys_the_platform_already_supplies(): void
    {
        $missing = $this->requirements->unsatisfied(
            ['DATABASE_URL', 'DB_HOST', 'POSTGRES_USER', 'SECRET_KEY', 'INTERNAL_API_URL', 'AT_API_KEY'],
            [],
        );

        $this->assertSame(['AT_API_KEY'], $missing);
    }

    #[Test]
    public function it_ignores_keys_that_already_have_a_value(): void
    {
        $missing = $this->requirements->unsatisfied(
            ['AT_USERNAME', 'AT_API_KEY'],
            ['AT_USERNAME' => 'zumi'],
        );

        $this->assertSame(['AT_API_KEY'], $missing);
    }

    #[Test]
    public function it_treats_a_blank_value_as_still_missing(): void
    {
        $missing = $this->requirements->unsatisfied(['AT_API_KEY'], ['AT_API_KEY' => '   ']);

        $this->assertSame(['AT_API_KEY'], $missing);
    }

    #[Test]
    public function it_rejects_names_that_are_not_environment_keys(): void
    {
        $missing = $this->requirements->unsatisfied(['9_BAD', 'has-dash', '', 'GOOD_KEY'], []);

        $this->assertSame(['GOOD_KEY'], $missing);
    }

    #[Test]
    public function it_reports_each_key_once_however_many_files_declare_it(): void
    {
        $missing = $this->requirements->unsatisfied(['AT_API_KEY', 'AT_API_KEY'], []);

        $this->assertSame(['AT_API_KEY'], $missing);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\User;
use App\Services\DomainAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class DomainAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private DomainAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DomainAvailabilityService::class);
    }

    public function test_parses_multi_part_tld_from_search_input(): void
    {
        DomainExtension::create([
            'extension' => '.co.ke',
            'description' => 'Kenya',
            'enabled' => true,
        ]);

        $result = $this->service->checkInput('travel.co.ke');

        $this->assertNotNull($result);
        $this->assertSame('travel', $result['name']);
        $this->assertSame('.co.ke', $result['extension']);
        $this->assertSame('travel.co.ke', $result['full_domain']);
    }

    public function test_marks_domain_as_taken_when_registered_locally(): void
    {
        DomainExtension::create([
            'extension' => '.com',
            'description' => 'COM',
            'enabled' => true,
        ]);

        $user = User::factory()->create();

        Domain::create([
            'user_id' => $user->id,
            'name' => 'mysite',
            'extension' => '.com',
            'status' => 'active',
            'type' => 'registration',
        ]);

        $result = $this->service->checkInput('mysite.com');

        $this->assertNotNull($result);
        $this->assertFalse($result['available']);
        $this->assertSame('local', $result['source']);
    }

    public function test_interprets_verisign_available_response(): void
    {
        $reflection = new ReflectionClass(DomainAvailabilityService::class);
        $method = $reflection->getMethod('interpretWhoisResponse');
        $method->setAccessible(true);

        $available = $method->invoke($this->service, 'No match for "EXAMPLE-AVAILABLE-123.COM".');
        $taken = $method->invoke($this->service, "Domain Name: EXAMPLE.COM\nCreation Date: 2020-01-01T00:00:00Z\nRegistrar: Example Registrar");

        $this->assertTrue($available);
        $this->assertFalse($taken);
    }
}

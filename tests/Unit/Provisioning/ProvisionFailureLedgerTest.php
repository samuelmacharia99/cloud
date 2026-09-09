<?php

namespace Tests\Unit\Provisioning;

use App\Enums\ProvisionFailureClass;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Provisioning\ProvisionFailureLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionFailureLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_expo_frontend_as_config(): void
    {
        $class = app(ProvisionFailureLedger::class)->classify(new \DomainException(
            "The frontend at 'apps/mobile' is Expo/React Native, not a browser application."
        ));

        $this->assertSame(ProvisionFailureClass::Config, $class);
    }

    public function test_classifies_port_collision_as_transient(): void
    {
        $class = app(ProvisionFailureLedger::class)->classify(new \RuntimeException(
            'Bind for 0.0.0.0:30001 failed: port is already allocated'
        ));

        $this->assertSame(ProvisionFailureClass::Transient, $class);
    }

    public function test_holds_failed_config_errors_until_an_operator_retries(): void
    {
        $service = Service::factory()->create(['status' => 'failed']);
        $ledger = app(ProvisionFailureLedger::class);
        $ledger->record($service, new \DomainException(
            "The frontend at 'apps/mobile' is Expo/React Native, not a browser application."
        ));

        $service->refresh();
        $this->assertFalse($ledger->shouldAutoRetry($service));
        $this->assertTrue($ledger->shouldNotifyCustomer($service));
        $this->assertTrue($ledger->shouldAlertOperators($service));

        $ledger->markCustomerNotified($service);
        $ledger->markOperatorAlerted($service);
        $service->refresh();

        $this->assertFalse($ledger->shouldNotifyCustomer($service));
        $this->assertFalse($ledger->shouldAlertOperators($service));

        $ledger->record($service->fresh(), new \DomainException(
            "The frontend at 'apps/mobile' is Expo/React Native, not a browser application."
        ));
        $service->refresh();

        $this->assertFalse($ledger->shouldNotifyCustomer($service));
        $this->assertSame(2, $service->service_meta['provision_failure']['attempts']);
    }

    public function test_retries_legacy_failed_services_without_a_ledger_row(): void
    {
        $service = Service::factory()->create(['status' => 'failed']);

        $this->assertTrue(app(ProvisionFailureLedger::class)->shouldAutoRetry($service));
    }

    public function test_pending_services_always_retry(): void
    {
        $service = Service::factory()->create(['status' => 'pending']);
        $ledger = app(ProvisionFailureLedger::class);
        $ledger->record($service, new \DomainException('Workload roots must be safe relative repository directories.'));
        $service->update(['status' => ServiceStatus::Pending]);

        $this->assertTrue($ledger->shouldAutoRetry($service->fresh()));
    }

    public function test_clear_drops_the_failure_so_retry_can_run(): void
    {
        $service = Service::factory()->create(['status' => 'failed']);
        $ledger = app(ProvisionFailureLedger::class);
        $ledger->record($service, new \DomainException(
            "The frontend at 'apps/mobile' is Expo/React Native, not a browser application."
        ));
        $ledger->clear($service);

        $service->refresh();
        $this->assertArrayNotHasKey('provision_failure', $service->service_meta ?? []);
        $this->assertTrue($ledger->shouldAutoRetry($service));
    }

    public function test_transient_failures_wait_before_the_next_cron_attempt(): void
    {
        $service = Service::factory()->create(['status' => 'failed']);
        $ledger = app(ProvisionFailureLedger::class);
        $ledger->record($service, new \RuntimeException('Bind for 0.0.0.0:30001 failed: port is already allocated'));

        $service->refresh();
        $this->assertFalse($ledger->shouldAutoRetry($service));
        $this->assertNotEmpty($service->service_meta['provision_failure']['retry_after']);
        $this->assertTrue($service->service_meta['provision_failure']['auto_retry']);
    }
}

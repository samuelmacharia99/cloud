<?php

namespace Tests\Unit\Services\Dns;

use App\Mail\DomainDnsLiveMail;
use App\Models\CustomerNotification;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\CloudflareZoneStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CloudflareZoneStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $zoneStatus = 'pending';

    private string $zoneName = 'example.com';

    /** @var array{body: array<string, mixed>, status: int}|null */
    private ?array $zoneFailure = null;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('cloudflare_enabled', 'true');
        Setting::setValue('cloudflare_api_token', 'test-token-abcdefghijklmnopqrstuvwxyz');
        Setting::setValue('cloudflare_account_id', 'acct123');
        Setting::setValue('notify_domain_dns_live', 'true');
        // Platform SMTP, which is what an admin-owned customer is mailed through.
        Setting::setValue('smtp_host', 'smtp.talksasa.test');

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-1' => function () {
                if ($this->zoneFailure !== null) {
                    return Http::response($this->zoneFailure['body'], $this->zoneFailure['status']);
                }

                return Http::response([
                    'success' => true,
                    'result' => [
                        'id' => 'zone-1',
                        'name' => $this->zoneName,
                        'status' => $this->zoneStatus,
                        'name_servers' => ['a.ns.cloudflare.com'],
                    ],
                ]);
            },
            '*' => Http::response(['success' => true, 'result' => []]),
        ]);
    }

    private function domain(?User $owner = null): Domain
    {
        return Domain::create([
            'user_id' => ($owner ?? User::factory()->customer()->create())->id,
            'name' => 'example',
            'extension' => '.com',
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => 'zone-1',
        ]);
    }

    private function fakeZone(string $status, string $name = 'example.com'): void
    {
        $this->zoneStatus = $status;
        $this->zoneName = $name;
        $this->zoneFailure = null;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function failZone(array $body, int $status): void
    {
        $this->zoneFailure = ['body' => $body, 'status' => $status];
    }

    public function test_a_zone_cloudflare_has_not_activated_reads_as_waiting(): void
    {
        Mail::fake();
        $this->fakeZone('pending');

        $state = app(CloudflareZoneStateService::class)->refresh($this->domain());

        $this->assertSame(CloudflareZoneStateService::PENDING, $state['state']);
        $this->assertFalse($state['needs_resync']);
        Mail::assertNothingSent();
        $this->assertSame(0, CustomerNotification::count(), 'nothing to announce until it is actually live');
    }

    public function test_a_zone_going_live_is_announced_once(): void
    {
        Mail::fake();
        $this->fakeZone('active');
        $domain = $this->domain();
        $service = app(CloudflareZoneStateService::class);

        $state = $service->refresh($domain);

        $this->assertSame(CloudflareZoneStateService::LIVE, $state['state']);
        $this->assertNotNull($state['activated_at']);
        $this->assertSame(1, CustomerNotification::count());
        Mail::assertSent(DomainDnsLiveMail::class, 1);

        // Every later sweep sees the same live zone; the owner hears about it once.
        $service->refresh($domain->fresh());
        $service->refresh($domain->fresh());

        $this->assertSame(1, CustomerNotification::count());
        Mail::assertSent(DomainDnsLiveMail::class, 1);
    }

    public function test_a_deleted_zone_reads_as_no_longer_on_cloudflare(): void
    {
        $this->failZone(['success' => false, 'errors' => [['code' => 1049, 'message' => 'Invalid zone identifier']]], 404);

        $state = app(CloudflareZoneStateService::class)->refresh($this->domain());

        $this->assertSame(CloudflareZoneStateService::MISSING, $state['state']);
        $this->assertTrue($state['needs_resync'], 're-sync is the way back');
    }

    public function test_cloudflare_being_unreachable_does_not_claim_the_domain_is_gone(): void
    {
        $domain = $this->domain();
        $service = app(CloudflareZoneStateService::class);

        $this->fakeZone('active');
        $service->refresh($domain);
        $this->assertSame(CloudflareZoneStateService::LIVE, $service->current($domain->fresh())['state']);

        $this->failZone(['success' => false, 'errors' => []], 500);

        $state = $service->refresh($domain->fresh());

        $this->assertSame(CloudflareZoneStateService::UNKNOWN, $state['state']);
        $this->assertFalse($state['needs_resync'], 'an outage is not evidence the zone has gone');
        $this->assertSame(
            CloudflareZoneStateService::LIVE,
            DnsZone::where('domain_id', $domain->id)->value('provider_status'),
            'the last known state stands'
        );
    }

    public function test_a_zone_under_another_name_reads_as_drifted(): void
    {
        $this->fakeZone('active', 'old-spelling.com');

        $state = app(CloudflareZoneStateService::class)->refresh($this->domain());

        $this->assertSame(CloudflareZoneStateService::DRIFTED, $state['state']);
        $this->assertTrue($state['needs_resync']);
    }

    public function test_the_sweep_only_looks_at_zones_that_are_not_live_yet(): void
    {
        Mail::fake();
        $service = app(CloudflareZoneStateService::class);

        $pending = $this->domain();
        $this->fakeZone('pending');
        $service->refresh($pending);

        $this->assertTrue(
            $service->zonesAwaitingActivation()->pluck('id')->contains($pending->id),
            'a pending zone is worth asking about'
        );

        $this->fakeZone('active');
        $service->refresh($pending->fresh());

        $this->assertFalse(
            $service->zonesAwaitingActivation()->pluck('id')->contains($pending->id),
            'once live it drops out, so the work shrinks rather than grows'
        );
    }

    public function test_a_reseller_customer_is_mailed_through_their_resellers_smtp(): void
    {
        Mail::fake();
        $this->fakeZone('active');

        $reseller = User::factory()->reseller()->create([
            'settings' => ['smtp' => [
                'enabled' => true,
                'host' => 'smtp.acme.test',
                'from_address' => 'support@acme.test',
            ]],
        ]);
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $state = app(CloudflareZoneStateService::class)->refresh($this->domain($customer));

        $this->assertSame(CloudflareZoneStateService::LIVE, $state['state']);
        $this->assertSame(1, CustomerNotification::where('user_id', $customer->id)->count());
        Mail::assertSent(DomainDnsLiveMail::class, 1);
    }

    public function test_a_reseller_customer_is_never_mailed_on_platform_smtp(): void
    {
        Mail::fake();
        $this->fakeZone('active');

        // Reseller present but no SMTP of their own. The platform must not step
        // over them and send it itself, and one reseller's missing configuration
        // must not break the sweep for everybody else.
        $reseller = User::factory()->reseller()->create();
        $customer = User::factory()->customer()->create(['reseller_id' => $reseller->id]);

        $state = app(CloudflareZoneStateService::class)->refresh($this->domain($customer));

        $this->assertSame(CloudflareZoneStateService::LIVE, $state['state']);
        Mail::assertNothingSent();
        $this->assertSame(
            1,
            CustomerNotification::where('user_id', $customer->id)->count(),
            'the in-app notice still lands, so the owner is told either way'
        );
    }
}

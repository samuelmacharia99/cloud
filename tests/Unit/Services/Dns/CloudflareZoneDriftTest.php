<?php

namespace Tests\Unit\Services\Dns;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\Services\Dns\CloudflareDnsService;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Renaming a domain leaves its row pointing at the zone created under the old
 * name. Cloudflare then reads every record name we send as relative to that old
 * zone and appends it, so a mail record for the new name lands as
 * "_dmarc.new.co.ke.old.co.ke" and stops meaning anything.
 */
class CloudflareZoneDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValue('cloudflare_enabled', 'true');
        Setting::setValue('cloudflare_api_token', 'test-token-abcdefghijklmnopqrstuvwxyz');
        Setting::setValue('cloudflare_account_id', 'acct123');
    }

    private function domain(string $name, string $extension, string $zoneId): Domain
    {
        return Domain::create([
            'user_id' => User::factory()->customer()->create()->id,
            'name' => $name,
            'extension' => $extension,
            'status' => 'active',
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => $zoneId,
        ]);
    }

    public function test_the_zone_name_is_read_back_from_cloudflare(): void
    {
        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-old' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-old',
                    'name' => 'joharamedicalcentreltd.co.ke',
                    'name_servers' => ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com'],
                ],
            ]),
        ]);

        $zone = app(CloudflareDnsService::class)->getZone('zone-old');

        $this->assertTrue($zone['success']);
        $this->assertSame('joharamedicalcentreltd.co.ke', $zone['zone_name']);
    }

    public function test_a_renamed_domain_is_moved_off_the_zone_named_after_its_old_spelling(): void
    {
        $domain = $this->domain('jowharamedicalcentreltd', '.co.ke', 'zone-old');

        Http::fake([
            // The zone the row points at still carries the old spelling.
            'api.cloudflare.com/client/v4/zones/zone-old' => Http::response([
                'success' => true,
                'result' => ['id' => 'zone-old', 'name' => 'joharamedicalcentreltd.co.ke', 'name_servers' => []],
            ]),
            // Creating the zone for the name the domain actually has now.
            'api.cloudflare.com/client/v4/zones' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-new',
                    'name' => 'jowharamedicalcentreltd.co.ke',
                    'name_servers' => ['ezra.ns.cloudflare.com', 'liberty.ns.cloudflare.com'],
                ],
            ]),
            '*' => Http::response(['success' => true, 'result' => []]),
        ]);

        $result = app(DomainCloudflareDnsService::class)->provisionZone($domain);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('zone-new', $domain->fresh()->cloudflare_zone_id);
    }

    public function test_a_zone_that_still_matches_is_left_alone(): void
    {
        $domain = $this->domain('example', '.com', 'zone-good');

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-good' => Http::response([
                'success' => true,
                'result' => ['id' => 'zone-good', 'name' => 'example.com', 'name_servers' => ['a.ns.cloudflare.com']],
            ]),
            '*' => Http::response(['success' => true, 'result' => []]),
        ]);

        app(DomainCloudflareDnsService::class)->provisionZone($domain);

        $this->assertSame('zone-good', $domain->fresh()->cloudflare_zone_id, 'a matching zone must not be replaced');
    }

    public function test_cloudflare_being_unreachable_does_not_abandon_a_working_zone(): void
    {
        $domain = $this->domain('example', '.com', 'zone-good');

        Http::fake([
            'api.cloudflare.com/client/v4/zones/zone-good' => Http::response(['success' => false, 'errors' => []], 500),
            '*' => Http::response(['success' => true, 'result' => []]),
        ]);

        app(DomainCloudflareDnsService::class)->provisionZone($domain);

        // A failed lookup is not evidence of drift; treating it as such would
        // orphan a live zone and duplicate it on every retry.
        $this->assertSame('zone-good', $domain->fresh()->cloudflare_zone_id);
    }
}

<?php

namespace App\Services\Dns;

use App\Enums\NotificationEvent;
use App\Mail\DomainDnsLiveMail;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Services\EmailDeliveryService;
use App\Services\InAppNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * What Cloudflare actually says about a domain's zone, recorded rather than assumed.
 *
 * The platform used to write "active" for every zone the moment it created one,
 * so a domain waiting on its nameservers and a domain whose zone had since been
 * deleted both read as serving. Cloudflare has its own vocabulary — a zone is
 * initializing, then pending until it sees the nameservers at the registry, then
 * active — and that is the answer worth showing an owner.
 *
 * The distinction this class refuses to blur is "gone" versus "could not ask".
 * A lookup that fails for any reason other than a definite not-found leaves the
 * last known state standing, because telling someone their DNS has disappeared
 * during an API outage is worse than telling them nothing.
 */
class CloudflareZoneStateService
{
    public const LIVE = 'live';

    public const PENDING = 'pending';

    public const MISSING = 'missing';

    public const DRIFTED = 'drifted';

    public const UNKNOWN = 'unknown';

    public const NONE = 'none';

    public function __construct(
        private CloudflareDnsService $cloudflare,
        private EmailDeliveryService $email,
        private InAppNotificationService $inApp,
    ) {}

    /**
     * Ask Cloudflare, record the answer, and announce a zone that has just gone
     * live. Safe to call from a page action or a scheduled sweep.
     *
     * @return array<string, mixed>
     */
    public function refresh(Domain $domain): array
    {
        $zoneId = trim((string) ($domain->cloudflare_zone_id ?? ''));

        if ($zoneId === '' || ! $domain->cloudflare_dns_enabled) {
            return $this->describe(self::NONE, $domain, null);
        }

        $zone = $this->cloudflare->getZone($zoneId);

        if (! ($zone['success'] ?? false)) {
            if ($this->cloudflare->looksLikeMissingZone($zone)) {
                return $this->describe(self::MISSING, $domain, $this->store($domain, self::MISSING));
            }

            // Reachability, not truth. Keep whatever was last known and only
            // record that we tried.
            $row = $this->zoneRow($domain);
            $row?->forceFill(['provider_checked_at' => now()])->save();

            return $this->describe(self::UNKNOWN, $domain, $row);
        }

        $zoneName = strtolower(trim((string) ($zone['zone_name'] ?? '')));
        $fqdn = strtolower(trim($domain->fqdn()));

        if ($zoneName !== '' && $zoneName !== $fqdn) {
            return $this->describe(self::DRIFTED, $domain, $this->store($domain, self::DRIFTED, $zoneName));
        }

        $status = strtolower(trim((string) ($zone['zone_status'] ?? '')));

        if (in_array($status, ['deleted', 'moved'], true)) {
            return $this->describe(self::MISSING, $domain, $this->store($domain, self::MISSING));
        }

        if ($status !== 'active') {
            return $this->describe(self::PENDING, $domain, $this->store($domain, self::PENDING));
        }

        $row = $this->zoneRow($domain);
        $firstTimeLive = $row === null || $row->activated_at === null;

        $row = $this->store($domain, self::LIVE, markActivated: true);

        if ($firstTimeLive) {
            $this->announceLive($domain);
        }

        return $this->describe(self::LIVE, $domain, $row);
    }

    /**
     * The stored answer, with no call to Cloudflare. This is what a page render
     * uses; asking the API per view would put a network round trip in front of
     * every page load.
     *
     * @return array<string, mixed>
     */
    public function current(Domain $domain): array
    {
        $zoneId = trim((string) ($domain->cloudflare_zone_id ?? ''));

        if ($zoneId === '' || ! $domain->cloudflare_dns_enabled) {
            return $this->describe(self::NONE, $domain, null);
        }

        $row = $this->zoneRow($domain);
        $state = (string) ($row?->provider_status ?? '');

        return $this->describe($state !== '' ? $state : self::UNKNOWN, $domain, $row);
    }

    /**
     * Zones worth asking about: anything not already known to be serving.
     * A zone drops out of this set the moment it goes live.
     *
     * @return Builder<Domain>
     */
    public function zonesAwaitingActivation(): Builder
    {
        return Domain::query()
            ->where('cloudflare_dns_enabled', true)
            ->whereNotNull('cloudflare_zone_id')
            ->whereDoesntHave('dnsZones', function ($query): void {
                $query->where('provider', 'cloudflare')->where('provider_status', self::LIVE);
            });
    }

    private function announceLive(Domain $domain): void
    {
        $domain->loadMissing('user');
        $owner = $domain->user;

        if (! $owner) {
            return;
        }

        $this->inApp->pushEvent(
            $owner,
            NotificationEvent::DomainDnsLive,
            'DNS is live for '.$domain->fqdn(),
            'The nameservers have been picked up at the registry and your records are being served.',
            customer_portal_route($owner, 'customer.domains.dns.index', $domain),
        );

        try {
            // Routing is EmailDeliveryService's business: a customer who belongs
            // to a reseller is mailed through that reseller's SMTP and branding,
            // so this never arrives from the platform over a reseller's head.
            $this->email->sendCustomerMailable(
                $owner,
                new DomainDnsLiveMail($domain),
                'DNS is live for '.$domain->fqdn(),
                NotificationEvent::DomainDnsLive,
            );
        } catch (\Throwable $e) {
            // A reseller who has not configured SMTP must not fail the sweep for
            // everyone else; the in-app notice above has already landed.
            Log::info('DNS live email not sent', [
                'domain_id' => $domain->id,
                'user_id' => $owner->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function zoneRow(Domain $domain): ?DnsZone
    {
        return DnsZone::query()
            ->where('domain_id', $domain->id)
            ->where('provider', 'cloudflare')
            ->first();
    }

    private function store(Domain $domain, string $state, ?string $zoneName = null, bool $markActivated = false): DnsZone
    {
        $attributes = [
            'name' => $zoneName ?? $domain->fqdn(),
            'external_zone_id' => (string) $domain->cloudflare_zone_id,
            'provider_status' => $state,
            'provider_checked_at' => now(),
        ];

        if ($markActivated) {
            $existing = $this->zoneRow($domain);
            $attributes['activated_at'] = $existing?->activated_at ?? now();
        }

        return DnsZone::query()->updateOrCreate(
            ['domain_id' => $domain->id, 'provider' => 'cloudflare'],
            $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $state, Domain $domain, ?DnsZone $row): array
    {
        [$label, $detail] = match ($state) {
            self::LIVE => ['Live on Cloudflare', 'This domain is answering DNS queries worldwide.'],
            self::PENDING => [
                'Waiting for nameservers',
                'Cloudflare has the zone but has not seen these nameservers at the registry yet. Set them on the domain and it goes live on its own.',
            ],
            self::MISSING => [
                'Not on Cloudflare',
                'The zone this domain pointed at no longer exists. Re-sync to put it back.',
            ],
            self::DRIFTED => [
                'Zone name no longer matches',
                'Cloudflare still holds this zone under a different name, so records written here do not belong to this domain. Re-sync to move it.',
            ],
            self::NONE => ['Not using Cloudflare DNS', 'This domain is not on Talksasa Cloudflare DNS.'],
            default => ['Status unknown', 'Cloudflare could not be reached the last time this was checked.'],
        };

        return [
            'state' => $state,
            'label' => $label,
            'detail' => $detail,
            'checked_at' => $row?->provider_checked_at,
            'activated_at' => $row?->activated_at,
            'needs_resync' => in_array($state, [self::MISSING, self::DRIFTED], true),
        ];
    }
}

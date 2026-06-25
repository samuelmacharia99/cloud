<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Service;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Support\Carbon;

class DomainActivationService
{
    /**
     * Activate the domain linked to a service (checkout domain product or hosting add-on).
     */
    public function activateFromService(Service $service): void
    {
        $domain = $this->resolveDomain($service);
        if (! $domain) {
            return;
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        if (! empty($meta['transfer_pending']) && $domain->isTransfer()) {
            return;
        }

        if ($domain->status === 'active' && $domain->registered_at && $domain->expires_at?->isFuture()) {
            app(DomainCloudflareDnsService::class)->provisionFromServiceMeta($domain, $meta);

            return;
        }

        $years = max(1, (int) ($meta['years'] ?? $meta['domain_registration_years'] ?? 1));

        $domain->update([
            'status' => 'active',
            'registered_at' => $domain->registered_at ?? now(),
            'expires_at' => $domain->expires_at ?? now()->addYears($years),
        ]);

        \Log::info('Domain activated from service', [
            'domain_id' => $domain->id,
            'domain_name' => $domain->name.$domain->extension,
            'service_id' => $service->id,
            'expires_at' => $domain->expires_at,
        ]);

        $this->syncLinkedServices($domain);
        app(DomainCloudflareDnsService::class)->provisionFromServiceMeta($domain->fresh(), $meta);
    }

    /**
     * When admin activates a domain record, align any linked pending services.
     */
    public function syncLinkedServices(Domain $domain, ServiceStatus $status = ServiceStatus::Active): void
    {
        Service::query()
            ->where('user_id', $domain->user_id)
            ->where(function ($query) use ($domain) {
                $query->whereJsonContains('service_meta->domain_id', $domain->id)
                    ->orWhere('name', $domain->name.$domain->extension);
            })
            ->whereIn('status', [
                ServiceStatus::Pending,
                ServiceStatus::Provisioning,
            ])
            ->update(['status' => $status->value]);
    }

    public function resolveDomain(Service $service): ?Domain
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $domainId = $meta['domain_id'] ?? null;

        if ($domainId) {
            return Domain::find($domainId);
        }

        $name = $service->name;
        if (! str_contains($name, '.')) {
            return null;
        }

        $parts = explode('.', $name, 2);

        return Domain::query()
            ->where('user_id', $service->user_id)
            ->where('name', $parts[0])
            ->where('extension', '.'.$parts[1])
            ->first();
    }

    /**
     * Apply admin-provided registration/expiry dates when activating manually.
     *
     * @param  array{registered_at?: mixed, expires_at?: mixed}  $dates
     */
    public function applyAdminActivation(Domain $domain, array $dates = []): void
    {
        if ($domain->status !== 'active') {
            return;
        }

        $updates = [];

        if (! empty($dates['registered_at']) && ! $domain->registered_at) {
            $updates['registered_at'] = Carbon::parse($dates['registered_at']);
        }

        if (! empty($dates['expires_at']) && ! $domain->expires_at) {
            $updates['expires_at'] = Carbon::parse($dates['expires_at']);
        }

        if ($updates !== []) {
            $domain->update($updates);
        }

        $this->syncLinkedServices($domain);
    }
}

<?php

namespace App\Services\Admin;

use App\Models\Domain;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Services\DomainActivationService;
use Illuminate\Support\Facades\DB;

class AdminDomainUpdateService
{
    /**
     * @param  array{
     *     name: string,
     *     extension: string,
     *     registrar?: ?string,
     *     status: string,
     *     registered_at?: ?string,
     *     expires_at?: ?string,
     *     nameserver_1?: ?string,
     *     nameserver_2?: ?string,
     *     notes?: ?string,
     *     auto_renew?: bool
     * }  $validated
     */
    public function update(Domain $domain, array $validated): Domain
    {
        $oldFqdn = strtolower($domain->fqdn());
        $newName = (string) $validated['name'];
        $newExtension = format_domain_extension((string) $validated['extension']);
        $newFqdn = format_domain_name($newName, $newExtension);
        $renamed = $oldFqdn !== $newFqdn;

        DB::transaction(function () use ($domain, $validated, $renamed, $newName, $newExtension, $newFqdn): void {
            $domain->update([
                'name' => $newName,
                'extension' => $newExtension,
                'registrar' => $validated['registrar'] ?? null,
                'status' => $validated['status'],
                'registered_at' => $validated['registered_at'] ?? null,
                'expires_at' => $validated['expires_at'] ?? null,
                'nameserver_1' => $validated['nameserver_1'] ?? null,
                'nameserver_2' => $validated['nameserver_2'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'auto_renew' => (bool) ($validated['auto_renew'] ?? false),
            ]);

            if (! $renamed) {
                return;
            }

            $domain->dnsZones()->update(['name' => $newFqdn]);
            $this->syncLinkedServices($domain, $newFqdn);
            $this->syncLinkedDomainOrders($domain, $newName, $newExtension);
        });

        if ($validated['status'] === 'active') {
            app(DomainActivationService::class)->applyAdminActivation($domain->fresh(), $validated);
        }

        return $domain->fresh(['user', 'domainExtension']);
    }

    private function syncLinkedServices(Domain $domain, string $newFqdn): void
    {
        Service::query()
            ->whereJsonContains('service_meta->domain_id', $domain->id)
            ->get()
            ->each(function (Service $service) use ($newFqdn): void {
                $meta = is_array($service->service_meta) ? $service->service_meta : [];
                $meta['domain'] = $newFqdn;
                $service->update(['service_meta' => $meta]);
            });
    }

    private function syncLinkedDomainOrders(Domain $domain, string $newName, string $newExtension): void
    {
        ResellerDomainOrder::query()
            ->where('domain_id', $domain->id)
            ->update([
                'domain_name' => $newName,
                'extension' => $newExtension,
            ]);
    }
}

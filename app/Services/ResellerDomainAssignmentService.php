<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Domains a reseller registered on their own wholesale account that really
 * belong to one of their customers. The customer's portal lists only what
 * the customer owns, so these stay invisible until they are assigned.
 */
class ResellerDomainAssignmentService
{
    public function __construct(
        private readonly ResellerScopeService $scope,
        private readonly ResellerDomainTransferService $transfers,
    ) {}

    /**
     * Reseller-owned domains with the customer whose hosting service carries
     * the same name, when there is one.
     *
     * @return Collection<int, array{domain: Domain, suggested: ?User, service: ?Service}>
     */
    public function unassigned(User $reseller): Collection
    {
        $domains = Domain::query()
            ->where('user_id', $reseller->id)
            ->where(function ($query) use ($reseller) {
                $query->whereNull('reseller_id')->orWhere('reseller_id', $reseller->id);
            })
            ->whereNull('pending_transfer_to_user_id')
            ->orderBy('name')
            ->get();
        if ($domains->isEmpty()) {
            return collect();
        }

        $byHostname = $this->customerServicesByHostname($reseller);

        return $domains->map(function (Domain $domain) use ($byHostname): array {
            $service = $byHostname[strtolower($domain->fqdn())] ?? null;

            return [
                'domain' => $domain,
                'suggested' => $service?->user,
                'service' => $service,
            ];
        })->values();
    }

    /**
     * Move each domain to the chosen customer. Anything not the reseller's
     * own domain, or not their customer, is skipped and named.
     *
     * @param  array<int|string, int|string>  $assignments  domain id => customer id
     * @return array{assigned: int, skipped: list<string>}
     */
    public function assign(User $reseller, array $assignments): array
    {
        $assigned = 0;
        $skipped = [];

        foreach ($assignments as $domainId => $customerId) {
            $domainId = (int) $domainId;
            $customerId = (int) $customerId;
            if ($domainId <= 0 || $customerId <= 0) {
                continue;
            }

            $domain = Domain::query()->find($domainId);
            if (! $domain || (int) $domain->user_id !== (int) $reseller->id) {
                $skipped[] = ($domain?->fqdn() ?? '#'.$domainId).': not on your own account.';

                continue;
            }

            $customer = User::query()->find($customerId);
            if (! $customer || $customer->is_reseller || ! $this->scope->ownsCustomer($reseller, $customer)) {
                $skipped[] = $domain->fqdn().': that customer is not on your book.';

                continue;
            }

            try {
                $this->transfers->transferBetweenOwnedCustomers($domain, $reseller, $customer, $reseller);
                $assigned++;
            } catch (\Throwable $e) {
                $skipped[] = $domain->fqdn().': '.$e->getMessage();
            }
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * @return array<string, Service>
     */
    private function customerServicesByHostname(User $reseller): array
    {
        $map = [];
        $services = $this->scope->managedServicesQuery($reseller)
            ->whereNotIn('status', ['terminated', 'cancelled'])
            ->where('user_id', '!=', $reseller->id)
            ->with(['user', 'product', 'containerDeployment.domains'])
            ->get();

        foreach ($services as $service) {
            if (! $service->user || $service->user->is_reseller) {
                continue;
            }
            $names = [];
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            foreach ([$meta['domain'] ?? null, $service->attachedDomainName(), $service->name] as $candidate) {
                if (is_string($candidate) && str_contains($candidate, '.')) {
                    $names[] = $candidate;
                }
            }
            foreach ($service->containerDeployment?->domains ?? [] as $bound) {
                $names[] = (string) $bound->domain;
            }
            foreach ($names as $name) {
                $key = strtolower(trim($name));
                $key = preg_replace('/^www\./', '', $key) ?? $key;
                if ($key !== '' && ! isset($map[$key])) {
                    $map[$key] = $service;
                }
            }
        }

        return $map;
    }
}

<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceTransferService
{
    public function __construct(
        private ResellerServiceCatalogMatchService $catalogMatcher,
    ) {}

    /**
     * @return array{
     *     service: array{id: int, name: string},
     *     from: array{id: int, name: string, email: string, reseller: ?string},
     *     to: array{id: int, name: string, email: string, reseller: ?string},
     *     attached_domain: ?string,
     *     warnings: list<string>
     * }
     */
    public function preview(Service $service, User $targetCustomer): array
    {
        $this->assertTransferAllowed($service, $targetCustomer);

        $fromCustomer = $service->user;
        $warnings = $this->buildWarnings($service, $fromCustomer, $targetCustomer);

        return [
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
            ],
            'from' => $this->customerSummary($fromCustomer),
            'to' => $this->customerSummary($targetCustomer),
            'attached_domain' => $service->attachedDomainName(),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{
     *     from_customer: string,
     *     to_customer: string,
     *     domain_transferred: bool,
     *     catalog_warnings: list<string>,
     *     da_warnings: list<string>
     * }
     */
    public function transfer(Service $service, User $targetCustomer, bool $transferDomain = false): array
    {
        $this->assertTransferAllowed($service, $targetCustomer);

        $fromCustomer = $service->user->fresh();
        $previousResellerId = $service->reseller_id;
        $catalogWarnings = [];
        $daWarnings = [];
        $domainTransferred = false;

        DB::transaction(function () use (
            $service,
            $targetCustomer,
            $fromCustomer,
            $previousResellerId,
            $transferDomain,
            &$catalogWarnings,
            &$daWarnings,
            &$domainTransferred,
        ) {
            $service->update([
                'user_id' => $targetCustomer->id,
                'reseller_id' => $targetCustomer->reseller_id,
            ]);

            $service->refresh();

            if ($previousResellerId !== $targetCustomer->reseller_id) {
                $catalogWarnings = $this->syncResellerCatalog($service, $targetCustomer);
                $daWarnings = $this->syncDirectAdminReseller($service, $targetCustomer);
            }

            if ($transferDomain) {
                $domainTransferred = $this->transferAttachedDomain($service, $fromCustomer, $targetCustomer);
            }

            $this->appendTransferNote($service, $fromCustomer, $targetCustomer, $domainTransferred);
        });

        AdminActivityService::log(
            'service.transfer',
            "Transferred service #{$service->id} ({$service->name}) from {$fromCustomer->name} to {$targetCustomer->name}",
            $service->fresh(),
            [
                'from_user_id' => $fromCustomer->id,
                'to_user_id' => $targetCustomer->id,
                'from_reseller_id' => $previousResellerId,
                'to_reseller_id' => $targetCustomer->reseller_id,
                'domain_transferred' => $domainTransferred,
                'catalog_warnings' => $catalogWarnings,
                'da_warnings' => $daWarnings,
            ],
        );

        return [
            'from_customer' => $fromCustomer->name,
            'to_customer' => $targetCustomer->name,
            'domain_transferred' => $domainTransferred,
            'catalog_warnings' => $catalogWarnings,
            'da_warnings' => $daWarnings,
        ];
    }

    private function assertTransferAllowed(Service $service, User $targetCustomer): void
    {
        if ($targetCustomer->is_admin) {
            throw new \InvalidArgumentException('Services cannot be transferred to administrator accounts.');
        }

        if ($targetCustomer->is_reseller) {
            throw new \InvalidArgumentException('Services cannot be transferred to reseller accounts. Transfer to one of the reseller\'s customers instead.');
        }

        if ((int) $service->user_id === (int) $targetCustomer->id) {
            throw new \InvalidArgumentException('Service is already assigned to this customer.');
        }

        if (! $service->user) {
            throw new \InvalidArgumentException('Service has no current owner.');
        }
    }

    /**
     * @return list<string>
     */
    private function buildWarnings(Service $service, User $fromCustomer, User $targetCustomer): array
    {
        $warnings = [];

        if ($fromCustomer->reseller_id !== $targetCustomer->reseller_id) {
            $warnings[] = 'Customers belong to different resellers — reseller catalog mapping and DirectAdmin ownership may be updated.';
        }

        if ($service->isSharedHosting() && $fromCustomer->reseller_id !== $targetCustomer->reseller_id) {
            $targetReseller = $targetCustomer->reseller;
            if ($targetReseller && ! filled($targetReseller->directadmin_username)) {
                $warnings[] = 'Target reseller has no linked DirectAdmin account — hosting may remain under the previous reseller on the server.';
            }
        }

        if ($service->invoice_id) {
            $warnings[] = 'Historical invoices stay with the previous customer; only future billing uses the new owner.';
        }

        return $warnings;
    }

    /**
     * @return array{id: int, name: string, email: string, reseller: ?string}
     */
    private function customerSummary(User $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'reseller' => $customer->reseller?->name,
        ];
    }

    /**
     * @return list<string>
     */
    private function syncResellerCatalog(Service $service, User $targetCustomer): array
    {
        $warnings = [];

        if ($targetCustomer->reseller_id) {
            $targetReseller = $targetCustomer->reseller;
            if (! $targetReseller) {
                return ['Target customer references a missing reseller record.'];
            }

            $match = $this->catalogMatcher->applyMatch($targetReseller, $service->fresh());
            if (! $match) {
                $warnings[] = 'Could not map service to a plan in the target reseller catalog.';
            }

            return $warnings;
        }

        $this->catalogMatcher->clearResellerCatalogAssignment($service->fresh());

        return $warnings;
    }

    /**
     * @return list<string>
     */
    private function syncDirectAdminReseller(Service $service, User $targetCustomer): array
    {
        if (! $service->isSharedHosting()) {
            return [];
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $username = $meta['username'] ?? null;
        $node = $service->node;

        if (! filled($username) || ! $node) {
            return [];
        }

        $targetResellerDa = $targetCustomer->reseller?->directadmin_username;
        $directAdmin = new DirectAdminService($node);

        if (! $directAdmin->isConfigured()) {
            return ["DirectAdmin API not configured on node {$node->name}."];
        }

        $result = $directAdmin->reassignUserReseller(
            (string) $username,
            filled($targetResellerDa) ? (string) $targetResellerDa : null,
        );

        if ($result['success']) {
            $meta['directadmin_reseller'] = filled($targetResellerDa) ? (string) $targetResellerDa : null;
            $service->update(['service_meta' => $meta]);

            return [];
        }

        $message = "DirectAdmin reassignment failed for {$username}: {$result['message']}";
        Log::warning('DirectAdmin reseller reassignment failed during service transfer', [
            'service_id' => $service->id,
            'username' => $username,
            'target_reseller_da' => $targetResellerDa,
            'error' => $result['message'],
        ]);

        return [$message];
    }

    private function transferAttachedDomain(Service $service, User $fromCustomer, User $targetCustomer): bool
    {
        $attachedDomain = $service->attachedDomainName();
        if (! $attachedDomain) {
            return false;
        }

        $domain = Domain::query()
            ->where('user_id', $fromCustomer->id)
            ->get()
            ->first(fn (Domain $record) => strcasecmp($record->fqdn(), $attachedDomain) === 0);

        if (! $domain) {
            return false;
        }

        $notes = is_array($domain->notes) ? $domain->notes : [];
        $notes[] = [
            'type' => 'service_transfer',
            'from' => $fromCustomer->name,
            'to' => $targetCustomer->name,
            'service_id' => $service->id,
            'transferred_at' => now()->toIso8601String(),
        ];

        $domain->update([
            'user_id' => $targetCustomer->id,
            'reseller_id' => $targetCustomer->reseller_id,
            'notes' => $notes,
        ]);

        return true;
    }

    private function appendTransferNote(Service $service, User $fromCustomer, User $targetCustomer, bool $domainTransferred): void
    {
        $note = sprintf(
            '[Transfer %s] Moved from %s (#%d) to %s (#%d)%s.',
            now()->format('Y-m-d H:i'),
            $fromCustomer->name,
            $fromCustomer->id,
            $targetCustomer->name,
            $targetCustomer->id,
            $domainTransferred ? ' with attached domain' : '',
        );

        $existing = trim((string) ($service->notes ?? ''));
        $service->update([
            'notes' => $existing !== '' ? $existing."\n".$note : $note,
        ]);
    }
}

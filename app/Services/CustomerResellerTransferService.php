<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerResellerTransferService
{
    /**
     * Reassign a managed customer (and their managed resources) to another reseller,
     * or back to platform management when $targetReseller is null.
     * The customer account keeps ownership of services, domains, invoices, and payments.
     *
     * @return array{from_reseller: ?string, to_reseller: string, da_warnings: list<string>}
     */
    public function transfer(User $customer, ?User $targetReseller): array
    {
        if ($customer->is_admin || $customer->is_reseller) {
            throw new \InvalidArgumentException('Only non-reseller customer accounts can be transferred.');
        }

        if ($targetReseller !== null) {
            if (! $targetReseller->is_reseller) {
                throw new \InvalidArgumentException('Target user is not a reseller.');
            }

            if ($customer->id === $targetReseller->id) {
                throw new \InvalidArgumentException('Cannot transfer a customer to themselves.');
            }

            if ($customer->reseller_id === $targetReseller->id) {
                throw new \InvalidArgumentException('Customer is already assigned to this reseller.');
            }
        } elseif ($customer->reseller_id === null) {
            throw new \InvalidArgumentException('Customer is already managed by the platform.');
        }

        $previousResellerName = $customer->reseller?->name;
        $previousResellerId = $customer->reseller_id;
        $newResellerId = $targetReseller?->id;

        DB::transaction(function () use ($customer, $newResellerId) {
            $customer->update(['reseller_id' => $newResellerId]);

            Service::query()
                ->where('user_id', $customer->id)
                ->update(['reseller_id' => $newResellerId]);

            Domain::query()
                ->where('user_id', $customer->id)
                ->update(['reseller_id' => $newResellerId]);

            ResellerDomainOrder::query()
                ->where('customer_id', $customer->id)
                ->update(['reseller_id' => $newResellerId]);
        });

        $daWarnings = $this->syncDirectAdminHostingAccounts($customer, $targetReseller);

        $toLabel = $targetReseller?->name ?? 'Platform (direct)';

        AdminActivityService::log(
            'customer.reseller_transfer',
            "Transferred customer {$customer->name} from ".($previousResellerName ?? 'Platform')." to {$toLabel}",
            $customer,
            [
                'from_reseller_id' => $previousResellerId,
                'to_reseller_id' => $newResellerId,
                'da_warnings' => $daWarnings,
            ],
        );

        return [
            'from_reseller' => $previousResellerName,
            'to_reseller' => $toLabel,
            'da_warnings' => $daWarnings,
        ];
    }

    /**
     * Best-effort move of shared-hosting accounts on DirectAdmin when reseller ownership changes.
     *
     * @return list<string>
     */
    private function syncDirectAdminHostingAccounts(User $customer, ?User $targetReseller): array
    {
        $warnings = [];
        $newResellerDa = $targetReseller?->directadmin_username;

        $services = Service::query()
            ->where('user_id', $customer->id)
            ->whereHas('product', fn ($q) => $q->where('type', 'shared_hosting'))
            ->with(['product', 'node'])
            ->get();

        foreach ($services as $service) {
            $meta = $service->service_meta ?? [];
            $username = $meta['username'] ?? null;
            $node = $service->node;

            if (! $username || ! $node || $node->type !== 'directadmin') {
                continue;
            }

            $directAdmin = new DirectAdminService($node);

            if (! $directAdmin->isConfigured()) {
                $warnings[] = "Service #{$service->id}: DirectAdmin API not configured on node {$node->name}.";

                continue;
            }

            $result = $directAdmin->reassignUserReseller(
                $username,
                filled($newResellerDa) ? (string) $newResellerDa : null,
            );

            if (! $result['success']) {
                $message = "Service #{$service->id} ({$username}): {$result['message']}";
                $warnings[] = $message;
                Log::warning('DirectAdmin reseller reassignment failed during customer transfer', [
                    'customer_id' => $customer->id,
                    'service_id' => $service->id,
                    'username' => $username,
                    'target_reseller_da' => $newResellerDa,
                    'error' => $result['message'],
                ]);
            }
        }

        return $warnings;
    }
}

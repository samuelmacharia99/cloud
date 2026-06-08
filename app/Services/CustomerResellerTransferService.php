<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerResellerTransferService
{
    /**
     * Reassign a managed customer (and their managed resources) to another reseller.
     * The customer account keeps ownership of services, domains, invoices, and payments.
     *
     * @return array{from_reseller: ?string, to_reseller: string}
     */
    public function transfer(User $customer, User $targetReseller): array
    {
        if ($customer->is_admin || $customer->is_reseller) {
            throw new \InvalidArgumentException('Only non-reseller customer accounts can be transferred.');
        }

        if (! $targetReseller->is_reseller) {
            throw new \InvalidArgumentException('Target user is not a reseller.');
        }

        if ($customer->id === $targetReseller->id) {
            throw new \InvalidArgumentException('Cannot transfer a customer to themselves.');
        }

        if ($customer->reseller_id === $targetReseller->id) {
            throw new \InvalidArgumentException('Customer is already assigned to this reseller.');
        }

        $previousResellerName = $customer->reseller?->name;

        DB::transaction(function () use ($customer, $targetReseller) {
            $customer->update(['reseller_id' => $targetReseller->id]);

            Service::query()
                ->where('user_id', $customer->id)
                ->update(['reseller_id' => $targetReseller->id]);

            Domain::query()
                ->where('user_id', $customer->id)
                ->update(['reseller_id' => $targetReseller->id]);

            ResellerDomainOrder::query()
                ->where('customer_id', $customer->id)
                ->update(['reseller_id' => $targetReseller->id]);
        });

        return [
            'from_reseller' => $previousResellerName,
            'to_reseller' => $targetReseller->name,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ResellerScopeService
{
    public function ownsCustomer(User $reseller, User $customer): bool
    {
        if ($customer->reseller_id === $reseller->id) {
            return true;
        }

        return Service::query()
            ->where('reseller_id', $reseller->id)
            ->where('user_id', $customer->id)
            ->exists();
    }

    /**
     * @return Builder<Service>
     */
    public function managedServicesQuery(User $reseller): Builder
    {
        return Service::query()->where(function (Builder $query) use ($reseller) {
            $query->where('reseller_id', $reseller->id)
                ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('reseller_id', $reseller->id));
        });
    }

    /**
     * @return Builder<Invoice>
     */
    public function managedInvoicesQuery(User $reseller): Builder
    {
        return Invoice::query()->whereHas('user', function (Builder $query) use ($reseller) {
            $query->where('reseller_id', $reseller->id);
        });
    }

    /**
     * @return Builder<User>
     */
    public function managedCustomersQuery(User $reseller): Builder
    {
        return User::query()->where('reseller_id', $reseller->id);
    }

    public function managedCustomerCount(User $reseller): int
    {
        return $this->managedCustomersQuery($reseller)->count();
    }

    /**
     * @return list<int>
     */
    public function managedCustomerIds(User $reseller): array
    {
        return $this->managedCustomersQuery($reseller)->pluck('id')->all();
    }
}

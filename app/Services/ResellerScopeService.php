<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Payment;
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
     * @return Builder<Payment>
     */
    public function managedPaymentsQuery(User $reseller): Builder
    {
        $customerIds = $this->managedCustomerIds($reseller);

        return Payment::query()
            ->whereHas('invoice', fn (Builder $query) => $query->whereIn('user_id', $customerIds ?: [0]));
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

    /**
     * Customer IDs for admin/reseller ownership checks: portal-assigned customers
     * plus any legacy rows only linked via service.reseller_id.
     *
     * @return list<int>
     */
    public function allManagedCustomerIds(User $reseller): array
    {
        $assigned = $this->managedCustomersQuery($reseller)->pluck('id');

        $viaServices = Service::query()
            ->where('reseller_id', $reseller->id)
            ->distinct()
            ->pluck('user_id');

        $viaDomains = Domain::query()
            ->where('reseller_id', $reseller->id)
            ->distinct()
            ->pluck('user_id');

        return $assigned
            ->merge($viaServices)
            ->merge($viaDomains)
            ->unique()
            ->filter()
            ->values()
            ->all();
    }

    public function resellerMayAssignResourceToOwner(User $reseller, int $ownerId): bool
    {
        if ($ownerId === $reseller->id) {
            return true;
        }

        $customer = User::query()->find($ownerId);

        return $customer instanceof User && $this->ownsCustomer($reseller, $customer);
    }
}

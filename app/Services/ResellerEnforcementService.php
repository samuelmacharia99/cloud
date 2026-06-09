<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Provisioning\ProvisioningService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ResellerEnforcementService
{
    public const REASON_RESELLER_OVERDUE = 'reseller_overdue';

    public const REASON_PACKAGE_LIMIT = 'package_limit';

    public const REASON_INVOICE_OVERDUE = 'invoice_overdue';

    public const REASON_DISK_OVERQUOTA = 'disk_overquota';

    public const META_SUSPENSION_REASON = 'suspension_reason';

    public function __construct(
        private ResellerScopeService $scope,
        private ServiceOverdueEnforcementService $overdueEnforcement,
        private ResellerDirectAdminService $resellerDirectAdmin,
    ) {}

    /**
     * Resolved lazily to avoid a circular dependency with ProvisioningService.
     */
    private function provisioning(): ProvisioningService
    {
        return app(ProvisioningService::class);
    }

    public function isSuspensionEnabled(): bool
    {
        return in_array(Setting::getValue('reseller_suspend_on_overdue', 'true'), ['1', 'true', true], true);
    }

    public function isCascadeEnabled(): bool
    {
        return in_array(Setting::getValue('reseller_cascade_suspend_on_overdue', 'true'), ['1', 'true', true], true);
    }

    public function isExcessEnforcementEnabled(): bool
    {
        return in_array(Setting::getValue('reseller_suspend_excess_services', 'true'), ['1', 'true', true], true);
    }

    public function isProvisionLimitEnforcementEnabled(): bool
    {
        return in_array(Setting::getValue('reseller_enforce_limits_on_provision', 'true'), ['1', 'true', true], true);
    }

    public function resolveResellerForService(Service $service): ?User
    {
        if ($service->reseller_id) {
            return User::query()->find($service->reseller_id);
        }

        $service->loadMissing('user');

        if ($service->user?->reseller_id) {
            return User::query()->find($service->user->reseller_id);
        }

        return null;
    }

    public function assertCanProvision(Service $service): void
    {
        if (! $this->isProvisionLimitEnforcementEnabled()) {
            return;
        }

        $reseller = $this->resolveResellerForService($service);
        if (! $reseller?->is_reseller) {
            return;
        }

        if ($reseller->isResellerSuspended()) {
            throw new \RuntimeException(
                "Provisioning blocked: reseller \"{$reseller->name}\" is suspended. Pay the package subscription invoice to restore service."
            );
        }

        if (! $reseller->hasResellerPackage()) {
            throw new \RuntimeException(
                "Provisioning blocked: reseller \"{$reseller->name}\" has no active package subscription."
            );
        }

        if ($reseller->isAtServiceLimit()) {
            throw new \RuntimeException(
                "Provisioning blocked: reseller \"{$reseller->name}\" has reached the service limit ({$reseller->resellerPackage->max_services} slots)."
            );
        }

        $diskUsage = app(ResellerDiskUsageService::class);
        if ($diskUsage->isOverPool($reseller)) {
            throw new \RuntimeException(
                "Provisioning blocked: reseller \"{$reseller->name}\" has exceeded the disk pool ({$diskUsage->diskPoolGb($reseller)} GB included)."
            );
        }
    }

    /**
     * @return Builder<User>
     */
    public function resellersEligibleForSuspensionQuery(?Carbon $reference = null): Builder
    {
        $graceCutoff = $this->overdueEnforcement->graceCutoffDate($reference);

        return User::query()
            ->where('is_reseller', true)
            ->whereNull('reseller_suspended_at')
            ->where(function (Builder $query) use ($graceCutoff) {
                $query->where(function (Builder $expired) use ($graceCutoff) {
                    $expired->whereNotNull('package_expires_at')
                        ->whereDate('package_expires_at', '<', $graceCutoff->toDateString());
                })->orWhereHas('invoices', function (Builder $invoice) use ($graceCutoff) {
                    $invoice->where('type', 'reseller_subscription')
                        ->whereIn('status', ['unpaid', 'overdue'])
                        ->whereDate('due_date', '<', $graceCutoff->toDateString());
                });
            });
    }

    /**
     * @return Builder<User>
     */
    public function resellersEligibleForUnsuspensionQuery(): Builder
    {
        return User::query()
            ->where('is_reseller', true)
            ->whereNotNull('reseller_suspended_at')
            ->where(function (Builder $query) {
                $query->where(function (Builder $valid) {
                    $valid->whereNotNull('package_expires_at')
                        ->where('package_expires_at', '>', now());
                })->whereDoesntHave('invoices', function (Builder $invoice) {
                    $invoice->where('type', 'reseller_subscription')
                        ->whereIn('status', ['unpaid', 'overdue']);
                });
            });
    }

    public function shouldSuspendReseller(User $reseller): bool
    {
        if (! $reseller->is_reseller || $reseller->isResellerSuspended()) {
            return false;
        }

        if (! $this->isSuspensionEnabled()) {
            return false;
        }

        $graceCutoff = $this->overdueEnforcement->graceCutoffDate();

        if ($reseller->package_expires_at && $reseller->package_expires_at->lt($graceCutoff)) {
            return true;
        }

        return Invoice::query()
            ->where('user_id', $reseller->id)
            ->where('type', 'reseller_subscription')
            ->whereIn('status', ['unpaid', 'overdue'])
            ->whereDate('due_date', '<', $graceCutoff->toDateString())
            ->exists();
    }

    public function suspendReseller(User $reseller, string $reason = self::REASON_RESELLER_OVERDUE): int
    {
        if ($reseller->isResellerSuspended()) {
            return 0;
        }

        $reseller->update([
            'reseller_suspended_at' => now(),
            'reseller_suspension_reason' => $reason,
        ]);

        Log::warning('Reseller account suspended', [
            'reseller_id' => $reseller->id,
            'reason' => $reason,
        ]);

        app(NotificationService::class)->notifyResellerSuspended($reseller->fresh(), $reason);

        if (! $this->resellerDirectAdmin->suspendResellerAccount($reseller)) {
            Log::warning('DirectAdmin reseller suspend skipped or failed', [
                'reseller_id' => $reseller->id,
                'directadmin_username' => $reseller->directadmin_username,
            ]);
        }

        if (! $this->isCascadeEnabled()) {
            return 0;
        }

        return $this->suspendManagedServices($reseller, $reason);
    }

    public function unsuspendReseller(User $reseller): int
    {
        if (! $reseller->isResellerSuspended()) {
            return 0;
        }

        $reseller->update([
            'reseller_suspended_at' => null,
            'reseller_suspension_reason' => null,
        ]);

        Log::info('Reseller account unsuspended', ['reseller_id' => $reseller->id]);

        if (! $this->resellerDirectAdmin->unsuspendResellerAccount($reseller)) {
            Log::info('DirectAdmin reseller unsuspend skipped or failed', [
                'reseller_id' => $reseller->id,
                'directadmin_username' => $reseller->directadmin_username,
            ]);
        }

        if (! $this->isCascadeEnabled()) {
            return 0;
        }

        return $this->unsuspendManagedServicesForResellerBilling($reseller);
    }

    public function handleSubscriptionPaid(User $reseller): void
    {
        if (! $reseller->is_reseller) {
            return;
        }

        if ($this->resellerBillingIsCurrent($reseller)) {
            $this->unsuspendReseller($reseller);
        }
    }

    public function resellerBillingIsCurrent(User $reseller): bool
    {
        if (! $reseller->hasResellerPackage()) {
            return false;
        }

        if (! $reseller->package_expires_at || $reseller->package_expires_at->isPast()) {
            return false;
        }

        return ! Invoice::query()
            ->where('user_id', $reseller->id)
            ->where('type', 'reseller_subscription')
            ->whereIn('status', ['unpaid', 'overdue'])
            ->exists();
    }

    /**
     * @return Collection<int, Service>
     */
    public function excessActiveServices(User $reseller): Collection
    {
        $package = $reseller->resellerPackage;
        if (! $package) {
            return collect();
        }

        $limit = $package->max_services;
        $active = $this->scope->managedServicesQuery($reseller)
            ->where('status', ServiceStatus::Active)
            ->orderBy('commenced_at')
            ->orderBy('id')
            ->get();

        if ($active->count() <= $limit) {
            return collect();
        }

        return $active->slice($limit)->values();
    }

    public function enforcePackageLimitsForReseller(User $reseller): int
    {
        if (! $this->isExcessEnforcementEnabled() || ! $reseller->hasResellerPackage()) {
            return 0;
        }

        $count = 0;

        foreach ($this->excessActiveServices($reseller) as $service) {
            try {
                $this->suspendServiceForEnforcement($service, self::REASON_PACKAGE_LIMIT);
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed to suspend excess reseller service', [
                    'service_id' => $service->id,
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function suspendManagedServices(User $reseller, string $reason): int
    {
        $services = $this->scope->managedServicesQuery($reseller)
            ->where('status', ServiceStatus::Active)
            ->get();

        $count = 0;

        foreach ($services as $service) {
            try {
                $this->suspendServiceForEnforcement($service, $reason);
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed cascade suspend for reseller service', [
                    'service_id' => $service->id,
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function unsuspendManagedServicesForResellerBilling(User $reseller): int
    {
        $services = $this->scope->managedServicesQuery($reseller)
            ->where('status', ServiceStatus::Suspended)
            ->get()
            ->filter(fn (Service $service) => $this->wasSuspendedByResellerEnforcement($service));

        $count = 0;

        foreach ($services as $service) {
            try {
                $this->clearEnforcementMeta($service);
                $this->provisioning()->unsuspend($service->fresh());
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed cascade unsuspend for reseller service', [
                    'service_id' => $service->id,
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    public function suspendServiceForEnforcement(Service $service, string $reason): void
    {
        if ($service->status !== ServiceStatus::Active) {
            return;
        }

        $meta = $service->service_meta ?? [];
        $meta[self::META_SUSPENSION_REASON] = $reason;
        $service->update(['service_meta' => $meta]);

        $this->provisioning()->suspend($service->fresh());
    }

    protected function wasSuspendedByResellerEnforcement(Service $service): bool
    {
        $reason = $service->service_meta[self::META_SUSPENSION_REASON] ?? null;

        return in_array($reason, [self::REASON_RESELLER_OVERDUE, self::REASON_PACKAGE_LIMIT], true);
    }

    protected function clearEnforcementMeta(Service $service): void
    {
        $meta = $service->service_meta ?? [];
        unset($meta[self::META_SUSPENSION_REASON]);
        $service->update(['service_meta' => $meta ?: null]);
    }
}

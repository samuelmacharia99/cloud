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

    public const REASON_PACKAGE_OVERQUOTA = 'package_overquota';

    public const REASON_RESELLER_DISK_POOL_OVER = 'reseller_disk_pool_overquota';

    public const REASON_RESELLER_USER_OVER = 'reseller_user_overquota';

    public const REASON_MANUAL = 'manual';

    public const META_SUSPENSION_REASON = 'suspension_reason';

    public const META_SUSPENSION_NOTE = 'suspension_note';

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

    public function isDiskPoolSuspensionEnabled(): bool
    {
        return in_array(Setting::getValue('reseller_suspend_on_disk_pool_overquota', 'true'), ['1', 'true', true], true);
    }

    public function isUserLimitSuspensionEnabled(): bool
    {
        return in_array(Setting::getValue('reseller_suspend_on_user_overquota', 'true'), ['1', 'true', true], true);
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

    /**
     * Suspend overdue resellers immediately (cron, mark-overdue, middleware, admin action).
     * Returns true when a new platform suspension was applied.
     */
    public function enforceOverdueSuspension(User $reseller): bool
    {
        if (! $this->isSuspensionEnabled()) {
            return false;
        }

        if ($reseller->isResellerSuspended()) {
            $this->retryDirectAdminSuspendIfNeeded($reseller);

            return false;
        }

        if (! $this->shouldSuspendReseller($reseller)) {
            return false;
        }

        $this->suspendReseller($reseller);

        return true;
    }

    public function retryDirectAdminSuspendIfNeeded(User $reseller): bool
    {
        if (! filled($reseller->directadmin_username) || ! $reseller->directAdminSyncFailed()) {
            return false;
        }

        if (! $reseller->isResellerSuspended()) {
            $reseller->update(['reseller_directadmin_sync_failed_at' => null]);

            return false;
        }

        if ($this->resellerDirectAdmin->suspendResellerAccount($reseller->fresh())) {
            $reseller->update(['reseller_directadmin_sync_failed_at' => null]);
            Log::info('DirectAdmin reseller suspend retry succeeded', [
                'reseller_id' => $reseller->id,
            ]);

            return true;
        }

        return false;
    }

    public function suspendReseller(User $reseller, string $reason = self::REASON_RESELLER_OVERDUE): int
    {
        if ($reseller->isResellerSuspended()) {
            return 0;
        }

        $reseller->update([
            'reseller_suspended_at' => now(),
            'reseller_suspension_reason' => $reason,
            'reseller_directadmin_sync_failed_at' => null,
        ]);

        Log::warning('Reseller account suspended', [
            'reseller_id' => $reseller->id,
            'reason' => $reason,
        ]);

        app(NotificationService::class)->notifyResellerSuspended(
            $reseller->fresh(),
            $this->suspensionReasonLabel($reason),
        );

        if (filled($reseller->directadmin_username)) {
            if (! $this->resellerDirectAdmin->suspendResellerAccount($reseller->fresh())) {
                $reseller->update(['reseller_directadmin_sync_failed_at' => now()]);
                Log::warning('DirectAdmin reseller suspend skipped or failed', [
                    'reseller_id' => $reseller->id,
                    'directadmin_username' => $reseller->directadmin_username,
                ]);
            }
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
            'reseller_directadmin_sync_failed_at' => null,
        ]);

        Log::info('Reseller account unsuspended', ['reseller_id' => $reseller->id]);

        if (filled($reseller->directadmin_username)) {
            if (! $this->resellerDirectAdmin->unsuspendResellerAccount($reseller->fresh())) {
                $reseller->update(['reseller_directadmin_sync_failed_at' => now()]);
                Log::info('DirectAdmin reseller unsuspend skipped or failed', [
                    'reseller_id' => $reseller->id,
                    'directadmin_username' => $reseller->directadmin_username,
                ]);
            }
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

    /**
     * @return array{suspended: int, restored: int, service_slots: int}
     */
    public function enforceAllPackageLimits(): array
    {
        $suspended = 0;
        $restored = 0;
        $serviceSlots = 0;

        $resellers = User::query()
            ->where('is_reseller', true)
            ->whereNotNull('reseller_package_id')
            ->with('resellerPackage')
            ->get();

        foreach ($resellers as $reseller) {
            try {
                $serviceSlots += $this->enforcePackageLimitsForReseller($reseller);

                if ($this->enforceDiskPoolLimit($reseller)) {
                    $suspended++;
                } elseif ($this->restoreDiskPoolLimit($reseller)) {
                    $restored++;
                }

                if ($this->enforceUserLimit($reseller)) {
                    $suspended++;
                } elseif ($this->restoreUserLimit($reseller)) {
                    $restored++;
                }
            } catch (\Throwable $e) {
                Log::error('Reseller package enforcement failed', [
                    'reseller_id' => $reseller->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('suspended', 'restored', 'serviceSlots');
    }

    public function enforceDiskPoolLimit(User $reseller): bool
    {
        if (! $this->isDiskPoolSuspensionEnabled() || ! $reseller->hasResellerPackage() || $reseller->isResellerSuspended()) {
            return false;
        }

        $diskUsage = app(ResellerDiskUsageService::class);
        if (! $diskUsage->isOverPool($reseller)) {
            return false;
        }

        $this->suspendReseller($reseller, self::REASON_RESELLER_DISK_POOL_OVER);

        return true;
    }

    public function restoreDiskPoolLimit(User $reseller): bool
    {
        if (! $reseller->isResellerSuspended()
            || $reseller->reseller_suspension_reason !== self::REASON_RESELLER_DISK_POOL_OVER) {
            return false;
        }

        $diskUsage = app(ResellerDiskUsageService::class);
        if ($diskUsage->isOverPool($reseller) || ! $this->resellerBillingIsCurrent($reseller)) {
            return false;
        }

        $this->unsuspendReseller($reseller);

        return true;
    }

    public function enforceUserLimit(User $reseller): bool
    {
        if (! $this->isUserLimitSuspensionEnabled() || ! $reseller->hasResellerPackage() || $reseller->isResellerSuspended()) {
            return false;
        }

        $maxUsers = (int) $reseller->resellerPackage->max_users;
        if ($maxUsers <= 0) {
            return false;
        }

        if ($reseller->getResellerUserCountForLimits() <= $maxUsers) {
            return false;
        }

        $this->suspendReseller($reseller, self::REASON_RESELLER_USER_OVER);

        return true;
    }

    public function restoreUserLimit(User $reseller): bool
    {
        if (! $reseller->isResellerSuspended()
            || $reseller->reseller_suspension_reason !== self::REASON_RESELLER_USER_OVER) {
            return false;
        }

        $maxUsers = (int) ($reseller->resellerPackage?->max_users ?? 0);
        if ($maxUsers <= 0 || $reseller->getResellerUserCountForLimits() > $maxUsers) {
            return false;
        }

        if (! $this->resellerBillingIsCurrent($reseller)) {
            return false;
        }

        $this->unsuspendReseller($reseller);

        return true;
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
            if ($this->overdueEnforcement->shouldSuspendForOverdueInvoice($service)) {
                Log::info('Skipping cascade unsuspend — customer billing invoice still unpaid', [
                    'service_id' => $service->id,
                    'reseller_id' => $reseller->id,
                ]);

                continue;
            }

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

        $this->provisioning()->suspend($service->fresh());

        $meta = $service->fresh()->service_meta ?? [];
        $meta[self::META_SUSPENSION_REASON] = $reason;
        $service->update(['service_meta' => $meta]);
    }

    protected function wasSuspendedByResellerEnforcement(Service $service): bool
    {
        $reason = $service->service_meta[self::META_SUSPENSION_REASON] ?? null;

        return in_array($reason, [
            self::REASON_RESELLER_OVERDUE,
            self::REASON_PACKAGE_LIMIT,
            self::REASON_RESELLER_DISK_POOL_OVER,
            self::REASON_RESELLER_USER_OVER,
        ], true);
    }

    protected function clearEnforcementMeta(Service $service): void
    {
        $meta = $service->service_meta ?? [];
        unset($meta[self::META_SUSPENSION_REASON], $meta[self::META_SUSPENSION_NOTE]);
        $service->update(['service_meta' => $meta ?: null]);
    }

    public function suspensionReasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_RESELLER_DISK_POOL_OVER => 'Total managed disk usage exceeded your package pool',
            self::REASON_RESELLER_USER_OVER => 'Hosted user count exceeded your package limit',
            self::REASON_PACKAGE_LIMIT => 'Active service count exceeded your package slot limit',
            default => 'Reseller package subscription is unpaid or overdue',
        };
    }
}

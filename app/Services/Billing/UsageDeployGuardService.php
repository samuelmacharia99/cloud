<?php

namespace App\Services\Billing;

use App\Enums\BillingMode;
use App\Enums\ServiceStatus;
use App\Models\DomainDeploymentLock;
use App\Models\Service;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Anti-abuse guards for free-deploy / usage-billed application hosting.
 */
class UsageDeployGuardService
{
    public function __construct(
        private UsageBillingProfileService $profile,
    ) {}

    public function normalizeFqdn(string $fqdn): string
    {
        return strtolower(rtrim(trim($fqdn), '.'));
    }

    /**
     * @throws ValidationException
     */
    public function assertCanDeploy(User $user, string $fqdn): void
    {
        if (! $this->profile->shouldUseUsageBillingForCustomer($user)) {
            return;
        }

        $fqdn = $this->normalizeFqdn($fqdn);
        $this->assertDomainAvailable($user, $fqdn);
        $this->assertWithinConcurrentLimit($user);
        $this->assertWithinDeployRateLimit($user);
        $this->assertNotInAccountCoolDown($user);
    }

    /**
     * @throws ValidationException
     */
    public function assertDomainAvailable(User $user, string $fqdn): void
    {
        $fqdn = $this->normalizeFqdn($fqdn);

        $lock = DomainDeploymentLock::query()->where('fqdn', $fqdn)->first();
        if ($lock && $lock->isBlocking()) {
            $msg = $lock->user_id === $user->id
                ? 'This domain is already linked to an application in your account. Terminate it (and wait out the cool-down) before deploying again on the same domain.'
                : 'This domain is already reserved for another application and cannot be used right now.';

            throw ValidationException::withMessages(['primary_domain' => $msg]);
        }

        // Legacy services without a lock row.
        $conflict = Service::query()
            ->whereIn('status', [
                ServiceStatus::Pending->value,
                ServiceStatus::Provisioning->value,
                ServiceStatus::Active->value,
                ServiceStatus::Suspended->value,
            ])
            ->where(function ($q) use ($fqdn) {
                $q->where('service_meta->primary_domain', $fqdn)
                    ->orWhere('service_meta->domain', $fqdn)
                    ->orWhere('service_meta->mailcow_domain', $fqdn);
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'primary_domain' => 'This domain is already used by an active application.',
            ]);
        }
    }

    /**
     * Allow binding a hostname to this service when the lock (if any) belongs to it.
     *
     * @throws ValidationException
     */
    public function assertDomainAvailableForBind(User $user, string $fqdn, Service $service): void
    {
        $fqdn = $this->normalizeFqdn($fqdn);

        $lock = DomainDeploymentLock::query()->where('fqdn', $fqdn)->first();
        if ($lock && $lock->isBlocking()) {
            if ((int) $lock->service_id === (int) $service->id) {
                return;
            }

            $msg = $lock->user_id === $user->id
                ? 'This domain is already linked to another application in your account.'
                : 'This domain is already reserved for another application and cannot be used right now.';

            throw ValidationException::withMessages(['domain' => $msg]);
        }

        $conflict = Service::query()
            ->where('id', '!=', $service->id)
            ->whereIn('status', [
                ServiceStatus::Pending->value,
                ServiceStatus::Provisioning->value,
                ServiceStatus::Active->value,
                ServiceStatus::Suspended->value,
            ])
            ->where(function ($q) use ($fqdn) {
                $q->where('service_meta->primary_domain', $fqdn)
                    ->orWhere('service_meta->domain', $fqdn)
                    ->orWhere('service_meta->mailcow_domain', $fqdn);
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'domain' => 'This domain is already used by an active application.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertWithinConcurrentLimit(User $user): void
    {
        $max = (int) config('usage_billing.abuse.max_concurrent_apps', 1);
        if ($max <= 0) {
            return;
        }

        $paidHistory = $this->userHasPaidUsageInvoice($user);
        if ($paidHistory) {
            $max = (int) config('usage_billing.abuse.max_concurrent_apps_after_payment', 5);
        }

        $count = $this->activeContainerCount($user);
        if ($count >= $max) {
            throw ValidationException::withMessages([
                'primary_domain' => $paidHistory
                    ? "You already have {$count} active application(s). Limit is {$max}."
                    : 'You can run one free application until your first usage invoice is paid. Upgrade or wait until then to deploy another.',
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertWithinDeployRateLimit(User $user): void
    {
        $perWeek = (int) config('usage_billing.abuse.max_deploys_per_week', 3);
        if ($perWeek <= 0) {
            return;
        }

        $recent = Service::query()
            ->where('user_id', $user->id)
            ->where('billing_mode', BillingMode::Usage)
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        if ($recent >= $perWeek) {
            throw ValidationException::withMessages([
                'primary_domain' => "Deploy limit reached ({$perWeek} new apps per week). Contact support if you need more.",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertNotInAccountCoolDown(User $user): void
    {
        $days = (int) config('usage_billing.abuse.account_cool_down_days_after_terminate', 0);
        if ($days <= 0) {
            return;
        }

        $recentTerminate = Service::query()
            ->where('user_id', $user->id)
            ->where('billing_mode', BillingMode::Usage)
            ->whereNotNull('terminate_date')
            ->where('terminate_date', '>=', now()->subDays($days))
            ->exists();

        if ($recentTerminate && $this->activeContainerCount($user) === 0) {
            // Only block brand-new deploys right after full terminate wave if configured.
            // Domain-level cool-down already covers same-domain reuse.
        }
    }

    public function qualifiesForFreePeriod(User $user): bool
    {
        if (! config('usage_billing.free_period.enabled', true)) {
            return false;
        }

        $once = (bool) config('usage_billing.free_period.once_per_account', true);
        if (! $once) {
            return true;
        }

        return ! Service::query()
            ->where('user_id', $user->id)
            ->where('billing_mode', BillingMode::Usage)
            ->where(function ($q) {
                $q->where('service_meta->usage_free_period_granted', true)
                    ->orWhere('service_meta->usage_free_period_granted', '1')
                    ->orWhere('service_meta->usage_free_period_granted', 1);
            })
            ->exists();
    }

    public function freePeriodDays(): int
    {
        return max(1, (int) config('usage_billing.free_period.days', 30));
    }

    public function lockDomain(string $fqdn, User $user, Service $service): DomainDeploymentLock
    {
        $fqdn = $this->normalizeFqdn($fqdn);

        return DomainDeploymentLock::query()->updateOrCreate(
            ['fqdn' => $fqdn],
            [
                'user_id' => $user->id,
                'service_id' => $service->id,
                'status' => DomainDeploymentLock::STATUS_LOCKED,
                'locked_at' => now(),
                'cool_down_until' => null,
            ]
        );
    }

    public function beginCoolDownForService(Service $service): void
    {
        $days = (int) config('usage_billing.abuse.domain_cool_down_days', 30);
        $fqdn = $this->primaryDomainForService($service);

        $lock = DomainDeploymentLock::query()
            ->where(function ($q) use ($service, $fqdn) {
                $q->where('service_id', $service->id);
                if ($fqdn) {
                    $q->orWhere('fqdn', $fqdn);
                }
            })
            ->first();

        if (! $lock) {
            if (! $fqdn) {
                return;
            }
            $lock = new DomainDeploymentLock([
                'fqdn' => $fqdn,
                'user_id' => $service->user_id,
            ]);
        }

        $lock->fill([
            'fqdn' => $fqdn ?: $lock->fqdn,
            'user_id' => $service->user_id,
            'service_id' => $service->id,
            'status' => DomainDeploymentLock::STATUS_COOLDOWN,
            'cool_down_until' => now()->addDays(max(0, $days)),
        ]);
        $lock->save();
    }

    public function primaryDomainForService(Service $service): ?string
    {
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $fqdn = $meta['primary_domain'] ?? $meta['domain'] ?? $meta['mailcow_domain'] ?? null;

        return $fqdn ? $this->normalizeFqdn((string) $fqdn) : null;
    }

    public function activeContainerCount(User $user): int
    {
        return Service::query()
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('billing_mode', BillingMode::Usage)
                    ->orWhereHas('product', fn ($p) => $p->where('type', 'container_hosting'));
            })
            ->whereIn('status', [
                ServiceStatus::Pending->value,
                ServiceStatus::Provisioning->value,
                ServiceStatus::Active->value,
                ServiceStatus::Suspended->value,
            ])
            ->where(function ($q) {
                $q->where('provisioning_driver_key', 'container')
                    ->orWhereHas('product', fn ($p) => $p->where('type', 'container_hosting'));
            })
            ->count();
    }

    private function userHasPaidUsageInvoice(User $user): bool
    {
        return Service::query()
            ->where('user_id', $user->id)
            ->where('billing_mode', BillingMode::Usage)
            ->whereHas('invoiceItems.invoice', function ($q) {
                $q->whereIn('status', ['paid', 'completed']);
            })
            ->exists();
    }
}

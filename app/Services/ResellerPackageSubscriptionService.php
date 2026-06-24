<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ResellerPackage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ResellerPackageSubscriptionService
{
    public const PACKAGE_META_PREFIX = '[package:';

    public const FROM_PACKAGE_META_PREFIX = '[from_package:';

    public const UPGRADE_META = '[upgrade:1]';

    public const DOWNGRADE_META = '[downgrade:1]';

    public const ACTIVATED_META = '[activated:1]';

    public function packageIdFromInvoice(Invoice $invoice): ?int
    {
        if (! preg_match('/\[package:(\d+)\]/', $invoice->notes ?? '', $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    public function isRenewalInvoice(Invoice $invoice): bool
    {
        return str_contains($invoice->notes ?? '', 'Renewal');
    }

    public function isUpgradeInvoice(Invoice $invoice): bool
    {
        return str_contains($invoice->notes ?? '', self::UPGRADE_META);
    }

    public function isDowngradeInvoice(Invoice $invoice): bool
    {
        return str_contains($invoice->notes ?? '', self::DOWNGRADE_META);
    }

    public function isPackageDowngrade(User $user, ResellerPackage $package): bool
    {
        $current = $user->resellerPackage;

        if (! $current || ! $user->reseller_package_id || $current->id === $package->id) {
            return false;
        }

        if ($current->billing_cycle !== $package->billing_cycle) {
            return false;
        }

        return (float) $package->price < (float) $current->price;
    }

    public function isPackageUpgrade(User $user, ResellerPackage $package): bool
    {
        $current = $user->resellerPackage;

        if (! $current || ! $user->reseller_package_id || $current->id === $package->id) {
            return false;
        }

        if ($current->billing_cycle !== $package->billing_cycle) {
            return false;
        }

        return (float) $package->price > (float) $current->price;
    }

    /**
     * Prorated amount to move to a higher tier for the remainder of the current billing period.
     *
     * @return array{
     *     amount: float,
     *     price_diff: float,
     *     days_remaining: int,
     *     cycle_days: int,
     *     expires_at: ?Carbon
     * }
     */
    public function upgradeQuote(User $user, ResellerPackage $targetPackage): array
    {
        $current = $user->resellerPackage;

        if (! $current) {
            throw new \InvalidArgumentException('No active reseller package to upgrade from.');
        }

        if ($current->billing_cycle !== $targetPackage->billing_cycle) {
            throw new \InvalidArgumentException('Upgrade must stay on the same billing cycle (monthly or annual).');
        }

        $priceDiff = max(0, (float) $targetPackage->price - (float) $current->price);
        $cycleDays = $this->cycleDaysFor($targetPackage);
        $expiresAt = $user->package_expires_at?->copy();

        if ($expiresAt && $expiresAt->isFuture()) {
            $daysRemaining = max(1, (int) now()->startOfDay()->diffInDays($expiresAt->startOfDay(), false));
            $amount = round($priceDiff * ($daysRemaining / $cycleDays), 2);
        } else {
            $daysRemaining = $cycleDays;
            $amount = round($priceDiff, 2);
        }

        return [
            'amount' => max(0, $amount),
            'price_diff' => $priceDiff,
            'days_remaining' => $daysRemaining,
            'cycle_days' => $cycleDays,
            'expires_at' => $expiresAt,
        ];
    }

    public function createSubscriptionInvoice(User $user, ResellerPackage $package, bool $renewal = false): Invoice
    {
        $isUpgrade = ! $renewal && $this->isPackageUpgrade($user, $package);
        $isDowngrade = ! $renewal && $this->isPackageDowngrade($user, $package);
        $schedule = app(InvoiceGenerationScheduleService::class);

        if ($isUpgrade) {
            $quote = $this->upgradeQuote($user, $package);
            $current = $user->resellerPackage;
            $label = sprintf(
                'Reseller Package Upgrade: %s → %s (prorated, %d of %d days remaining)',
                $current->name,
                $package->name,
                $quote['days_remaining'],
                $quote['cycle_days'],
            );
            $amounts = $this->calculateAmounts($quote['amount']);
            $dueDate = now()->copy()->startOfDay()->addDays($schedule->resellerPackageAdvanceDays());
            $notes = trim($label.' '.self::UPGRADE_META.' '.self::FROM_PACKAGE_META_PREFIX.$current->id.'] '.self::PACKAGE_META_PREFIX.$package->id.']');
        } elseif ($isDowngrade) {
            $current = $user->resellerPackage;
            $label = sprintf(
                'Reseller Package Downgrade: %s → %s (no charge, limits apply immediately)',
                $current->name,
                $package->name,
            );
            $amounts = $this->calculateAmounts(0);
            $dueDate = now()->copy()->startOfDay()->addDays($schedule->resellerPackageAdvanceDays());
            $notes = trim($label.' '.self::DOWNGRADE_META.' '.self::FROM_PACKAGE_META_PREFIX.$current->id.'] '.self::PACKAGE_META_PREFIX.$package->id.']');
        } else {
            $label = $renewal
                ? "Reseller Package Renewal: {$package->name} ({$package->billing_cycle})"
                : "Reseller Package: {$package->name} ({$package->billing_cycle})";

            $amounts = $this->calculateAmounts((float) $package->price);
            $dueDate = $renewal && $user->package_expires_at
                ? $schedule->resellerPackageRenewalDueDate($user)
                : now()->copy()->startOfDay()->addDays($schedule->resellerPackageAdvanceDays());
            $notes = $label.' '.self::PACKAGE_META_PREFIX.$package->id.']';
        }

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'type' => 'reseller_subscription',
            'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
            'status' => 'unpaid',
            'due_date' => $dueDate,
            'subtotal' => $amounts['subtotal'],
            'tax' => $amounts['tax'],
            'total' => $amounts['total'],
            'notes' => $notes,
        ]);

        if ($renewal) {
            app(ResellerDiskUsageBillingService::class)->addUsageItemsToSubscriptionInvoice($invoice, $user, true);
        }

        app(ResellerSubscriptionAutoPayService::class)->attempt($invoice);

        if (! $invoice->fresh()->isPaid()) {
            app(NotificationService::class)->notifyResellerSubscriptionInvoice($invoice->fresh(['user']));
        }

        return $invoice->fresh(['items']);
    }

    /**
     * @return array{subtotal: float, tax: float, total: float}
     */
    public function calculateAmounts(float $subtotal): array
    {
        $breakdown = TaxService::calculateResellerSubscription($subtotal);

        return [
            'subtotal' => $breakdown['subtotal'],
            'tax' => $breakdown['tax'],
            'total' => $breakdown['total'],
        ];
    }

    /**
     * Preview package expiry after a successful renewal payment.
     */
    public function previewRenewalExpiry(User $user): ?Carbon
    {
        $package = $user->resellerPackage;
        if (! $package) {
            return null;
        }

        $base = $user->package_expires_at && $user->package_expires_at->isFuture()
            ? $user->package_expires_at->copy()
            : now();

        return $this->calculateExpiryFrom($base, $package);
    }

    public function pendingSubscriptionInvoice(User $user, ?ResellerPackage $package = null): ?Invoice
    {
        $query = Invoice::query()
            ->where('user_id', $user->id)
            ->where('type', 'reseller_subscription')
            ->whereIn('status', ['unpaid', 'overdue']);

        if ($package) {
            $query->where('notes', 'like', '%'.self::PACKAGE_META_PREFIX.$package->id.']%');
        }

        return $query->latest()->first();
    }

    /**
     * Open renewal invoice for the current package billing period only.
     * Upgrade or stale subscription invoices must not block cron renewal generation.
     */
    public function pendingRenewalSubscriptionInvoice(User $user): ?Invoice
    {
        $schedule = app(InvoiceGenerationScheduleService::class);
        $renewalDue = $schedule->resellerPackageRenewalDueDate($user);

        if (! $renewalDue) {
            return null;
        }

        $dueDate = $renewalDue->toDateString();

        return Invoice::query()
            ->where('user_id', $user->id)
            ->where('type', 'reseller_subscription')
            ->whereIn('status', ['unpaid', 'overdue'])
            ->where('notes', 'like', '%Renewal%')
            ->whereDate('due_date', $dueDate)
            ->latest()
            ->first();
    }

    /**
     * Create a package renewal invoice when inside the billing window.
     */
    public function createRenewalInvoiceIfNeeded(User $user, bool $force = false): Invoice
    {
        $package = $user->resellerPackage;
        if (! $package) {
            throw new \InvalidArgumentException('Reseller has no package assigned.');
        }

        $schedule = app(InvoiceGenerationScheduleService::class);

        if (! $force && ! $schedule->isResellerPackageDueForRenewalInvoice($user)) {
            throw new \InvalidArgumentException('Reseller is not yet due for a renewal invoice.');
        }

        $pending = $this->pendingRenewalSubscriptionInvoice($user);
        if ($pending) {
            throw new \InvalidArgumentException('An unpaid renewal invoice already exists for this billing period.');
        }

        return $this->createSubscriptionInvoice($user, $package, renewal: true);
    }

    public function activateFromPaidInvoice(Invoice $invoice): void
    {
        if ($invoice->type !== 'reseller_subscription' || ! $invoice->isPaid()) {
            return;
        }

        if (str_contains($invoice->notes ?? '', self::ACTIVATED_META)) {
            return;
        }

        $package = ResellerPackage::find($this->packageIdFromInvoice($invoice));
        if (! $package) {
            return;
        }

        $user = $invoice->user;
        if (! $user) {
            return;
        }

        if ($this->isRenewalInvoice($invoice)) {
            $this->extendSubscription($user, $package);
        } elseif ($this->isUpgradeInvoice($invoice)) {
            $this->applyUpgrade($user, $package);
        } elseif ($this->isDowngradeInvoice($invoice)) {
            $this->applyDowngrade($user, $package);
        } else {
            $this->activateSubscription($user, $package);
        }

        $invoice->update([
            'notes' => trim(($invoice->notes ?? '').' '.self::ACTIVATED_META),
        ]);

        app(ResellerEnforcementService::class)->handleSubscriptionPaid($user->fresh());
    }

    public function applyUpgrade(User $user, ResellerPackage $package): void
    {
        $updates = [
            'reseller_package_id' => $package->id,
        ];

        // Keep the existing renewal date when upgrading mid-cycle (already paid through that date).
        if (! $user->package_expires_at || $user->package_expires_at->isPast()) {
            $updates['package_subscribed_at'] = $user->package_subscribed_at ?? now();
            $updates['package_expires_at'] = $this->calculateExpiryFrom(now(), $package);
        }

        $user->update($updates);
    }

    public function applyDowngrade(User $user, ResellerPackage $package): void
    {
        $this->applyUpgrade($user, $package);
    }

    public function activateSubscription(User $user, ResellerPackage $package): void
    {
        $user->update([
            'reseller_package_id' => $package->id,
            'package_subscribed_at' => $user->package_subscribed_at ?? now(),
            'package_expires_at' => $this->calculateExpiryFrom(now(), $package),
        ]);
    }

    public function extendSubscription(User $user, ResellerPackage $package): void
    {
        $base = $user->package_expires_at && $user->package_expires_at->isFuture()
            ? $user->package_expires_at->copy()
            : now();

        $user->update([
            'reseller_package_id' => $package->id,
            'package_expires_at' => $this->calculateExpiryFrom($base, $package),
        ]);
    }

    private function calculateExpiryFrom(\DateTimeInterface $from, ResellerPackage $package): Carbon
    {
        $date = Carbon::parse($from);

        return $package->billing_cycle === 'annually'
            ? $date->copy()->addYear()
            : $date->copy()->addMonth();
    }

    private function cycleDaysFor(ResellerPackage $package): int
    {
        return $package->billing_cycle === 'annually' ? 365 : 30;
    }
}

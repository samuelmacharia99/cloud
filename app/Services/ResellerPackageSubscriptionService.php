<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ResellerPackage;
use App\Models\User;
use App\Services\Billing\InvoiceNumberService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResellerPackageSubscriptionService
{
    public const PACKAGE_META_PREFIX = '[package:';

    public const FROM_PACKAGE_META_PREFIX = '[from_package:';

    public const UPGRADE_META = '[upgrade:1]';

    public const DOWNGRADE_META = '[downgrade:1]';

    public const ACTIVATED_META = '[activated:1]';

    public const SUPERSEDED_META_PREFIX = '[superseded_by:';

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

    public function subscriptionLabelFromNotes(?string $notes): ?string
    {
        if (! $notes) {
            return null;
        }

        $clean = trim(preg_replace('/\[[^\]]+\]/', '', $notes) ?? '');

        return $clean !== '' ? $clean : null;
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
            $lineAmount = $quote['amount'];
            $dueDate = now()->copy()->startOfDay()->addDays($schedule->resellerPackageAdvanceDays());
            $notes = trim($label.' '.self::UPGRADE_META.' '.self::FROM_PACKAGE_META_PREFIX.$current->id.'] '.self::PACKAGE_META_PREFIX.$package->id.']');
        } elseif ($isDowngrade) {
            $current = $user->resellerPackage;
            $label = sprintf(
                'Reseller Package Downgrade: %s → %s (no charge, limits apply immediately)',
                $current->name,
                $package->name,
            );
            $lineAmount = 0;
            $dueDate = now()->copy()->startOfDay()->addDays($schedule->resellerPackageAdvanceDays());
            $notes = trim($label.' '.self::DOWNGRADE_META.' '.self::FROM_PACKAGE_META_PREFIX.$current->id.'] '.self::PACKAGE_META_PREFIX.$package->id.']');
        } else {
            $label = $renewal
                ? "Reseller Package Renewal: {$package->name} ({$package->billing_cycle})"
                : "Reseller Package: {$package->name} ({$package->billing_cycle})";

            $lineAmount = (float) $package->price;
            $dueDate = $renewal && $user->package_expires_at
                ? $schedule->resellerPackageRenewalDueDate($user)
                : now()->copy()->startOfDay()->addDays($schedule->resellerPackageAdvanceDays());
            $notes = $label.' '.self::PACKAGE_META_PREFIX.$package->id.']';
        }

        $invoice = DB::transaction(function () use ($user, $dueDate, $notes, $label, $lineAmount, $renewal) {
            $invoice = app(InvoiceNumberService::class)->createWithUniqueNumber(
                fn (string $number) => Invoice::create([
                    'user_id' => $user->id,
                    'type' => 'reseller_subscription',
                    'invoice_number' => $number,
                    'status' => 'unpaid',
                    'due_date' => $dueDate,
                    'subtotal' => 0,
                    'tax' => 0,
                    'total' => 0,
                    'notes' => $notes,
                ]),
            );

            $this->appendPackageLineItem($invoice, $label, $lineAmount);

            if ($renewal) {
                app(ResellerDiskUsageBillingService::class)->addUsageItemsToSubscriptionInvoice($invoice, $user, true);
            }

            if (! $renewal) {
                $this->cancelSupersededSubscriptionInvoices($user, $invoice);
            }

            return $invoice->fresh();
        });

        app(ResellerSubscriptionAutoPayService::class)->attempt($invoice);

        if (! $invoice->fresh()->isPaid()) {
            app(NotificationService::class)->notifyResellerSubscriptionInvoice($invoice->fresh(['user']));
        }

        return $invoice->fresh(['items']);
    }

    /**
     * Reuse an unpaid invoice for this package, or create one. Leftover open package invoices are cancelled.
     */
    public function issueOrReuseSubscriptionInvoice(User $user, ResellerPackage $package): Invoice
    {
        $existing = $this->pendingSubscriptionInvoice($user, $package);
        if ($existing) {
            $this->cancelSupersededSubscriptionInvoices($user, $existing);

            return $existing->fresh(['items']);
        }

        return $this->createSubscriptionInvoice($user, $package);
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

    public function pendingPlanChangeInvoice(User $user): ?Invoice
    {
        return Invoice::query()
            ->where('user_id', $user->id)
            ->where('type', 'reseller_subscription')
            ->whereIn('status', ['unpaid', 'overdue'])
            ->where(function ($query) {
                $query->where('notes', 'like', '%'.self::UPGRADE_META.'%')
                    ->orWhere('notes', 'like', '%'.self::DOWNGRADE_META.'%');
            })
            ->latest()
            ->first();
    }

    /**
     * Cancel leftover unpaid package invoices so only $keep stays collectible.
     *
     * @return Collection<int, Invoice>
     */
    public function cancelSupersededSubscriptionInvoices(User $user, Invoice $keep): Collection
    {
        return DB::transaction(function () use ($user, $keep) {
            $open = Invoice::query()
                ->where('user_id', $user->id)
                ->where('type', 'reseller_subscription')
                ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Overdue->value])
                ->whereKeyNot($keep->id)
                ->lockForUpdate()
                ->get();

            $cancelled = collect();

            foreach ($open as $invoice) {
                if ($this->invoiceHasCompletedPayment($invoice)) {
                    Log::warning('Left open package invoice in place because it already has a completed payment', [
                        'reseller_id' => $user->id,
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'kept_invoice_number' => $keep->invoice_number,
                    ]);

                    continue;
                }

                $this->releaseWalletHoldOnInvoice($user, $invoice);

                $invoice->update([
                    'status' => InvoiceStatus::Cancelled->value,
                    'wallet_amount_applied' => 0,
                    'notes' => trim(($invoice->notes ?? '').' '.self::SUPERSEDED_META_PREFIX.$keep->invoice_number.'] Cancelled automatically because a newer package invoice replaced it.'),
                ]);

                $cancelled->push($invoice->fresh());

                AdminActivityService::log(
                    'reseller.package_invoice_superseded',
                    "Cancelled invoice {$invoice->invoice_number} because package invoice {$keep->invoice_number} replaced it",
                    $invoice,
                    [
                        'reseller_id' => $user->id,
                        'superseded_by' => $keep->invoice_number,
                    ],
                );
            }

            return $cancelled;
        });
    }

    /**
     * Open renewal invoice for the current package billing period only.
     * An unpaid upgrade invoice supersedes renewal generation until it is paid or cancelled.
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

        if ($this->pendingPlanChangeInvoice($user)) {
            throw new \InvalidArgumentException('Pay or cancel the open package upgrade invoice before generating a renewal.');
        }

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
            $this->issueReplacementRenewalIfDue($user->fresh());
        } elseif ($this->isDowngradeInvoice($invoice)) {
            $this->applyDowngrade($user, $package);
            $this->issueReplacementRenewalIfDue($user->fresh());
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

    private function issueReplacementRenewalIfDue(User $user): void
    {
        $schedule = app(InvoiceGenerationScheduleService::class);

        if (! $schedule->isResellerPackageDueForRenewalInvoice($user)) {
            return;
        }

        if ($this->pendingRenewalSubscriptionInvoice($user)) {
            return;
        }

        try {
            $this->createRenewalInvoiceIfNeeded($user);
        } catch (\InvalidArgumentException) {
            // Not due, or another open renewal already exists.
        }
    }

    private function invoiceHasCompletedPayment(Invoice $invoice): bool
    {
        return $invoice->payments()
            ->where('status', PaymentStatus::Completed->value)
            ->exists();
    }

    private function releaseWalletHoldOnInvoice(User $user, Invoice $invoice): void
    {
        $applied = round((float) ($invoice->wallet_amount_applied ?? 0), 2);
        if ($applied <= 0) {
            return;
        }

        app(ResellerWalletService::class)->creditInvoiceRefund(
            $user,
            $applied,
            "Released from cancelled invoice {$invoice->invoice_number}",
            $invoice->id,
        );
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

    private function appendPackageLineItem(Invoice $invoice, string $description, float $amount): void
    {
        $breakdown = TaxService::calculateResellerSubscription($amount);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => null,
            'product_type' => 'reseller_package',
            'description' => $description,
            'quantity' => 1,
            'unit_price' => $amount,
            'amount' => $amount,
        ]);

        $invoice->increment('subtotal', $breakdown['subtotal']);
        $invoice->increment('tax', $breakdown['tax']);
        $invoice->increment('total', $breakdown['total']);
    }
}

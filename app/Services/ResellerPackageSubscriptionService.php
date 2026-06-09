<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ResellerPackage;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ResellerPackageSubscriptionService
{
    public const PACKAGE_META_PREFIX = '[package:';

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

    public function createSubscriptionInvoice(User $user, ResellerPackage $package, bool $renewal = false): Invoice
    {
        $label = $renewal
            ? "Reseller Package Renewal: {$package->name} ({$package->billing_cycle})"
            : "Reseller Package: {$package->name} ({$package->billing_cycle})";

        $amounts = $this->calculateAmounts((float) $package->price);
        $schedule = app(InvoiceGenerationScheduleService::class);

        $dueDate = $renewal && $user->package_expires_at
            ? $schedule->resellerPackageRenewalDueDate($user)
            : now()->copy()->startOfDay()->addDays($schedule->resellerPackageAdvanceDays());

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'type' => 'reseller_subscription',
            'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
            'status' => 'unpaid',
            'due_date' => $dueDate,
            'subtotal' => $amounts['subtotal'],
            'tax' => $amounts['tax'],
            'total' => $amounts['total'],
            'notes' => $label.' '.self::PACKAGE_META_PREFIX.$package->id.']',
        ]);

        app(ResellerDiskUsageBillingService::class)->addUsageItemsToSubscriptionInvoice($invoice, $user, $renewal);

        app(ResellerSubscriptionAutoPayService::class)->attempt($invoice);

        return $invoice->fresh(['items']);
    }

    /**
     * @return array{subtotal: float, tax: float, total: float}
     */
    public function calculateAmounts(float $subtotal): array
    {
        $taxEnabled = in_array(Setting::getValue('tax_enabled'), ['1', 'true', true], true);
        $taxRate = (float) Setting::getValue('tax_rate', 0);
        $tax = $taxEnabled ? round($subtotal * $taxRate / 100, 2) : 0;

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
        ];
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
        } else {
            $this->activateSubscription($user, $package);
        }

        $invoice->update([
            'notes' => trim(($invoice->notes ?? '').' '.self::ACTIVATED_META),
        ]);

        app(ResellerEnforcementService::class)->handleSubscriptionPaid($user->fresh());
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
}

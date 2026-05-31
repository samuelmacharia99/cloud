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

        return Invoice::create([
            'user_id' => $user->id,
            'type' => 'reseller_subscription',
            'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
            'status' => 'unpaid',
            'due_date' => now()->addDays(7),
            'subtotal' => $package->price,
            'tax' => 0,
            'total' => $package->price,
            'notes' => $label.' '.self::PACKAGE_META_PREFIX.$package->id.']',
        ]);
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

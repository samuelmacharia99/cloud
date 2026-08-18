<?php

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Auto-renew requires prepaid cover (customer credits or reseller wallet)
 * equal to this domain plus every other auto-renew domain on the same payer.
 * At invoice time, that prepaid balance is applied in full — no partial auto-pay.
 */
class DomainAutoRenewService
{
    public function __construct(
        private DomainRenewalService $renewals,
        private InvoiceGenerationScheduleService $schedule,
        private InvoiceSettlementService $settlement,
        private ResellerWalletService $wallets,
        private ResellerInvoicePaymentService $invoicePayments,
    ) {}

    public function isEligible(Domain $domain): bool
    {
        $domain->loadMissing('user');

        if ($domain->isDnsManaged() || $domain->status !== 'active' || ! $domain->expires_at) {
            return false;
        }

        return $domain->user instanceof User;
    }

    public function renewalCharge(Domain $domain, ?User $payer = null, int $years = 1): float
    {
        $domain->loadMissing(['user', 'domainExtension']);
        $payer ??= $domain->user;

        if (! $payer instanceof User || ! $domain->domainExtension) {
            return 0.0;
        }

        $pricing = $domain->domainExtension->getPricingForUser($payer, $years);
        if (! $pricing) {
            return 0.0;
        }

        $net = (float) ($pricing->renewal_price ?? $pricing->price ?? 0);
        if ($net <= 0) {
            return 0.0;
        }

        $tax = $payer->is_reseller
            ? TaxService::calculateResellerWholesale($net)
            : TaxService::calculateForUser($net, $payer);

        return round((float) $tax['total'], 2);
    }

    public function prepaidBalance(User $payer): float
    {
        if ($payer->is_reseller) {
            return round((float) $this->wallets->getOrCreate($payer)->balance, 2);
        }

        return round(CreditService::getAvailableBalance($payer), 2);
    }

    public function committedRenewalCharge(User $payer, ?Domain $including = null): float
    {
        $domains = Domain::query()
            ->where('user_id', $payer->id)
            ->where('auto_renew', true)
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where(function ($query) {
                $query->whereNull('type')->orWhere('type', '!=', 'dns');
            })
            ->with(['user', 'domainExtension'])
            ->get();

        if ($including && ! $domains->contains(fn (Domain $domain) => $domain->id === $including->id)) {
            $domains->push($including);
        }

        return round($domains->sum(fn (Domain $domain) => $this->renewalCharge($domain, $payer)), 2);
    }

    public function assertCanEnable(Domain $domain, User $payer): void
    {
        if (! $this->isEligible($domain)) {
            throw new \InvalidArgumentException('This domain cannot use auto-renewal.');
        }

        $charge = $this->renewalCharge($domain, $payer);
        if ($charge <= 0) {
            throw new \InvalidArgumentException('No renewal pricing is configured for this domain extension.');
        }

        $required = $this->committedRenewalCharge($payer, $domain);
        $available = $this->prepaidBalance($payer);

        if ($available + 0.001 < $required) {
            throw new InsufficientFundsException($required, $available);
        }
    }

    public function setEnabled(Domain $domain, bool $enabled, User $actor, bool $enforceBalance = true): void
    {
        if (! $enabled) {
            $domain->update(['auto_renew' => false]);

            return;
        }

        $payer = $domain->user;
        if (! $payer instanceof User) {
            throw new \InvalidArgumentException('Domain has no owner.');
        }

        if ($enforceBalance && ! $actor->is_admin) {
            $this->assertCanEnable($domain, $payer);
        }

        $domain->update(['auto_renew' => true]);
    }

    /**
     * Pay an open renewal invoice from prepaid balance when auto-renew is on.
     */
    public function attemptAutoPay(Invoice $invoice, Domain $domain): bool
    {
        $domain->refresh();
        $invoice->refresh();

        if (! $domain->auto_renew) {
            return false;
        }

        if ($invoice->isPaid()) {
            return true;
        }

        if ($invoice->isFullyPaid()) {
            return $this->settlement->settleFullyPaid($invoice);
        }

        $payer = $invoice->user;
        if (! $payer instanceof User) {
            return false;
        }

        try {
            if ($payer->is_reseller) {
                return $this->payFromWallet($invoice, $payer);
            }

            return $this->settlement->settleFromCredits($invoice);
        } catch (\Throwable $e) {
            Log::error('Domain auto-renew prepaid settlement failed', [
                'invoice_id' => $invoice->id,
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function startScheduledRenewal(Domain $domain, int $years, int $paymentDays): DomainRenewalOrder
    {
        $domain->loadMissing(['user', 'domainExtension']);
        $owner = $domain->user;

        if (! $owner instanceof User) {
            throw new \RuntimeException('Domain has no owner.');
        }

        if ($this->schedule->isResellerManagedDomain($domain)) {
            $resellerId = $domain->reseller_id ?: $owner->reseller_id;
            $reseller = User::query()->find($resellerId);
            if (! $reseller instanceof User || ! $reseller->is_reseller) {
                throw new \RuntimeException('Reseller-managed domain is missing its reseller.');
            }

            $wholesale = $this->renewals->wholesaleRenewalAmount($domain, $years);
            $pricing = $domain->domainExtension?->getPricingForUser($owner, $years);
            $retail = (float) ($pricing->renewal_price ?? $pricing->price ?? 0);
            if ($retail <= 0) {
                throw new \RuntimeException('No retail renewal pricing for this domain.');
            }

            return $this->renewals->initiateManagedCustomerRenewal(
                $domain,
                $reseller,
                $owner,
                $years,
                $wholesale,
                $retail
            );
        }

        return $this->renewals->initiateRenewal($domain, $owner, $years, $paymentDays);
    }

    public function payOpenAutoRenewInvoices(): int
    {
        $paid = 0;

        $orders = DomainRenewalOrder::query()
            ->with(['domain.user', 'invoice.user', 'customerInvoice.user'])
            ->whereIn('status', ['pending', 'invoiced'])
            ->whereHas('domain', function ($query) {
                $query->where('auto_renew', true)
                    ->where('status', 'active');
            })
            ->get();

        foreach ($orders as $order) {
            $domain = $order->domain;
            $invoice = $order->customerInvoice ?? $order->invoice;
            if (! $domain || ! $invoice || $invoice->isPaid()) {
                continue;
            }

            if ($this->attemptAutoPay($invoice, $domain)) {
                $paid++;
            }
        }

        return $paid;
    }

    public function prepaidLabel(User $payer): string
    {
        return $payer->is_reseller ? 'wallet' : 'account credits';
    }

    private function payFromWallet(Invoice $invoice, User $reseller): bool
    {
        $amountDue = $this->invoicePayments->amountDue($invoice);
        if ($amountDue <= 0) {
            return $this->settlement->settleFullyPaid($invoice);
        }

        $wallet = $this->wallets->getOrCreate($reseller);
        if ((float) $wallet->balance + 0.001 < $amountDue) {
            Log::info('Domain auto-renew waiting for wallet top-up', [
                'invoice_id' => $invoice->id,
                'reseller_id' => $reseller->id,
                'wallet_balance' => $wallet->balance,
                'amount_due' => $amountDue,
            ]);

            return false;
        }

        $invoiceId = DB::transaction(function () use ($invoice, $reseller) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if (! $locked || $locked->isPaid()) {
                return null;
            }

            $amountDue = $this->invoicePayments->amountDue($locked);
            if ($amountDue <= 0) {
                return $locked->id;
            }

            $wallet = $this->wallets->getOrCreate($reseller);
            if ((float) $wallet->balance + 0.001 < $amountDue) {
                return null;
            }

            $this->invoicePayments->applyWallet($locked, $reseller, true);

            return $locked->id;
        });

        if (! $invoiceId) {
            return false;
        }

        $paidInvoice = Invoice::query()->find($invoiceId);

        return $paidInvoice ? $this->settlement->settleFullyPaid($paidInvoice) : false;
    }
}

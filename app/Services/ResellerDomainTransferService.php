<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

class ResellerDomainTransferService
{
    public function __construct(
        protected DatabaseManager $db,
        protected ResellerScopeService $scope,
    ) {}

    /**
     * Immediately move a domain between the reseller (or a managed customer) and another managed customer.
     */
    public function transferBetweenOwnedCustomers(Domain $domain, User $fromCustomer, User $toCustomer, User $reseller): Domain
    {
        if (! $this->resellerCanTransferFrom($reseller, $fromCustomer, $domain)) {
            throw new \InvalidArgumentException('Source customer is not managed by this reseller.');
        }

        if ((int) $toCustomer->reseller_id !== (int) $reseller->id) {
            throw new \InvalidArgumentException('Target customer is not managed by this reseller.');
        }

        if ((int) $domain->user_id !== (int) $fromCustomer->id) {
            throw new \InvalidArgumentException('Domain is not owned by the source customer.');
        }

        if ((int) $fromCustomer->id === (int) $toCustomer->id) {
            throw new \InvalidArgumentException('Domain is already assigned to this customer.');
        }

        return $this->db->transaction(function () use ($domain, $fromCustomer, $toCustomer, $reseller) {
            $notes = $domain->notes ?? [];
            if (! is_array($notes)) {
                $notes = [];
            }

            $notes[] = [
                'type' => 'domain_transfer',
                'from' => $fromCustomer->name,
                'to' => $toCustomer->name,
                'mode' => 'reseller_direct',
                'transferred_at' => now()->toIso8601String(),
            ];

            $domain->update([
                'user_id' => $toCustomer->id,
                // Keep the domain under this reseller even if the target row is missing reseller_id.
                'reseller_id' => $toCustomer->reseller_id ?: $reseller->id,
                'pending_transfer_to_user_id' => null,
                'transfer_token' => null,
                'transfer_requested_at' => null,
                'notes' => $notes,
            ]);

            return $domain->fresh();
        });
    }

    /**
     * Source may be the reseller (wholesale domain) or any customer the reseller manages.
     */
    private function resellerCanTransferFrom(User $reseller, User $fromCustomer, Domain $domain): bool
    {
        if ((int) $fromCustomer->id === (int) $reseller->id) {
            return true;
        }

        if ($this->scope->ownsCustomer($reseller, $fromCustomer)) {
            return true;
        }

        // Domain tagged to this reseller (admin-added / portfolio) even if user.reseller_id is stale.
        return (int) ($domain->reseller_id ?? 0) === (int) $reseller->id;
    }

    public function initiate(Domain $domain, User $fromCustomer, User $toCustomer, User $reseller): void
    {
        $this->db->transaction(function () use ($domain, $fromCustomer, $toCustomer, $reseller) {
            $token = (string) Str::uuid();

            $domain->update([
                'pending_transfer_to_user_id' => $toCustomer->id,
                'transfer_token' => $token,
                'transfer_requested_at' => now(),
            ]);

            if (blank($toCustomer->phone)) {
                return;
            }

            $message = "Domain transfer requested: {$domain->name}{$domain->extension}. Approve: "
                .route('customer.domains.inter-transfer.approval', $token);

            try {
                app('talksasa-sms-service')->sendSms($reseller, $toCustomer->phone, $message);
            } catch (\Throwable $e) {
                \Log::error("Failed to send domain transfer SMS to customer {$toCustomer->id}: {$e->getMessage()}");
            }
        });
    }

    public function approve(string $token, User $approvingUser): Domain
    {
        return $this->db->transaction(function () use ($token, $approvingUser) {
            $domain = Domain::where('transfer_token', $token)->firstOrFail();

            if ($domain->pending_transfer_to_user_id !== $approvingUser->id) {
                throw new \InvalidArgumentException('User not authorized to approve this transfer');
            }

            $fromCustomer = $domain->user;
            $toCustomer = $approvingUser;

            $notes = $domain->notes ?? [];
            if (! is_array($notes)) {
                $notes = [];
            }

            $notes[] = [
                'type' => 'domain_transfer',
                'from' => $fromCustomer->name,
                'to' => $toCustomer->name,
                'transferred_at' => now()->toIso8601String(),
            ];

            $domain->update([
                'user_id' => $toCustomer->id,
                'reseller_id' => $toCustomer->reseller_id,
                'pending_transfer_to_user_id' => null,
                'transfer_token' => null,
                'transfer_requested_at' => null,
                'notes' => $notes,
            ]);

            $message = "Domain {$domain->name} transfer to {$toCustomer->name} has been approved!";

            try {
                $reseller = $toCustomer->reseller;
                app('talksasa-sms-service')->sendSms($reseller, $reseller->phone, $message);
            } catch (\Throwable $e) {
                \Log::error("Failed to send approval SMS to reseller: {$e->getMessage()}");
            }

            return $domain;
        });
    }

    public function reject(string $token, User $rejectingUser): Domain
    {
        return $this->db->transaction(function () use ($token, $rejectingUser) {
            $domain = Domain::where('transfer_token', $token)->firstOrFail();

            if ($domain->pending_transfer_to_user_id !== $rejectingUser->id) {
                throw new \InvalidArgumentException('User not authorized to reject this transfer');
            }

            $fromCustomer = $domain->user;

            $domain->update([
                'pending_transfer_to_user_id' => null,
                'transfer_token' => null,
                'transfer_requested_at' => null,
            ]);

            $message = "Domain {$domain->name} transfer has been rejected.";

            try {
                $reseller = $fromCustomer->reseller;
                if ($reseller?->is_reseller) {
                    app('talksasa-sms-service')->sendSms($reseller, $fromCustomer->phone, $message);
                }
            } catch (\Throwable $e) {
                \Log::error("Failed to send rejection SMS to customer: {$e->getMessage()}");
            }

            return $domain;
        });
    }
}

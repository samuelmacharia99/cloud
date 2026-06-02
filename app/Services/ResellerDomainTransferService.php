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
    ) {}

    public function initiate(Domain $domain, User $fromCustomer, User $toCustomer, User $reseller): void
    {
        $this->db->transaction(function () use ($domain, $fromCustomer, $toCustomer, $reseller) {
            $token = Str::uuid();

            $domain->update([
                'pending_transfer_to_user_id' => $toCustomer->id,
                'transfer_token' => $token,
                'transfer_requested_at' => now(),
            ]);

            $message = "Domain transfer requested: {$domain->name}. Click to approve: " . route('domains.transfer.approval', $token);

            try {
                app('talksasa-sms-service')->sendSms($reseller, $toCustomer->phone, $message);
            } catch (\Exception $e) {
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
            if (!is_array($notes)) {
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
            } catch (\Exception $e) {
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
            } catch (\Exception $e) {
                \Log::error("Failed to send rejection SMS to customer: {$e->getMessage()}");
            }

            return $domain;
        });
    }
}

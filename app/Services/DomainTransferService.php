<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\User;
use App\Mail\DomainTransferInitiatedMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class DomainTransferService
{
    /**
     * Create a transfer request
     */
    public static function createTransferRequest(
        User $user,
        string $domainName,
        string $extension,
        string $eppCode,
        string $oldRegistrar,
        ?string $oldRegistrarUrl = null
    ): Domain {
        // Check if domain already exists for user
        $existing = Domain::where('user_id', $user->id)
            ->where('name', $domainName)
            ->where('extension', $extension)
            ->first();

        if ($existing) {
            throw new \Exception("Domain {$domainName}.{$extension} already exists in your account");
        }

        // Create domain transfer record
        $domain = Domain::create([
            'user_id' => $user->id,
            'name' => $domainName,
            'extension' => $extension,
            'type' => 'transfer',
            'status' => 'pending',
            'transfer_status' => 'pending',
            'epp_code' => $eppCode,
            'old_registrar' => $oldRegistrar,
            'old_registrar_url' => $oldRegistrarUrl,
            'transfer_notes' => "Transfer initiated on " . now()->format('Y-m-d H:i:s'),
        ]);

        // Log the transfer request
        Log::info('Domain transfer requested', [
            'user_id' => $user->id,
            'domain' => $domainName . '.' . $extension,
            'old_registrar' => $oldRegistrar,
        ]);

        return $domain;
    }

    /**
     * Initiate the transfer process (send authorization to old registrar)
     */
    public static function initiateTransfer(Domain $domain): bool
    {
        try {
            // Update status
            $domain->update([
                'transfer_status' => 'initiated',
                'transfer_initiated_at' => now(),
                'transfer_notes' => "Transfer initiated with {$domain->old_registrar} on " . now()->format('Y-m-d H:i:s'),
            ]);

            // Send email to user
            Mail::to($domain->user->email)->send(
                new DomainTransferInitiatedMail($domain)
            );

            // In real scenario, you would:
            // 1. Contact old registrar API to authorize transfer
            // 2. Send authorization email to domain owner
            // 3. Poll for authorization completion

            Log::info('Domain transfer initiated', [
                'domain_id' => $domain->id,
                'domain' => $domain->name . '.' . $domain->extension,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Domain transfer initiation failed', [
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Mark transfer as in progress (after old registrar authorizes)
     */
    public static function markInProgress(Domain $domain): bool
    {
        try {
            $domain->update([
                'transfer_status' => 'in_progress',
                'transfer_notes' => "Transfer in progress with {$domain->old_registrar}. Waiting for completion.",
            ]);

            Log::info('Domain transfer marked in progress', [
                'domain_id' => $domain->id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to mark transfer in progress', [
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Complete the transfer
     */
    public static function completeTransfer(Domain $domain, string $newRegistrar = 'Talksasa Cloud'): bool
    {
        try {
            $domain->update([
                'transfer_status' => 'completed',
                'transfer_completed_at' => now(),
                'status' => 'active',
                'registrar' => $newRegistrar,
                'registered_at' => now(),
                'expires_at' => now()->addYear(),
                'transfer_notes' => "Transfer completed on " . now()->format('Y-m-d H:i:s') . ". Domain is now registered with {$newRegistrar}.",
            ]);

            Log::info('Domain transfer completed', [
                'domain_id' => $domain->id,
                'new_registrar' => $newRegistrar,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to complete transfer', [
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Fail a transfer
     */
    public static function failTransfer(Domain $domain, string $reason): bool
    {
        try {
            $domain->update([
                'transfer_status' => 'failed',
                'status' => 'failed',
                'transfer_notes' => "Transfer failed: {$reason}",
            ]);

            Log::error('Domain transfer failed', [
                'domain_id' => $domain->id,
                'reason' => $reason,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to mark transfer as failed', [
                'domain_id' => $domain->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Cancel a transfer
     */
    public static function cancelTransfer(Domain $domain, string $reason = 'Cancelled by user'): bool
    {
        try {
            // Can only cancel if transfer hasn't completed or failed
            if ($domain->transfer_status === 'completed' || $domain->transfer_status === 'failed') {
                throw new \Exception("Cannot cancel a {$domain->transfer_status} transfer");
            }

            $domain->delete();

            Log::info('Domain transfer cancelled', [
                'domain_id' => $domain->id,
                'reason' => $reason,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cancel transfer', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get transfer instructions for user
     */
    public static function getTransferInstructions(Domain $domain): array
    {
        return [
            'step1' => 'Contact your current registrar (' . $domain->old_registrar . ')',
            'step2' => 'Request authorization for domain transfer',
            'step3' => 'Provide the EPP code: ' . substr($domain->epp_code, 0, 3) . '****' . substr($domain->epp_code, -3),
            'step4' => 'Wait for authorization (usually 3-5 business days)',
            'step5' => 'We will automatically complete the transfer once authorized',
            'registrar_url' => $domain->old_registrar_url,
        ];
    }

    /**
     * Get estimated completion date
     */
    public static function getEstimatedCompletionDate(Domain $domain): string
    {
        if ($domain->transfer_status === 'completed') {
            return 'Completed on ' . $domain->transfer_completed_at->format('F d, Y');
        }

        if ($domain->transfer_status === 'failed') {
            return 'Transfer failed';
        }

        // Estimated 5 business days from initiation
        if ($domain->transfer_initiated_at) {
            $estimated = $domain->transfer_initiated_at->addDays(5);
            return 'Estimated: ' . $estimated->format('F d, Y');
        }

        return 'Pending initiation';
    }
}

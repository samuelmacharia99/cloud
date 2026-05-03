<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ResellerSslService;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RenewResellerSslCommand extends BaseCronCommand
{
    protected $signature = 'cron:renew-reseller-ssl';

    protected $description = 'Renew SSL certificates for resellers with custom domains';

    public function handleCron(): string
    {
        $sslService = app(ResellerSslService::class);
        $renewed = 0;
        $failed = 0;
        $skipped = 0;

        // Get all resellers
        $resellers = User::where('is_reseller', true)
            ->whereNotNull('settings')
            ->get();

        foreach ($resellers as $reseller) {
            $sslStatus = $sslService->getSslStatus($reseller);

            // Skip if SSL not active
            if ($sslStatus['status'] !== 'active') {
                $skipped++;
                continue;
            }

            // Skip if no expiry date
            if (empty($sslStatus['expires_at'])) {
                $skipped++;
                continue;
            }

            $expiryDate = Carbon::parse($sslStatus['expires_at']);
            $daysUntilExpiry = now()->diffInDays($expiryDate);

            // Only renew if within 30 days of expiry
            if ($daysUntilExpiry > 30) {
                continue;
            }

            Log::info('Renewing SSL certificate for reseller', [
                'reseller_id' => $reseller->id,
                'domain' => $sslStatus['domain'],
                'days_until_expiry' => $daysUntilExpiry,
            ]);

            $result = $sslService->renewCertificate($reseller);

            if ($result['success']) {
                $renewed++;
                Log::info('SSL certificate renewed for reseller', [
                    'reseller_id' => $reseller->id,
                    'domain' => $sslStatus['domain'],
                    'message' => $result['message'],
                ]);
            } else {
                $failed++;
                Log::error('Failed to renew SSL certificate for reseller', [
                    'reseller_id' => $reseller->id,
                    'domain' => $sslStatus['domain'],
                    'message' => $result['message'],
                ]);
            }
        }

        $summary = "SSL renewal complete: {$renewed} renewed, {$failed} failed, {$skipped} skipped";
        Log::info($summary);

        return $summary;
    }
}

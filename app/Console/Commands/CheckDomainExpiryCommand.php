<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class CheckDomainExpiryCommand extends BaseCronCommand
{
    protected $signature = 'cron:check-domain-expiry';
    protected $description = 'Marks expired domains and logs warnings for domains expiring soon';

    protected function handleCron(): string
    {
        $lines = [];
        $notificationService = app(NotificationService::class);

        // Mark expired domains
        $expired = Domain::where('status', '!=', 'expired')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
        $lines[] = "Marked {$expired} domain(s) as expired.";

        // Warn for upcoming expirations
        foreach ([30, 7, 1] as $days) {
            $target = now()->addDays($days)->toDateString();
            $domains = Domain::where('status', 'active')
                ->whereDate('expires_at', $target)
                ->with('user')
                ->get();

            foreach ($domains as $domain) {
                Log::warning("DOMAIN EXPIRY: {$domain->name} expires in {$days} day(s). User ID: {$domain->user_id}. Auto-renew: " . ($domain->auto_renew ? 'yes' : 'no'));
                $notificationService->notifyDomainExpiry($domain, $days);
            }
            if ($domains->count()) {
                $lines[] = "{$domains->count()} domain(s) expiring in {$days} day(s).";
            }
        }

        return implode(' | ', $lines);
    }
}

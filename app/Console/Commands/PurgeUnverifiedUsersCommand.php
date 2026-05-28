<?php

namespace App\Console\Commands;

use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Console\Command;

class PurgeUnverifiedUsersCommand extends Command
{
    protected $signature = 'registration:purge-unverified {--days= : Days since registration before purge}';

    protected $description = 'Delete unverified customer accounts with no services or invoices';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('registration.purge_unverified_after_days', 7));
        $cutoff = now()->subDays($days);

        $users = User::query()
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->whereNull('email_verified_at')
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('services')
            ->whereDoesntHave('invoices')
            ->get();

        $count = 0;
        foreach ($users as $user) {
            EmailVerificationCode::where('user_id', $user->id)->delete();
            $user->delete();
            $count++;
        }

        $this->info("Purged {$count} unverified account(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}

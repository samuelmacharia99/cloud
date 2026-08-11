<?php

namespace App\Console\Commands;

use App\Services\Provisioning\MailDnsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Deploy / ops: ensure DKIM in Mailcow and publish MX/SPF/DKIM/DMARC
 * for every active email service whose domain uses Talksasa Cloudflare DNS.
 */
class SyncCloudflareMailDnsCommand extends Command
{
    protected $signature = 'mailcow:sync-cloudflare-dns
                            {--dry-run : List eligible domains without writing DNS}
                            {--limit= : Maximum eligible Cloudflare mail domains to process}';

    protected $description = 'Ensure DKIM and re-apply hardened mail DNS (MX/SPF/DKIM/DMARC) for Cloudflare-managed mail domains';

    public function handle(MailDnsService $dns): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = filled($limitOption) ? max(1, (int) $limitOption) : null;

        $this->info($dryRun
            ? 'Dry-run: scanning active Mailcow services on Talksasa Cloudflare DNS…'
            : 'Syncing mail DNS for Cloudflare-managed domains…');

        $summary = $dns->syncAllCloudflareMailDomains($dryRun, $limit);

        $rows = collect($summary['results'])->map(fn (array $row) => [
            $row['service_id'],
            $row['domain'] ?: '—',
            $row['status'],
            Str::limit($row['message'], 80),
        ])->all();

        if ($rows !== []) {
            $this->table(['Service', 'Domain', 'Status', 'Message'], $rows);
        } else {
            $this->line('No matching email services found.');
        }

        $this->newLine();
        $this->line("Scanned: {$summary['scanned']}");
        $this->line("Eligible (Cloudflare mail): {$summary['eligible']}");
        $this->line('Skipped: '.$summary['skipped']);
        if ($dryRun) {
            $this->line('Would apply: '.$summary['eligible']);
        } else {
            $this->line('Applied: '.$summary['applied']);
            $this->line('Failed: '.$summary['failed']);
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

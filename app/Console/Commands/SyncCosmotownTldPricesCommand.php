<?php

namespace App\Console\Commands;

use App\Services\Registrar\CosmotownTldPriceSyncService;

class SyncCosmotownTldPricesCommand extends BaseCronCommand
{
    protected $signature = 'registrar:sync-cosmotown-tld-prices';

    protected $description = 'Pull Cosmotown registrar costs for enabled TLDs into Admin → Domains & Pricing';

    protected function handleCron(): string
    {
        $result = app(CosmotownTldPriceSyncService::class)->sync();

        if (
            ! $result['success']
            && $result['synced'] === 0
            && $result['unchanged'] === 0
            && ! str_contains($result['message'], 'No active Cosmotown registrar')
            && ! str_contains($result['message'], 'No enabled domain extensions')
        ) {
            throw new \RuntimeException($result['message']);
        }

        return $result['message'];
    }
}

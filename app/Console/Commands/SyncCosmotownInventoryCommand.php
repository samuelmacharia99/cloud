<?php

namespace App\Console\Commands;

use App\Services\Registrar\CosmotownInventorySyncService;

class SyncCosmotownInventoryCommand extends BaseCronCommand
{
    protected $signature = 'registrar:sync-cosmotown-inventory';

    protected $description = 'Update Admin → Domains expiry and nameservers from the Cosmotown account inventory';

    protected function handleCron(): string
    {
        $result = app(CosmotownInventorySyncService::class)->sync();

        if (
            ! $result['success']
            && $result['fetched'] === 0
            && $result['updated'] === 0
            && ! str_contains($result['message'], 'No active Cosmotown registrar')
        ) {
            throw new \RuntimeException($result['message']);
        }

        return $result['message'];
    }
}

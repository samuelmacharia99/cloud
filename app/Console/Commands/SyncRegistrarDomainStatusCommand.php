<?php

namespace App\Console\Commands;

use App\Enums\RegistrarDriver;
use App\Models\Domain;
use App\Models\Registrar;
use App\Models\ResellerDomainOrder;
use App\Services\DomainPushService;
use App\Services\DomainTransferService;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Console\Command;

class SyncRegistrarDomainStatusCommand extends Command
{
    protected $signature = 'registrar:sync-domains {--limit=100 : Maximum domains to check per run}';

    protected $description = 'Sync Openprovider domain statuses and complete pending orders when active';

    public function handle(RegistrarFulfillmentService $fulfillment): int
    {
        $registrar = Registrar::query()
            ->where('driver', RegistrarDriver::Openprovider)
            ->where('is_active', true)
            ->first();

        if (! $registrar) {
            $this->info('No active Openprovider registrar configured.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');

        $domains = Domain::query()
            ->where(function ($query) {
                $query->whereIn('status', ['provisioning', 'pending'])
                    ->orWhere(function ($sub) {
                        $sub->where('type', 'transfer')
                            ->whereIn('transfer_status', ['initiated', 'in_progress', 'pending']);
                    });
            })
            ->where(function ($query) use ($registrar) {
                $query->whereNotNull('registrar_external_id')
                    ->orWhere('registrar', $registrar->slug);
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $synced = 0;
        $completed = 0;

        foreach ($domains as $domain) {
            $wasActive = $domain->status === 'active';

            if (! $fulfillment->syncDomain($domain->fresh())) {
                continue;
            }

            $synced++;
            $domain->refresh();

            if (! $wasActive && $domain->status === 'active') {
                $completed += $this->completeLinkedOrders($domain, $registrar->name);
            }
        }

        $this->info("Synced {$synced} domain(s); completed {$completed} order(s).");

        return self::SUCCESS;
    }

    private function completeLinkedOrders(Domain $domain, string $registrarName): int
    {
        $count = 0;
        $pushService = app(DomainPushService::class);

        $orders = ResellerDomainOrder::query()
            ->where('domain_id', $domain->id)
            ->where('status', 'pushed')
            ->get();

        foreach ($orders as $order) {
            try {
                if ($order->isTransfer() && $domain->transfer_status !== 'completed') {
                    DomainTransferService::completeTransfer($domain, $registrarName);
                }

                $pushService->completeOrder($order, $registrarName);
                $count++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $count;
    }
}

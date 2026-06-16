<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Models\ContainerDeployment;
use App\Models\Node;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\Telegram\TelegramMonitorBridge;
use Illuminate\Support\Facades\DB;

class TelegramDailyOpsReportCommand extends BaseCronCommand
{
    protected $signature = 'cron:telegram-daily-ops-report';

    protected $description = 'Send daily 8AM Telegram operations snapshot';

    protected function handleCron(): string
    {
        $nodesRunning = Node::query()
            ->where('is_active', true)
            ->where('status', 'online')
            ->count();

        $servicesRunning = Service::query()
            ->where('status', ServiceStatus::Active->value)
            ->count();

        $containersRunning = ContainerDeployment::query()
            ->where('status', 'running')
            ->count();

        $ticketsRaised24h = Ticket::query()
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $openTickets = Ticket::query()
            ->where('status', '!=', 'closed')
            ->count();

        $payments24hKes = (float) Payment::query()
            ->where('status', 'completed')
            ->where('paid_at', '>=', now()->subDay())
            ->selectRaw('COALESCE(SUM(CASE WHEN currency = ? OR currency IS NULL THEN amount ELSE 0 END), 0) as total', [config('currency.base', 'KES')])
            ->value('total');

        $activeCustomers = DB::table('users')
            ->where('is_admin', false)
            ->where('is_reseller', false)
            ->count();

        app(TelegramMonitorBridge::class)->systemAlert('Daily platform operations snapshot (8:00 AM)', [
            'Running nodes' => (string) $nodesRunning,
            'Running services' => (string) $servicesRunning,
            'Running containers' => (string) $containersRunning,
            'Active customers' => (string) $activeCustomers,
            'Tickets raised (24h)' => (string) $ticketsRaised24h,
            'Open tickets' => (string) $openTickets,
            'Payments received (24h, KES)' => number_format($payments24hKes, 2),
        ]);

        return "Daily ops report sent: {$nodesRunning} nodes, {$servicesRunning} services, {$containersRunning} containers.";
    }
}

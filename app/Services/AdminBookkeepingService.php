<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\DomainExtension;
use App\Models\DomainRenewalOrder;
use App\Models\InvoiceItem;
use App\Models\Node;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminBookkeepingService
{
    public const CATEGORY_HOSTING = 'hosting';

    public const CATEGORY_SUBSCRIPTION = 'reseller_subscription';

    public const CATEGORY_DOMAIN = 'domain';

    public const CATEGORY_WALLET_TOPUP = 'wallet_topup';

    public const CATEGORY_OTHER = 'other';

    /**
     * @return array<string, mixed>
     */
    public function build(int $year, ?int $month): array
    {
        $from = $this->periodStart($year, $month);
        $to = $this->periodEnd($year, $month);
        $costMonths = $this->costMonthCount($from, $to);

        $payments = $this->completedPlatformPayments($from, $to)
            ->with([
                'user:id,name,email,is_reseller,reseller_node_id',
                'invoice.user:id,name,is_reseller,reseller_node_id',
                'invoice.items.service.containerDeployment',
            ])
            ->orderByRaw('COALESCE(payments.paid_at, payments.created_at) asc')
            ->get();

        $income = $this->emptyIncome();
        $nodeHosting = [];
        $nodeSubscriptions = [];

        foreach ($payments as $payment) {
            $allocation = $this->allocatePayment($payment);
            foreach ($allocation['categories'] as $key => $amount) {
                $income[$key] += $amount;
            }
            foreach ($allocation['node_hosting'] as $nodeId => $amount) {
                $nodeHosting[$nodeId] = ($nodeHosting[$nodeId] ?? 0) + $amount;
            }
            foreach ($allocation['node_subscription'] as $nodeId => $amount) {
                $nodeSubscriptions[$nodeId] = ($nodeSubscriptions[$nodeId] ?? 0) + $amount;
            }
        }

        foreach ($income as $key => $amount) {
            $income[$key] = round($amount, 2);
        }

        $walletSubscriptions = $this->walletSubscriptionByNode($from, $to);
        foreach ($walletSubscriptions as $nodeId => $amount) {
            $nodeSubscriptions[$nodeId] = ($nodeSubscriptions[$nodeId] ?? 0) + $amount;
        }

        $cashIn = round(array_sum($income), 2);
        $nodes = $this->nodeRows($costMonths, $nodeHosting, $nodeSubscriptions);
        $nodeSpend = round((float) collect($nodes)->sum(fn (array $row) => $row['cost_kes'] ?? 0), 2);

        $domainReport = $this->domainReport($from, $to);
        $domainSpend = $domainReport['cost'];
        $spend = round($nodeSpend + $domainSpend, 2);
        $profit = round($cashIn - $spend, 2);

        return [
            'year' => $year,
            'month' => $month,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $this->periodLabel($year, $month),
            'costMonths' => $costMonths,
            'years' => $this->availableYears(),
            'monthOptions' => $this->monthOptions(),
            'cashIn' => $cashIn,
            'spend' => $spend,
            'profit' => $profit,
            'marginPercent' => $cashIn > 0 ? round(($profit / $cashIn) * 100, 1) : null,
            'income' => $income,
            'costs' => [
                'nodes' => $nodeSpend,
                'domains' => $domainSpend,
                'nodes_untracked' => collect($nodes)
                    ->filter(fn (array $row) => ($row['id'] ?? 0) > 0 && $row['monthly_cost_usd'] === null)
                    ->count(),
                'nodes_missing_rate' => collect($nodes)
                    ->filter(fn (array $row) => ($row['id'] ?? 0) > 0 && $row['monthly_cost_usd'] !== null && $row['cost_kes'] === null)
                    ->count(),
                'domains_missing_cost' => $domainReport['missing_cost_count'],
            ],
            'chart' => $this->chartSeries($from, $to, $month === null, $payments, $domainReport['rows'], $nodes, $costMonths),
            'paymentCount' => $payments->count(),
            'nodes' => $nodes,
            'domains' => $domainReport,
        ];
    }

    public function periodStart(int $year, ?int $month): Carbon
    {
        return $month
            ? Carbon::create($year, $month, 1)->startOfDay()
            : Carbon::create($year, 1, 1)->startOfDay();
    }

    public function periodEnd(int $year, ?int $month): Carbon
    {
        return $month
            ? Carbon::create($year, $month, 1)->endOfMonth()
            : Carbon::create($year, 12, 31)->endOfDay();
    }

    /**
     * How many calendar months of provider cost fall in this period (no future months).
     */
    public function costMonthCount(CarbonInterface $from, CarbonInterface $to): int
    {
        $last = $to->copy()->min(now())->startOfMonth();
        $cursor = $from->copy()->startOfMonth();
        $count = 0;

        while ($cursor->lte($last)) {
            $count++;
            $cursor->addMonth();
        }

        return $count;
    }

    private function completedPlatformPayments(CarbonInterface $from, CarbonInterface $to)
    {
        return Payment::query()
            ->platformRevenue()
            ->where('status', PaymentStatus::Completed)
            ->whereEffectivePaidBetween($from, $to);
    }

    /**
     * @return array{categories: array<string, float>, node_hosting: array<int, float>, node_subscription: array<int, float>}
     */
    public function allocatePayment(Payment $payment): array
    {
        $kes = $this->paymentKes($payment);
        $categories = $this->emptyIncome();
        $nodeHosting = [];
        $nodeSubscription = [];

        if ($payment->payment_purpose === 'wallet_topup') {
            $categories[self::CATEGORY_WALLET_TOPUP] = $kes;

            return [
                'categories' => $categories,
                'node_hosting' => $nodeHosting,
                'node_subscription' => $nodeSubscription,
            ];
        }

        $invoice = $payment->invoice;
        if (! $invoice) {
            $categories[self::CATEGORY_OTHER] = $kes;

            return [
                'categories' => $categories,
                'node_hosting' => $nodeHosting,
                'node_subscription' => $nodeSubscription,
            ];
        }

        if ($invoice->type === 'reseller_subscription') {
            $categories[self::CATEGORY_SUBSCRIPTION] = $kes;
            $nodeId = $invoice->user?->reseller_node_id;
            if ($nodeId) {
                $nodeSubscription[(int) $nodeId] = $kes;
            }

            return [
                'categories' => $categories,
                'node_hosting' => $nodeHosting,
                'node_subscription' => $nodeSubscription,
            ];
        }

        $items = $invoice->relationLoaded('items') ? $invoice->items : $invoice->items()->with('service.containerDeployment')->get();
        $itemTotal = (float) $items->sum('amount');

        if ($itemTotal <= 0 || $items->isEmpty()) {
            $categories[self::CATEGORY_OTHER] = $kes;

            return [
                'categories' => $categories,
                'node_hosting' => $nodeHosting,
                'node_subscription' => $nodeSubscription,
            ];
        }

        foreach ($items as $item) {
            $share = round($kes * ((float) $item->amount / $itemTotal), 2);
            $bucket = $this->itemCategory($item);
            $categories[$bucket] += $share;

            if ($bucket === self::CATEGORY_HOSTING) {
                $nodeId = $this->serviceNodeId($item->service);
                if ($nodeId) {
                    $nodeHosting[$nodeId] = ($nodeHosting[$nodeId] ?? 0) + $share;
                } else {
                    $nodeHosting[0] = ($nodeHosting[0] ?? 0) + $share;
                }
            }
        }

        $this->reconcileRounding($categories, $kes);

        return [
            'categories' => $categories,
            'node_hosting' => $nodeHosting,
            'node_subscription' => $nodeSubscription,
        ];
    }

    public function paymentKes(Payment $payment): float
    {
        if ($payment->amount_base_kes !== null) {
            return round((float) $payment->amount_base_kes, 2);
        }

        return round((float) $payment->amount, 2);
    }

    private function itemCategory(InvoiceItem $item): string
    {
        if ($item->product_type === 'Domain' || $item->domain_id) {
            return self::CATEGORY_DOMAIN;
        }

        if ($item->product_type === 'reseller_package'
            || $item->product_type === 'reseller_disk_usage'
            || $item->product_type === 'reseller_disk_overage') {
            return self::CATEGORY_SUBSCRIPTION;
        }

        if ($item->service_id || $item->product_id) {
            return self::CATEGORY_HOSTING;
        }

        return self::CATEGORY_OTHER;
    }

    private function serviceNodeId(?Service $service): ?int
    {
        if (! $service) {
            return null;
        }

        if ($service->node_id) {
            return (int) $service->node_id;
        }

        $deploymentNodeId = $service->containerDeployment?->node_id;

        return $deploymentNodeId ? (int) $deploymentNodeId : null;
    }

    /**
     * @param  array<string, float>  $categories
     */
    private function reconcileRounding(array &$categories, float $kes): void
    {
        $sum = round(array_sum($categories), 2);
        $delta = round($kes - $sum, 2);
        if ($delta == 0.0) {
            return;
        }

        foreach (array_keys($categories) as $key) {
            if ($categories[$key] > 0) {
                $categories[$key] = round($categories[$key] + $delta, 2);

                return;
            }
        }

        $categories[self::CATEGORY_OTHER] = round($categories[self::CATEGORY_OTHER] + $delta, 2);
    }

    /**
     * @return array<string, float>
     */
    private function emptyIncome(): array
    {
        return [
            self::CATEGORY_HOSTING => 0.0,
            self::CATEGORY_SUBSCRIPTION => 0.0,
            self::CATEGORY_DOMAIN => 0.0,
            self::CATEGORY_WALLET_TOPUP => 0.0,
            self::CATEGORY_OTHER => 0.0,
        ];
    }

    /**
     * @return array<int, float>
     */
    private function walletSubscriptionByNode(CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = WalletTransaction::query()
            ->where('type', 'subscription_debit')
            ->whereBetween('created_at', [$from, $to])
            ->with(['wallet.reseller:id,reseller_node_id'])
            ->get();

        $byNode = [];
        foreach ($rows as $row) {
            $nodeId = $row->wallet?->reseller?->reseller_node_id;
            if (! $nodeId) {
                continue;
            }
            $byNode[(int) $nodeId] = ($byNode[(int) $nodeId] ?? 0) + (float) $row->amount;
        }

        return $byNode;
    }

    /**
     * @param  array<int, float>  $nodeHosting
     * @param  array<int, float>  $nodeSubscriptions
     * @return list<array<string, mixed>>
     */
    private function nodeRows(int $costMonths, array $nodeHosting, array $nodeSubscriptions): array
    {
        $nodes = Node::query()->orderBy('name')->get();
        $rows = [];

        foreach ($nodes as $node) {
            $monthlyKes = $node->monthlyCostKes();
            $cost = $monthlyKes === null ? null : round($monthlyKes * $costMonths, 2);
            $hosting = round($nodeHosting[$node->id] ?? 0, 2);
            $subscription = round($nodeSubscriptions[$node->id] ?? 0, 2);
            $revenue = round($hosting + $subscription, 2);

            $monthlyUsd = $node->monthly_cost_usd !== null ? (float) $node->monthly_cost_usd : null;

            $rows[] = [
                'id' => $node->id,
                'name' => $node->name,
                'type' => $node->type,
                'type_label' => $node->getTypeLabel(),
                'status' => $node->status,
                'monthly_cost_usd' => $monthlyUsd,
                'monthly_cost_kes' => $monthlyKes,
                'cost_kes' => $cost,
                'cost_status' => $monthlyUsd === null ? 'missing_spend' : ($cost === null ? 'missing_rate' : 'ok'),
                'hosting_revenue' => $hosting,
                'subscription_revenue' => $subscription,
                'revenue' => $revenue,
                'profit' => $cost === null ? null : round($revenue - $cost, 2),
            ];
        }

        $unassignedHosting = round($nodeHosting[0] ?? 0, 2);
        if ($unassignedHosting > 0) {
            $rows[] = [
                'id' => 0,
                'name' => 'Unassigned hosting',
                'type' => null,
                'type_label' => 'No node',
                'status' => null,
                'monthly_cost_usd' => null,
                'monthly_cost_kes' => null,
                'cost_kes' => 0.0,
                'cost_status' => 'unassigned',
                'hosting_revenue' => $unassignedHosting,
                'subscription_revenue' => 0.0,
                'revenue' => $unassignedHosting,
                'profit' => $unassignedHosting,
            ];
        }

        usort($rows, function (array $a, array $b) {
            $ap = $a['profit'] ?? -INF;
            $bp = $b['profit'] ?? -INF;
            if ($ap === $bp) {
                return strcmp($a['name'], $b['name']);
            }

            return $bp <=> $ap;
        });

        return $rows;
    }

    /**
     * Fulfillment-based domain P&L for the period, rolled up by registrar.
     *
     * @return array{
     *     collected: float,
     *     cost: float,
     *     profit: float,
     *     count: int,
     *     missing_cost_count: int,
     *     registrars: list<array<string, mixed>>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function domainReport(CarbonInterface $from, CarbonInterface $to): array
    {
        $extensions = $this->domainExtensionsByTld();
        $rows = [];

        $orders = ResellerDomainOrder::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->with(['customer:id,name,email', 'reseller:id,name'])
            ->orderByDesc('completed_at')
            ->get();

        foreach ($orders as $order) {
            $extension = $extensions->get($this->normalizeExtension((string) $order->extension));
            if (! $extension) {
                continue;
            }

            $kind = $order->isTransfer() ? 'transfer' : 'registration';
            $cost = $this->registrarCostFor($extension, $kind, (int) $order->years);
            $collected = round((float) $order->wholesale_amount, 2);

            $this->appendDomainFulfillment($rows, $extension, [
                'kind' => $kind,
                'domain' => $order->domain_name.$this->normalizeExtension((string) $order->extension),
                'years' => (int) $order->years,
                'completed_at' => $order->completed_at,
                'payer' => $order->reseller?->name ?? $order->customer?->name ?? '—',
                'collected' => $collected,
                'cost' => $cost,
                'profit' => $cost === null ? null : round($collected - $cost, 2),
                'source' => 'order',
                'source_id' => $order->id,
            ]);
        }

        $renewals = DomainRenewalOrder::query()
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$from, $to])
            ->with(['domain', 'user:id,name', 'reseller:id,name'])
            ->orderByDesc('completed_at')
            ->get();

        foreach ($renewals as $renewal) {
            $extKey = $this->normalizeExtension((string) ($renewal->domain?->extension ?? ''));
            $extension = $extensions->get($extKey);
            if (! $extension) {
                continue;
            }

            $cost = $this->registrarCostFor($extension, 'renewal', (int) $renewal->years);
            $collected = round($renewal->effectiveWholesaleAmount(), 2);

            $this->appendDomainFulfillment($rows, $extension, [
                'kind' => 'renewal',
                'domain' => $renewal->domain?->fqdn() ?? '—',
                'years' => (int) $renewal->years,
                'completed_at' => $renewal->completed_at,
                'payer' => $renewal->reseller?->name ?? $renewal->user?->name ?? '—',
                'collected' => $collected,
                'cost' => $cost,
                'profit' => $cost === null ? null : round($collected - $cost, 2),
                'source' => 'renewal',
                'source_id' => $renewal->id,
            ]);
        }

        usort($rows, fn (array $a, array $b) => ($b['completed_at']?->timestamp ?? 0) <=> ($a['completed_at']?->timestamp ?? 0));

        $costed = collect($rows)->filter(fn (array $row) => $row['cost'] !== null);
        $cost = round((float) $costed->sum('cost'), 2);
        $collectedWithCost = round((float) $costed->sum('collected'), 2);

        return [
            'collected' => round((float) collect($rows)->sum('collected'), 2),
            'cost' => $cost,
            'profit' => round($collectedWithCost - $cost, 2),
            'count' => count($rows),
            'missing_cost_count' => collect($rows)->filter(fn (array $row) => $row['cost'] === null)->count(),
            'registrars' => $this->registrarSummaries($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function appendDomainFulfillment(array &$rows, DomainExtension $extension, array $payload): void
    {
        $bucket = $this->registrarBucket($extension);

        $rows[] = array_merge($payload, [
            'registrar_key' => $bucket['key'],
            'registrar_id' => $bucket['id'],
            'registrar_name' => $bucket['name'],
            'registrar_driver' => $bucket['driver'],
            'registrar_driver_label' => $bucket['driver_label'],
        ]);
    }

    /**
     * @return array{key: string, id: ?int, name: string, driver: ?string, driver_label: ?string}
     */
    private function registrarBucket(DomainExtension $extension): array
    {
        $model = $extension->registrarModel;
        if ($model) {
            return [
                'key' => 'id:'.$model->id,
                'id' => $model->id,
                'name' => $model->name,
                'driver' => $model->driver?->value,
                'driver_label' => $model->driver?->label(),
            ];
        }

        $name = trim((string) $extension->registrar);
        if ($name !== '') {
            return [
                'key' => 'name:'.strtolower($name),
                'id' => null,
                'name' => $name,
                'driver' => null,
                'driver_label' => null,
            ];
        }

        return [
            'key' => 'unassigned',
            'id' => null,
            'name' => 'Unassigned',
            'driver' => null,
            'driver_label' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function registrarSummaries(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = (string) $row['registrar_key'];
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => $row['registrar_id'],
                    'name' => $row['registrar_name'],
                    'driver' => $row['registrar_driver'],
                    'driver_label' => $row['registrar_driver_label'],
                    'count' => 0,
                    'registrations' => 0,
                    'transfers' => 0,
                    'renewals' => 0,
                    'collected' => 0.0,
                    'costed_collected' => 0.0,
                    'cost' => 0.0,
                    'missing_cost_count' => 0,
                ];
            }

            $groups[$key]['count']++;
            $groups[$key]['collected'] += (float) $row['collected'];

            match ($row['kind']) {
                'transfer' => $groups[$key]['transfers']++,
                'renewal' => $groups[$key]['renewals']++,
                default => $groups[$key]['registrations']++,
            };

            if ($row['cost'] === null) {
                $groups[$key]['missing_cost_count']++;
            } else {
                $groups[$key]['cost'] += (float) $row['cost'];
                $groups[$key]['costed_collected'] += (float) $row['collected'];
            }
        }

        $summaries = [];
        foreach ($groups as $group) {
            $summaries[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'driver' => $group['driver'],
                'driver_label' => $group['driver_label'],
                'count' => $group['count'],
                'registrations' => $group['registrations'],
                'transfers' => $group['transfers'],
                'renewals' => $group['renewals'],
                'collected' => round($group['collected'], 2),
                'cost' => round($group['cost'], 2),
                'profit' => round($group['costed_collected'] - $group['cost'], 2),
                'missing_cost_count' => $group['missing_cost_count'],
            ];
        }

        usort($summaries, function (array $a, array $b) {
            if ($a['profit'] === $b['profit']) {
                return strcmp($a['name'], $b['name']);
            }

            return $b['profit'] <=> $a['profit'];
        });

        return $summaries;
    }

    /**
     * @return Collection<string, DomainExtension>
     */
    private function domainExtensionsByTld(): Collection
    {
        return DomainExtension::query()
            ->with('registrarModel')
            ->get()
            ->keyBy(fn (DomainExtension $extension) => $this->normalizeExtension($extension->extension));
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower(trim($extension));
        if ($extension === '') {
            return '';
        }

        return str_starts_with($extension, '.') ? $extension : '.'.$extension;
    }

    private function registrarCostFor(DomainExtension $extension, string $kind, int $years): ?float
    {
        return match ($kind) {
            'renewal' => $extension->registrarRenewalCost($years),
            'transfer' => $extension->registrar_transfer_cost_kes !== null
                ? round((float) $extension->registrar_transfer_cost_kes, 2)
                : $extension->registrarRegistrationCost(1),
            default => $extension->registrarRegistrationCost($years),
        };
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  list<array<string, mixed>>  $domainRows
     * @param  list<array<string, mixed>>  $nodes
     * @return array{labels: list<string>, revenue: list<float>, spend: list<float>, profit: list<float>, granularity: string}
     */
    private function chartSeries(
        CarbonInterface $from,
        CarbonInterface $to,
        bool $yearly,
        Collection $payments,
        array $domainRows,
        array $nodes,
        int $costMonths,
    ): array {
        if ($yearly) {
            return $this->monthlyChart($from, $to, $payments, $domainRows, $nodes);
        }

        return $this->dailyChart($from, $to, $payments, $domainRows, $nodes, $costMonths);
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  list<array<string, mixed>>  $domainRows
     * @param  list<array<string, mixed>>  $nodes
     * @return array{labels: list<string>, revenue: list<float>, spend: list<float>, profit: list<float>, granularity: string}
     */
    private function dailyChart(
        CarbonInterface $from,
        CarbonInterface $to,
        Collection $payments,
        array $domainRows,
        array $nodes,
        int $costMonths,
    ): array {
        $daysInMonth = $from->daysInMonth;
        $nodeMonthly = (float) collect($nodes)->sum(fn (array $row) => (float) ($row['monthly_cost_kes'] ?? 0));
        $dailyNodeCost = $costMonths > 0 ? round($nodeMonthly / $daysInMonth, 2) : 0.0;

        $revenueByDay = [];
        foreach ($payments as $payment) {
            $day = ($payment->paid_at ?? $payment->created_at)?->toDateString();
            if (! $day) {
                continue;
            }
            $revenueByDay[$day] = ($revenueByDay[$day] ?? 0) + $this->paymentKes($payment);
        }

        $domainByDay = [];
        foreach ($domainRows as $row) {
            if ($row['cost'] === null || ! $row['completed_at']) {
                continue;
            }
            $day = $row['completed_at']->toDateString();
            $domainByDay[$day] = ($domainByDay[$day] ?? 0) + (float) $row['cost'];
        }

        $labels = [];
        $revenue = [];
        $spend = [];
        $profit = [];
        $cursor = $from->copy()->startOfDay();
        $spent = 0.0;

        while ($cursor->lte($to)) {
            $key = $cursor->toDateString();
            $labels[] = $cursor->format('M j');
            $dayRevenue = round($revenueByDay[$key] ?? 0, 2);
            $daySpend = round($dailyNodeCost + ($domainByDay[$key] ?? 0), 2);
            $spent += $daySpend;
            $revenue[] = $dayRevenue;
            $spend[] = $daySpend;
            $profit[] = round($dayRevenue - $daySpend, 2);
            $cursor->addDay();
        }

        $this->fixLastBucketRounding($spend, round($nodeMonthly * $costMonths + array_sum($domainByDay), 2), $spent);

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'spend' => $spend,
            'profit' => $profit,
            'granularity' => 'day',
        ];
    }

    /**
     * @param  Collection<int, Payment>  $payments
     * @param  list<array<string, mixed>>  $domainRows
     * @param  list<array<string, mixed>>  $nodes
     * @return array{labels: list<string>, revenue: list<float>, spend: list<float>, profit: list<float>, granularity: string}
     */
    private function monthlyChart(
        CarbonInterface $from,
        CarbonInterface $to,
        Collection $payments,
        array $domainRows,
        array $nodes,
    ): array {
        $nodeMonthly = (float) collect($nodes)->sum(fn (array $row) => (float) ($row['monthly_cost_kes'] ?? 0));
        $lastCostMonth = now()->copy()->startOfMonth();

        $revenueByMonth = [];
        foreach ($payments as $payment) {
            $stamp = $payment->paid_at ?? $payment->created_at;
            if (! $stamp) {
                continue;
            }
            $key = $stamp->format('Y-m');
            $revenueByMonth[$key] = ($revenueByMonth[$key] ?? 0) + $this->paymentKes($payment);
        }

        $domainByMonth = [];
        foreach ($domainRows as $row) {
            if ($row['cost'] === null || ! $row['completed_at']) {
                continue;
            }
            $key = $row['completed_at']->format('Y-m');
            $domainByMonth[$key] = ($domainByMonth[$key] ?? 0) + (float) $row['cost'];
        }

        $labels = [];
        $revenue = [];
        $spend = [];
        $profit = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $key = $cursor->format('Y-m');
            $labels[] = $cursor->format('M');
            $monthRevenue = round($revenueByMonth[$key] ?? 0, 2);
            $includeNodeCost = $cursor->lte($lastCostMonth) && $cursor->lte($to);
            $monthSpend = round(($includeNodeCost ? $nodeMonthly : 0) + ($domainByMonth[$key] ?? 0), 2);
            $revenue[] = $monthRevenue;
            $spend[] = $monthSpend;
            $profit[] = round($monthRevenue - $monthSpend, 2);
            $cursor->addMonth();
        }

        return [
            'labels' => $labels,
            'revenue' => $revenue,
            'spend' => $spend,
            'profit' => $profit,
            'granularity' => 'month',
        ];
    }

    /**
     * @param  list<float>  $buckets
     */
    private function fixLastBucketRounding(array &$buckets, float $target, float $actual): void
    {
        if ($buckets === []) {
            return;
        }

        $delta = round($target - $actual, 2);
        if ($delta == 0.0) {
            return;
        }

        $last = array_key_last($buckets);
        $buckets[$last] = round($buckets[$last] + $delta, 2);
    }

    /**
     * @return list<int>
     */
    private function availableYears(): array
    {
        $earliest = Payment::query()->min(DB::raw('COALESCE(paid_at, created_at)'));
        $start = $earliest ? Carbon::parse($earliest)->year : now()->year;

        return range(min($start, now()->year), now()->year);
    }

    /**
     * @return array<int|string, string>
     */
    private function monthOptions(): array
    {
        $options = ['all' => 'All months'];
        for ($month = 1; $month <= 12; $month++) {
            $options[$month] = Carbon::create(null, $month, 1)->format('F');
        }

        return $options;
    }

    public function periodLabel(int $year, ?int $month): string
    {
        if ($month) {
            return Carbon::create($year, $month, 1)->format('F Y');
        }

        return (string) $year;
    }
}

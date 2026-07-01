<?php

namespace App\Services;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ResellerDashboardPaymentStats
{
    /**
     * @param  list<int>  $customerIds
     * @return array<string, float> day key (Y-m-d) => amount
     */
    public function dailyTotals(array $customerIds, Carbon $from, Carbon $to): array
    {
        if ($customerIds === []) {
            return [];
        }

        $totals = [];

        Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $customerIds))
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($fallback) use ($from, $to) {
                        $fallback->whereNull('paid_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->get(['amount', 'paid_at', 'created_at'])
            ->each(function (Payment $payment) use (&$totals) {
                $day = ($payment->paid_at ?? $payment->created_at)?->toDateString();
                if (! $day) {
                    return;
                }
                $totals[$day] = ($totals[$day] ?? 0) + (float) $payment->amount;
            });

        return $totals;
    }

    /**
     * @param  list<int>  $customerIds
     */
    public function totalForRange(array $customerIds, Carbon $from, Carbon $to): float
    {
        if ($customerIds === []) {
            return 0.0;
        }

        return (float) Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $customerIds))
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('paid_at', [$from, $to])
                    ->orWhere(function ($fallback) use ($from, $to) {
                        $fallback->whereNull('paid_at')
                            ->whereBetween('created_at', [$from, $to]);
                    });
            })
            ->sum('amount');
    }

    /**
     * @param  list<int>  $customerIds
     * @return list<float>
     */
    public function monthlySeries(array $customerIds, int $months = 6): array
    {
        if ($customerIds === []) {
            return array_fill(0, $months, 0.0);
        }

        $start = now()->subMonths($months - 1)->startOfMonth();
        $end = now()->endOfMonth();

        $bucketed = [];

        Payment::query()
            ->where('status', 'completed')
            ->whereHas('invoice', fn ($query) => $query->whereIn('user_id', $customerIds))
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('paid_at', [$start, $end])
                    ->orWhere(function ($fallback) use ($start, $end) {
                        $fallback->whereNull('paid_at')
                            ->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->get(['amount', 'paid_at', 'created_at'])
            ->each(function (Payment $payment) use (&$bucketed) {
                $at = $payment->paid_at ?? $payment->created_at;
                if (! $at) {
                    return;
                }
                $key = $at->copy()->startOfMonth()->format('Y-m');
                $bucketed[$key] = ($bucketed[$key] ?? 0) + (float) $payment->amount;
            });

        $series = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = now()->subMonths($i)->startOfMonth()->format('Y-m');
            $series[] = round((float) ($bucketed[$key] ?? 0), 2);
        }

        return $series;
    }

    /**
     * @param  Collection<int, int>|list<int>  $customerIds
     */
    public function customerIdsArray(Collection|array $customerIds): array
    {
        return collect($customerIds)->filter()->values()->all();
    }
}

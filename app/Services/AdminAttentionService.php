<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\DomainRenewalOrder;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AdminAttentionService
{
    /** @var list<string> */
    public const SECTIONS = [
        'domain_orders',
        'orders',
        'domain_renewals',
        'tickets',
        'payments',
        'services',
    ];

    /**
     * Payment methods that require an admin to approve (not auto-settled gateways).
     *
     * @var list<string>
     */
    public const PAYMENTS_NEEDING_REVIEW = [
        'manual',
        'bank_transfer',
    ];

    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        return Cache::remember(
            'admin_attention_'.$user->id,
            now()->addSeconds(30),
            fn () => $this->buildSnapshot($user),
        );
    }

    public function markSeen(User $user, string $section): void
    {
        if (! in_array($section, self::SECTIONS, true)) {
            return;
        }

        $settings = $user->settings ?? [];
        $settings['admin_seen'] ??= [];
        $settings['admin_seen'][$section] = now()->toIso8601String();

        $user->forceFill(['settings' => $settings])->save();

        Cache::forget('admin_attention_'.$user->id);
        $this->clearCache();
    }

    public function clearCache(): void
    {
        Cache::forget('admin_attention_counts');

        User::query()
            ->where('is_admin', true)
            ->pluck('id')
            ->each(fn ($id) => Cache::forget('admin_attention_'.$id));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(User $user): array
    {
        $seen = $user->settings['admin_seen'] ?? [];

        $domainOrdersPending = ResellerDomainOrder::query()
            ->whereIn('status', ['queued', 'pushed', 'failed']);

        $renewalsPending = DomainRenewalOrder::query()->whereIn('status', ['queued', 'paid', 'pushed', 'failed']);

        $ticketsOpen = Ticket::query()->visibleToAdmin()->where('status', '!=', 'closed');

        $paymentsPending = Payment::query()
            ->where('status', PaymentStatus::Pending)
            ->whereIn('payment_method', self::PAYMENTS_NEEDING_REVIEW);

        $servicesFailed = Service::query()->where('status', 'failed');

        $counts = [
            'domain_orders' => (clone $domainOrdersPending)->count(),
            'orders' => 0,
            'domain_renewals' => (clone $renewalsPending)->count(),
            'tickets' => (clone $ticketsOpen)->count(),
            'payments' => (clone $paymentsPending)->count(),
            'services_provisioning' => (clone $servicesFailed)->count(),
            'services_failed' => (clone $servicesFailed)->count(),
        ];

        $new = [
            'domain_orders' => $this->countNewSince($seen['domain_orders'] ?? null, clone $domainOrdersPending, 'created_at'),
            'orders' => 0,
            'domain_renewals' => $this->countNewSince($seen['domain_renewals'] ?? null, clone $renewalsPending, 'created_at'),
            'tickets' => $this->countNewTicketsSince($seen['tickets'] ?? null, clone $ticketsOpen),
            'payments' => $this->countNewSince($seen['payments'] ?? null, clone $paymentsPending, 'created_at'),
            'services' => $this->countNewSince($seen['services'] ?? null, clone $servicesFailed, 'updated_at'),
        ];

        $counts['total'] = $counts['domain_orders']
            + $counts['domain_renewals']
            + $counts['tickets']
            + $counts['payments']
            + $counts['services_failed'];
        $newTotal = array_sum($new);

        foreach ($new as $key => $value) {
            $counts[$key.'_new'] = $value;
        }

        $counts['new_total'] = $newTotal;

        $counts['domain_order_breakdown'] = ResellerDomainOrder::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->whereIn('status', ['queued', 'pushed', 'failed'])
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $counts['recent'] = $this->recentFeed();

        return $counts;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function countNewSince(?string $seenAt, Builder $query, string $column): int
    {
        if ($seenAt) {
            $query->where($column, '>', Carbon::parse($seenAt));
        }

        return $query->count();
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function countNewTicketsSince(?string $seenAt, Builder $query): int
    {
        if (! $seenAt) {
            return $query->count();
        }

        $since = Carbon::parse($seenAt);

        return $query->where(function (Builder $outer) use ($since) {
            $outer->where('created_at', '>', $since)
                ->orWhere('escalated_at', '>', $since);
        })->count();
    }

    /**
     * @return list<array{type: string, title: string, meta: string, url: string, at: string}>
     */
    private function recentFeed(): array
    {
        $items = collect();

        ResellerDomainOrder::query()
            ->with('reseller:id,name')
            ->whereIn('status', ['queued', 'pushed', 'failed'])
            ->latest()
            ->limit(4)
            ->get()
            ->each(function (ResellerDomainOrder $order) use ($items) {
                $items->push([
                    'type' => 'domain_order',
                    'title' => $order->fullDomainName(),
                    'meta' => ucfirst($order->status).' · '.($order->reseller?->name ?? 'Platform'),
                    'url' => route('admin.domain-orders.show', $order),
                    'at' => $order->created_at?->diffForHumans() ?? '',
                    'sort' => $order->created_at,
                ]);
            });

        Ticket::query()
            ->visibleToAdmin()
            ->with('user:id,name')
            ->where('status', '!=', 'closed')
            ->latest()
            ->limit(4)
            ->get()
            ->each(function (Ticket $ticket) use ($items) {
                $items->push([
                    'type' => 'ticket',
                    'title' => $ticket->title ?? 'Support ticket',
                    'meta' => ucfirst($ticket->priority ?? 'normal').' · '.($ticket->user?->name ?? 'Unknown'),
                    'url' => route('tickets.show', $ticket),
                    'at' => $ticket->created_at?->diffForHumans() ?? '',
                    'sort' => $ticket->escalated_at ?? $ticket->created_at,
                ]);
            });

        Payment::query()
            ->with('user:id,name')
            ->where('status', PaymentStatus::Pending)
            ->whereIn('payment_method', self::PAYMENTS_NEEDING_REVIEW)
            ->latest()
            ->limit(3)
            ->get()
            ->each(function (Payment $payment) use ($items) {
                $method = $payment->payment_method?->label() ?? 'Payment';
                $items->push([
                    'type' => 'payment',
                    'title' => 'KES '.number_format((float) $payment->amount, 2),
                    'meta' => ($payment->user?->name ?? 'Unknown').' · '.$method.' awaiting approval',
                    'url' => route('admin.payments.show', $payment),
                    'at' => $payment->created_at?->diffForHumans() ?? '',
                    'sort' => $payment->created_at,
                ]);
            });

        DomainRenewalOrder::query()
            ->with('domain:id,name')
            ->whereIn('status', ['queued', 'paid', 'pushed', 'failed'])
            ->latest()
            ->limit(3)
            ->get()
            ->each(function (DomainRenewalOrder $renewal) use ($items) {
                $items->push([
                    'type' => 'renewal',
                    'title' => $renewal->domain?->name ?? ('Renewal #'.$renewal->id),
                    'meta' => ucfirst((string) $renewal->status).' · domain renewal',
                    'url' => route('admin.domain-renewals.show', $renewal),
                    'at' => $renewal->created_at?->diffForHumans() ?? '',
                    'sort' => $renewal->created_at,
                ]);
            });

        Service::query()
            ->with(['user:id,name', 'product:id,name'])
            ->where('status', 'failed')
            ->latest('updated_at')
            ->limit(3)
            ->get()
            ->each(function (Service $service) use ($items) {
                $items->push([
                    'type' => 'service',
                    'title' => $service->product?->name ?? $service->name ?? 'Service',
                    'meta' => ($service->user?->name ?? 'Unknown').' · provisioning failed',
                    'url' => route('admin.services.show', $service),
                    'at' => $service->updated_at?->diffForHumans() ?? '',
                    'sort' => $service->updated_at ?? $service->created_at,
                ]);
            });

        return $items
            ->sortByDesc('sort')
            ->take(8)
            ->map(fn (array $item) => collect($item)->except('sort')->all())
            ->values()
            ->all();
    }
}

@if(!empty($usageReport))
<div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-5 space-y-4">
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">
                {{ ($usageReport['is_usage'] ?? false) ? 'Usage this billing period' : 'Resource usage vs package' }}
            </h3>
            @if(!empty($usageReport['period']))
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {{ $usageReport['period']['from_human'] }} → {{ $usageReport['period']['to_human'] }}
                </p>
            @endif
        </div>
        @if($usageReport['is_usage'] ?? false)
            <div class="text-right">
                <p class="text-xs text-slate-500">Projected next invoice</p>
                <p class="text-lg font-bold text-slate-900 dark:text-white">
                    KES {{ number_format($usageReport['projected_total'] ?? 0, 0) }}
                </p>
                <p class="text-xs text-slate-500">
                    Floor {{ number_format($usageReport['floor_price'] ?? 0, 0) }}
                    @if(($usageReport['projected_overage'] ?? 0) > 0)
                        + overage {{ number_format($usageReport['projected_overage'], 0) }}
                    @endif
                </p>
            </div>
        @endif
    </div>

    @if(!empty($usageReport['warnings']))
        <div class="rounded-lg bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 p-3 text-sm text-amber-900 dark:text-amber-100 space-y-1">
            @foreach($usageReport['warnings'] as $warning)
                <p>{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
        <div class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
            <p class="text-xs text-slate-500">Avg CPU</p>
            <p class="font-semibold text-slate-900 dark:text-white">
                {{ number_format($usageReport['usage']['avg_cpu_cores'] ?? 0, 2) }}
                <span class="text-xs font-normal text-slate-500">/ {{ number_format($usageReport['included']['cpu'] ?? 0, 2) }} cores</span>
            </p>
        </div>
        <div class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
            <p class="text-xs text-slate-500">Peak RAM</p>
            <p class="font-semibold text-slate-900 dark:text-white">
                {{ number_format(($usageReport['usage']['peak_memory_mb'] ?? 0) / 1024, 2) }}
                <span class="text-xs font-normal text-slate-500">/ {{ number_format(($usageReport['included']['memory_mb'] ?? 0) / 1024, 2) }} GB</span>
            </p>
        </div>
        <div class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
            <p class="text-xs text-slate-500">Avg disk</p>
            <p class="font-semibold text-slate-900 dark:text-white">
                {{ number_format($usageReport['usage']['avg_disk_gb'] ?? 0, 2) }}
                <span class="text-xs font-normal text-slate-500">/ {{ number_format($usageReport['included']['disk_gb'] ?? 0, 2) }} GB</span>
            </p>
        </div>
        <div class="rounded-lg bg-slate-50 dark:bg-slate-800 p-3">
            <p class="text-xs text-slate-500">Mailboxes (peak)</p>
            <p class="font-semibold text-slate-900 dark:text-white">
                {{ (int) ($usageReport['usage']['mailbox_peak'] ?? 0) }}
                <span class="text-xs font-normal text-slate-500">/ {{ (int) ($usageReport['mailboxes_included'] ?? 0) }}</span>
            </p>
        </div>
    </div>

    @if(!empty($usageReport['invoice_items']))
        <div class="border-t border-slate-100 dark:border-slate-800 pt-3">
            <p class="text-xs font-medium text-slate-500 uppercase mb-2">Recent usage invoice lines</p>
            <ul class="space-y-2 text-sm">
                @foreach($usageReport['invoice_items'] as $line)
                    <li class="flex justify-between gap-3">
                        <span class="text-slate-700 dark:text-slate-300">
                            @if(!empty($line['invoice_number']))
                                <span class="text-slate-400">{{ $line['invoice_number'] }} · </span>
                            @endif
                            {{ \Illuminate\Support\Str::limit($line['description'], 80) }}
                        </span>
                        <span class="font-medium text-slate-900 dark:text-white shrink-0">KES {{ number_format($line['amount'], 0) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
@endif

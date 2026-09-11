{{--
    The panel that answers "is this server all right", which the node page has
    never been able to. It shows what the scheduled scan found, and runs a fresh
    one only when somebody asks: opening a page should not SSH into a server.
--}}
<div
    class="ui-card p-8"
    x-data="nodeDoctor(@js(route('admin.nodes.health-scan', $node)), @js(route('admin.nodes.health-repair', $node)))"
>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Server health</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Services, partitions, inodes and the DirectAdmin task queue. Scanned every five minutes.
            </p>
        </div>
        <button
            type="button"
            @click="scan()"
            :disabled="scanning"
            class="shrink-0 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
            x-text="scanning ? 'Scanning…' : 'Scan now'"
        ></button>
    </div>

    <p x-show="error" x-text="error" class="mb-4 text-sm text-red-600 dark:text-red-400"></p>

    <template x-if="!scanned">
        <p class="text-sm text-slate-500 dark:text-slate-400">
            Scan now to check this server, or open the timeline below for what the last scheduled scan found.
        </p>
    </template>

    <template x-if="scanned && findings.length === 0">
        <div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 p-4 text-sm text-emerald-900 dark:text-emerald-100">
            Every service is running, no partition is close to full, and the task queue is draining.
        </div>
    </template>

    <div class="space-y-3" x-show="findings.length">
        <template x-for="finding in findings" :key="finding.id">
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
                <div class="flex items-start justify-between gap-4 px-4 py-3 bg-slate-50 dark:bg-slate-800/60">
                    <div class="min-w-0">
                        <span
                            class="inline-block rounded px-1.5 py-0.5 text-[11px] font-medium uppercase tracking-wide"
                            :class="{
                                'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300': finding.severity === 'critical',
                                'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300': finding.severity === 'warning',
                                'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300': finding.severity === 'info'
                            }"
                            x-text="finding.severity"
                        ></span>
                        <h4 class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" x-text="finding.title"></h4>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400" x-text="finding.summary"></p>
                    </div>
                    <button
                        type="button"
                        x-show="finding.treat_action"
                        @click="repair(finding)"
                        :disabled="repairing"
                        class="shrink-0 px-3 py-1.5 bg-slate-900 dark:bg-slate-100 text-white dark:text-slate-900 rounded-lg text-xs font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                        x-text="repairing === finding.id ? 'Working…' : finding.treat_label"
                    ></button>
                </div>
                <div class="px-4 py-3 space-y-2" x-show="finding.evidence?.length || finding.manual_steps?.length">
                    <template x-if="finding.evidence?.length">
                        <pre class="text-xs font-mono bg-slate-900 text-slate-300 p-2 rounded overflow-x-auto whitespace-pre-wrap" x-text="finding.evidence.join('\n')"></pre>
                    </template>
                    <template x-if="finding.manual_steps?.length">
                        <ul class="text-xs text-slate-600 dark:text-slate-400 list-disc pl-4 space-y-1">
                            <template x-for="step in finding.manual_steps" :key="step">
                                <li x-text="step"></li>
                            </template>
                        </ul>
                    </template>
                </div>
            </div>
        </template>
    </div>

    @if ($nodeEvents->isNotEmpty())
        <div class="mt-8">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Recent history</h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-3">
                Written when something changed, not on every scan. A server that failed at three in the morning still matters at nine.
            </p>
            <ul class="space-y-2">
                @foreach ($nodeEvents as $event)
                    <li class="flex items-start gap-3 text-xs">
                        <span class="shrink-0 font-mono text-slate-400 dark:text-slate-500">
                            {{ $event->recorded_at?->format('M j H:i') }}
                        </span>
                        <span @class([
                            'shrink-0 rounded px-1.5 py-0.5 font-medium',
                            'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300' => $event->severity === 'critical',
                            'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300' => $event->severity === 'warning',
                            'bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300' => ! in_array($event->severity, ['critical', 'warning']),
                        ])>
                            {{ str_replace('_', ' ', $event->event) }}
                        </span>
                        <span class="text-slate-600 dark:text-slate-400">
                            {{ $event->payload['title'] ?? $event->payload['message'] ?? $event->payload['finding'] ?? '' }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

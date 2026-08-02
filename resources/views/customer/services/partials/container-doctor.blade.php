{{-- In-house log doctor: scans latest ~2000 log lines for known stack issues --}}
<div
    class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/80 overflow-hidden"
    x-data="containerDoctor({
        diagnoseUrl: @js(route('customer.services.container.doctor.diagnose', $service)),
        treatUrl: @js(route('customer.services.container.doctor.treat', $service)),
        logLines: {{ \App\Services\Provisioning\ContainerDoctorService::LOG_LINES }},
    })"
>
    <div class="px-5 py-4 border-b border-slate-200 dark:border-slate-700 flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white">Container Doctor</h3>
                <span class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">Beta</span>
            </div>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1 max-w-2xl">
                Scans the latest <span x-text="logLines"></span> log lines for common stack problems and offers one-click repairs. AI assist comes later.
            </p>
        </div>
        <button
            type="button"
            @click="runDiagnose()"
            :disabled="diagnosing || treating"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-sm font-medium transition"
        >
            <svg class="h-4 w-4" :class="diagnosing && 'animate-spin'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.611L5 14.5" />
            </svg>
            <span x-text="diagnosing ? 'Scanning…' : (hasResult ? 'Re-scan logs' : 'Run doctor')"></span>
        </button>
    </div>

    <div class="p-5 space-y-4">
        <div
            x-show="treating"
            x-cloak
            class="text-sm rounded-lg px-3 py-2 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-200 border border-blue-200 dark:border-blue-800"
        >
            Applying fix… this can take up to a minute (database sync + .env rewrite).
        </div>

        <div
            x-ref="treatBanner"
            x-show="treatMessage"
            x-cloak
            class="text-sm rounded-lg px-3 py-3 border"
            :class="treatOk
                ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-900 dark:text-emerald-100 border-emerald-200 dark:border-emerald-800'
                : 'bg-red-50 dark:bg-red-900/20 text-red-900 dark:text-red-100 border-red-200 dark:border-red-800'"
        >
            <p class="font-semibold" x-text="treatOk ? 'Treatment applied' : 'Treatment failed'"></p>
            <p class="mt-1" x-text="treatMessage"></p>
        </div>

        <p x-show="error" x-cloak class="text-sm rounded-lg px-3 py-2 bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-200 border border-red-200 dark:border-red-800" x-text="error"></p>

        <template x-if="!hasResult && !diagnosing && !error && !treatMessage">
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Click <span class="font-medium text-slate-700 dark:text-slate-200">Run doctor</span> to check for Postgres auth mismatches, missing drivers, Node/GD issues, permission errors, and more.
            </p>
        </template>

        <template x-if="hasResult && healthy">
            <div class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
                No active critical issues from live checks
                <span x-show="findings.some(f => f.stale)"> (some older log lines remain below)</span>
                <span class="text-emerald-600/80 dark:text-emerald-300/80" x-show="scannedAt" x-text="'· scanned ' + scannedAt"></span>.
            </div>
        </template>

        <template x-if="hasResult && liveChecks && (liveChecks.http_status || liveChecks.db_ok !== null)">
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40 px-4 py-3 text-xs text-slate-600 dark:text-slate-300 flex flex-wrap gap-x-4 gap-y-1">
                <span>Live checks:</span>
                <span x-show="liveChecks.http_status" x-text="'HTTP ' + liveChecks.http_status"></span>
                <span x-text="liveChecks.db_ok === true ? 'DB: connected' : (liveChecks.db_ok === false ? 'DB: failed' : 'DB: n/a')"></span>
                <span x-show="liveChecks.table_count !== null && liveChecks.table_count !== undefined" x-text="'Tables: ' + liveChecks.table_count"></span>
                <span x-show="liveChecks.env_source" x-text="'Env: ' + liveChecks.env_source"></span>
            </div>
        </template>

        <template x-if="hasResult && findings.length">
            <div class="space-y-3">
                <template x-for="finding in findings" :key="finding.id">
                    <div class="rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden"
                         :class="finding.stale && 'opacity-90'">
                        <div class="px-4 py-3 flex flex-wrap items-start justify-between gap-3 bg-slate-50 dark:bg-slate-900/40">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span
                                        class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded"
                                        :class="{
                                            'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300': finding.severity === 'critical',
                                            'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300': finding.severity === 'warning',
                                            'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300': finding.severity === 'info'
                                        }"
                                        x-text="finding.stale ? 'stale' : finding.severity"
                                    ></span>
                                    <h4 class="text-sm font-semibold text-slate-900 dark:text-white" x-text="finding.title"></h4>
                                </div>
                                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="finding.summary"></p>
                            </div>
                            <button
                                type="button"
                                x-show="finding.treat_action"
                                @click="runTreat(finding)"
                                :disabled="diagnosing || treating"
                                class="shrink-0 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-xs font-medium transition"
                                x-text="treatingAction === finding.treat_action ? 'Treating…' : (finding.treat_label || 'Treat')"
                            ></button>
                        </div>
                        <div class="px-4 py-3 space-y-2" x-show="finding.evidence?.length || finding.manual_steps?.length">
                            <template x-if="finding.evidence?.length">
                                <div>
                                    <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Evidence from logs</p>
                                    <pre class="text-xs font-mono bg-slate-900 text-slate-300 p-2 rounded overflow-x-auto whitespace-pre-wrap max-h-28" x-text="finding.evidence.join('\n')"></pre>
                                </div>
                            </template>
                            <template x-if="finding.manual_steps?.length">
                                <ul class="text-xs text-slate-600 dark:text-slate-400 list-disc pl-4 space-y-0.5">
                                    <template x-for="(step, i) in finding.manual_steps" :key="i">
                                        <li x-text="step"></li>
                                    </template>
                                </ul>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</div>

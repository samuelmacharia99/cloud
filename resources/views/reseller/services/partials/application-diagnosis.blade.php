{{-- Read-only diagnosis. No repair buttons and no log tail: application logs
     carry customer secrets, and restarting someone's site is theirs to do. --}}
<div class="ui-card p-6 space-y-4"
     x-data="resellerApplicationDiagnosis(@js(route('reseller.services.diagnose', $service)))">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h2 class="font-semibold text-slate-900 dark:text-white">Application diagnosis</h2>
            <p class="text-sm text-slate-500 mt-1">
                Checks the live container and the last {{ \App\Services\Provisioning\ContainerDoctorService::LOG_LINES }} log lines, so you can answer a support question without asking the customer to look. Read-only: repairs stay in their portal.
            </p>
        </div>
        <button type="button" @click="run()" :disabled="running"
            class="shrink-0 px-4 py-2 rounded-lg bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white text-sm font-medium transition">
            <span x-text="running ? 'Checking…' : (hasResult ? 'Check again' : 'Run diagnosis')"></span>
        </button>
    </div>

    <p x-show="error" x-cloak class="text-sm text-red-600 dark:text-red-400" x-text="error"></p>

    <template x-if="hasResult && healthy">
        <p class="rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">
            No active issues found<span x-show="scannedAt" x-text="' · checked ' + scannedAt"></span>.
        </p>
    </template>

    <template x-if="hasResult && checkChips.length">
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40 px-4 py-3 text-xs text-slate-600 dark:text-slate-300 flex flex-wrap gap-x-4 gap-y-1">
            <span>Live checks:</span>
            <template x-for="chip in checkChips" :key="chip">
                <span x-text="chip"></span>
            </template>
        </div>
    </template>

    <template x-if="hasResult && findings.length">
        <div class="space-y-3">
            <template x-for="finding in findings" :key="finding.id">
                <div class="rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
                    <div class="px-4 py-3 bg-slate-50 dark:bg-slate-900/40">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-[10px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded"
                                :class="{
                                    'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300': finding.severity === 'critical',
                                    'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300': finding.severity === 'warning',
                                    'bg-slate-200 dark:bg-slate-700 text-slate-700 dark:text-slate-300': finding.severity === 'info'
                                }"
                                x-text="finding.severity"></span>
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white" x-text="finding.title"></h3>
                        </div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="finding.summary"></p>
                    </div>
                    <div class="px-4 py-3" x-show="finding.evidence?.length">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 mb-1">Evidence</p>
                        <pre class="text-xs font-mono bg-slate-900 text-slate-300 p-2 rounded overflow-x-auto whitespace-pre-wrap max-h-28" x-text="finding.evidence.join('\n')"></pre>
                    </div>
                </div>
            </template>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Repairs run from the customer's own console. Send them this summary, or raise a ticket with us if it is ours to fix.
            </p>
        </div>
    </template>
</div>

@once
    @push('scripts')
        <script>
            function resellerApplicationDiagnosis(diagnoseUrl) {
                return {
                    diagnoseUrl,
                    running: false,
                    hasResult: false,
                    healthy: false,
                    findings: [],
                    checkChips: [],
                    scannedAt: '',
                    error: '',
                    async run() {
                        this.running = true;
                        this.error = '';

                        try {
                            const response = await fetch(this.diagnoseUrl, {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                },
                            });

                            if (response.status === 429) {
                                this.error = 'Too many checks in one minute. Wait a moment, then try again.';
                                return;
                            }

                            const data = await response.json().catch(() => ({}));

                            if (!response.ok) {
                                this.error = data.message || data.error || 'Could not check this application.';
                                return;
                            }

                            this.findings = Array.isArray(data.findings) ? data.findings : [];
                            this.healthy = !!data.healthy;
                            this.checkChips = this.chipsFor(data.live_checks || {});
                            this.scannedAt = data.scanned_at ? new Date(data.scanned_at).toLocaleTimeString() : '';
                            this.hasResult = true;
                        } catch (error) {
                            this.error = 'Network error while checking this application.';
                        } finally {
                            this.running = false;
                        }
                    },
                    chipsFor(checks) {
                        const chips = [];
                        if (checks.http_status) chips.push('HTTP ' + checks.http_status);
                        if (checks.db_ok === true) chips.push('DB: connected');
                        if (checks.db_ok === false) chips.push('DB: failed');
                        if (checks.table_count !== null && checks.table_count !== undefined) chips.push('Tables: ' + checks.table_count);
                        if (checks.upstream_reachable === true) chips.push('App port: answering');
                        if (checks.upstream_reachable === false) chips.push('App port: not answering');
                        if (checks.restarting) chips.push('Container: restarting');
                        if (checks.container_image) chips.push('Image: ' + checks.container_image);
                        if (checks.disk_percent !== null && checks.disk_percent !== undefined) chips.push('Node disk: ' + checks.disk_percent + '%');
                        return chips;
                    },
                };
            }
        </script>
    @endpush
@endonce

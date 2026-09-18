<div class="ui-card p-8" x-data="nodePortAudit(@js(route('admin.nodes.port-audit', $node)))">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Published ports</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                What this host is listening on in the platform range, and which of it has a deployment behind it.
                A container that outlived its record still holds its port, which is how a new deploy is handed one Docker then refuses.
                This report only looks; nothing here is removed.
            </p>
        </div>
        <button type="button" class="px-3 py-1.5 border border-slate-300 dark:border-slate-600 rounded-lg text-sm"
                :disabled="loading" @click="run()" x-text="loading ? 'Scanning…' : (ran ? 'Scan again' : 'Scan ports')"></button>
    </div>

    <p class="text-sm text-red-600 dark:text-red-400 mt-4" x-show="error" x-text="error" x-cloak></p>

    <template x-if="ran && !error">
        <div class="mt-5 space-y-5">
            <p class="text-sm text-slate-600 dark:text-slate-400">
                <span x-text="report.listening_ports.length"></span> port(s) listening,
                <span x-text="report.claimed.length"></span> stack(s) with a deployment,
                <span x-text="report.orphans.length"></span> without one.
            </p>

            <template x-if="report.orphans.length">
                <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4">
                    <p class="text-sm font-medium text-amber-900 dark:text-amber-200">Containers with no deployment record</p>
                    <p class="text-xs text-amber-800 dark:text-amber-300 mt-1">Their ports are reserved so no deploy is given one, but they keep using disk and memory. Remove them on the node once you are sure what they are.</p>
                    <ul class="mt-3 space-y-1">
                        <template x-for="row in report.orphans" :key="row.project">
                            <li class="text-xs font-mono text-amber-900 dark:text-amber-200">
                                <span x-text="row.project"></span>
                                · <span x-text="row.running ? 'running' : 'stopped'"></span>
                                · ports <span x-text="row.ports.join(', ') || 'none'"></span>
                                · <span x-text="row.status"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>

            <template x-if="report.unexplained_ports.length">
                <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-4">
                    <p class="text-sm font-medium text-slate-900 dark:text-white">Listening with nothing in Docker behind it</p>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">A host process or tunnel holds these. The allocator now avoids them.</p>
                    <p class="text-xs font-mono text-slate-700 dark:text-slate-300 mt-2" x-text="report.unexplained_ports.join(', ')"></p>
                </div>
            </template>

            <template x-if="!report.orphans.length && !report.unexplained_ports.length">
                <p class="text-sm text-emerald-700 dark:text-emerald-300">Every published port on this host belongs to a deployment the platform knows about.</p>
            </template>
        </div>
    </template>
</div>

@push('scripts')
<script>
function nodePortAudit(url) {
    return {
        url,
        loading: false,
        ran: false,
        error: null,
        report: { listening_ports: [], claimed: [], orphans: [], unexplained_ports: [] },

        async run() {
            this.loading = true;
            this.error = null;
            try {
                const response = await fetch(this.url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        Accept: 'application/json',
                    },
                });
                const data = await response.json();
                if (!response.ok || data.reachable === false) {
                    this.error = data.message || `Scan failed (HTTP ${response.status}).`;
                    return;
                }
                this.report = data;
                this.ran = true;
            } catch (error) {
                this.error = 'Scan failed: could not reach the server.';
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endpush

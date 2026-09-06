@php
    $convertProgress = $convertProgress ?? ['is_active' => false, 'active_count' => 0, 'items' => [], 'current' => null];
@endphp
<style>[x-cloak]{display:none!important}</style>
<div
    class="fixed z-40 bottom-4 right-4 w-[min(28rem,calc(100vw-1.5rem))]"
    x-data="daConvertProgressTerminal(@js($convertProgress), @js(route('admin.resellers.directadmin-offramp.progress', $reseller)))"
>
    <button
        type="button"
        class="ml-auto block px-3 py-2 rounded-lg bg-slate-900 text-teal-200 text-xs font-mono border border-slate-700 shadow-lg"
        x-show="!open && (payload.is_active || (payload.items || []).length)"
        x-cloak
        @click="open = true; minimized = false"
    >
        Convert log
    </button>

    <div
        class="rounded-2xl border border-slate-800 bg-slate-950 text-slate-100 shadow-2xl overflow-hidden"
        x-show="open"
        x-cloak
    >
        <div class="flex items-center gap-2 px-3 py-2 border-b border-slate-800 bg-slate-900">
            <span class="h-2.5 w-2.5 rounded-full bg-red-500/80"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-amber-500/80"></span>
            <span class="h-2.5 w-2.5 rounded-full bg-emerald-500/80"></span>
            <p class="ml-1 text-[11px] font-mono text-slate-400 truncate">
                da-convert · <span x-text="current?.hostname || 'waiting'"></span>
            </p>
            <span
                class="ml-auto text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full border"
                :class="statusBadgeClass()"
                x-text="current?.status || current?.item_status || 'idle'"
            ></span>
            <button type="button" class="text-slate-500 hover:text-white text-xs px-1" @click="minimized = !minimized" x-text="minimized ? '+' : '–'"></button>
            <button type="button" class="text-slate-500 hover:text-white text-xs px-1" @click="open = false">×</button>
        </div>
        <div x-show="!minimized" class="p-3 space-y-2">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-sm font-semibold truncate" x-text="current?.hostname || 'No convert running'"></p>
                    <p class="text-[11px] text-slate-400 truncate" x-text="current?.label || 'Queue a convert to stream steps here.'"></p>
                </div>
                <p class="text-xl font-bold tabular-nums text-teal-300" x-text="`${current?.percent || 0}%`"></p>
            </div>
            <div class="h-1.5 rounded-full bg-slate-800 overflow-hidden">
                <div class="h-full rounded-full bg-gradient-to-r from-teal-400 to-sky-400 transition-all duration-500" :style="`width: ${current?.percent || 0}%`"></div>
            </div>
            <div class="flex flex-wrap gap-2" x-show="payload.items?.length > 1">
                <template x-for="item in payload.items" :key="item.item_id">
                    <button
                        type="button"
                        class="text-[10px] font-mono px-2 py-1 rounded border"
                        :class="current?.item_id === item.item_id ? 'border-teal-400 text-teal-200' : 'border-slate-700 text-slate-400'"
                        @click="select(item.item_id)"
                        x-text="item.hostname"
                    ></button>
                </template>
            </div>
            <pre
                x-ref="logEl"
                class="text-[11px] leading-relaxed font-mono text-teal-100/90 p-3 h-40 overflow-auto whitespace-pre-wrap rounded-xl bg-[#050508] border border-slate-800"
                x-text="current?.log || 'Waiting for worker steps…'"
            ></pre>
            <div class="flex items-center justify-between gap-2 text-[11px]">
                <span class="text-slate-500" x-show="payload.active_count > 0" x-text="`${payload.active_count} running`"></span>
                <a class="text-sky-300 hover:underline" x-show="current?.wizard_url" :href="current?.wizard_url">Open wizard</a>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function daConvertProgressTerminal(initial, url) {
    return {
        payload: initial || { is_active: false, active_count: 0, items: [], current: null },
        current: (initial && initial.current) || null,
        selectedId: initial?.current?.item_id || null,
        open: Boolean(initial?.is_active),
        minimized: false,
        pollTimer: null,
        url,

        init() {
            this.select(this.selectedId);
            this.scrollLog();
            this.schedulePoll();
            window.addEventListener('da-convert-watch', (event) => {
                this.open = true;
                this.minimized = false;
                if (event.detail?.itemId) this.select(event.detail.itemId);
            });
        },

        select(itemId) {
            this.selectedId = itemId || this.payload.current?.item_id || this.payload.items?.[0]?.item_id || null;
            this.current = (this.payload.items || []).find((item) => item.item_id === this.selectedId) || this.payload.current || null;
            this.scrollLog();
        },

        statusBadgeClass() {
            const status = this.current?.status || this.current?.item_status;
            if (['running', 'pending', 'queued', 'converting'].includes(status)) return 'border-teal-400/40 bg-teal-500/10 text-teal-300';
            if (['completed', 'converted', 'waiting_dns', 'done'].includes(status)) return 'border-emerald-400/40 bg-emerald-500/10 text-emerald-300';
            if (['failed', 'blocked'].includes(status)) return 'border-red-400/40 bg-red-500/10 text-red-300';
            return 'border-slate-600 bg-slate-800 text-slate-400';
        },

        schedulePoll() {
            if (this.pollTimer) clearInterval(this.pollTimer);
            this.pollTimer = setInterval(() => this.refresh(), this.payload.is_active ? 2000 : 8000);
        },

        scrollLog() {
            this.$nextTick(() => {
                const el = this.$refs.logEl;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        async refresh() {
            try {
                const response = await fetch(this.url, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;
                const previous = this.current?.log;
                const wasActive = this.payload.is_active;
                this.payload = await response.json();
                if (this.payload.is_active) this.open = true;
                this.select(this.selectedId);
                if (this.current?.log !== previous) this.scrollLog();
                if (wasActive !== this.payload.is_active) this.schedulePoll();
            } catch (error) {
                console.error('Convert progress refresh failed', error);
            }
        },
    };
}
</script>
@endpush

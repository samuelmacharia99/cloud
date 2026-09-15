@php
    $limits = is_array($limits ?? null) ? $limits : [];
    $cpu = $computePool['cpu'] ?? ['pool' => 0, 'used' => 0, 'remaining' => 0];
    $memory = $computePool['memory'] ?? ['pool' => 0, 'used' => 0, 'remaining' => 0];
    $bandwidth = $bandwidthPool ?? ['pool' => 0, 'used' => 0, 'remaining' => 0];
    $rateCard = $rateCard ?? [];
    $rateConfigured = array_sum(array_map('floatval', $rateCard)) > 0;
    $disabledUnless = $disabledUnless ?? null;
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp

<div class="rounded-lg border border-violet-200 dark:border-violet-800 bg-violet-50 dark:bg-violet-950/30 p-4 space-y-4"
     x-data="resellerContainerPlan({
        cpu: {{ json_encode((float) ($limits['cpu'] ?? 1)) }},
        memoryMb: {{ json_encode((int) ($limits['memory_mb'] ?? 1024)) }},
        diskGb: {{ json_encode((float) ($limits['disk_gb'] ?? 10)) }},
        bandwidthGb: {{ json_encode((int) ($limits['bandwidth_gb'] ?? 0)) }},
        templateId: {{ json_encode($selectedTemplateId ? (string) $selectedTemplateId : '') }},
        templates: @json($stackTemplates ?? []),
        rates: @json($rateCard),
     })">
    <div>
        <p class="text-sm font-semibold text-violet-900 dark:text-violet-200">Plan specs</p>
        <p class="text-xs text-violet-800 dark:text-violet-300 mt-1">
            Every site on this plan gets these resources. Your package pool right now:
            <span class="font-medium">{{ $fmt($cpu['remaining'] ?? 0) }} of {{ $fmt($cpu['pool'] ?? 0) }} vCPU</span>,
            <span class="font-medium">{{ number_format(((int) ($memory['remaining'] ?? 0)) / 1024, 1) }} of {{ number_format(((int) ($memory['pool'] ?? 0)) / 1024, 1) }} GB RAM</span> free
            @if (($bandwidth['pool'] ?? 0) > 0), <span class="font-medium">{{ number_format((int) $bandwidth['pool']) }} GB bandwidth per month</span>@endif.
            A pool of 0 is unmetered.
        </p>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">vCPU</label>
            <input type="number" name="resource_limits[cpu]" step="0.25" min="0.25" max="64" x-model.number="cpu" @if ($disabledUnless) :disabled="!({{ $disabledUnless }})" @endif
                   class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm @error('resource_limits.cpu') border-red-500 @enderror">
            @error('resource_limits.cpu') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">RAM (MB)</label>
            <input type="number" name="resource_limits[memory_mb]" step="256" min="256" max="262144" x-model.number="memoryMb" @if ($disabledUnless) :disabled="!({{ $disabledUnless }})" @endif
                   class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm @error('resource_limits.memory_mb') border-red-500 @enderror">
            @error('resource_limits.memory_mb') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Disk (GB)</label>
            <input type="number" name="resource_limits[disk_gb]" step="1" min="1" max="10000" x-model.number="diskGb" @if ($disabledUnless) :disabled="!({{ $disabledUnless }})" @endif
                   class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm @error('resource_limits.disk_gb') border-red-500 @enderror">
            @error('resource_limits.disk_gb') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Bandwidth (GB/month)</label>
            <input type="number" name="resource_limits[bandwidth_gb]" step="10" min="0" max="1000000" x-model.number="bandwidthGb" placeholder="0 = unmetered" @if ($disabledUnless) :disabled="!({{ $disabledUnless }})" @endif
                   class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm @error('resource_limits.bandwidth_gb') border-red-500 @enderror">
            @error('resource_limits.bandwidth_gb') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="block text-xs font-medium text-slate-700 dark:text-slate-300 mb-1">Stack</label>
        <select name="container_template_id" x-model="templateId" @if ($disabledUnless) :disabled="!({{ $disabledUnless }})" @endif
                class="w-full px-3 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white text-sm @error('container_template_id') border-red-500 @enderror">
            <option value="">Any stack the plan can run (customer picks at deploy)</option>
            <template x-for="t in templates" :key="t.id">
                <option :value="String(t.id)" :disabled="!fits(t)" x-text="t.name + (fits(t) ? '' : ' — needs ' + t.required_cpu_cores + ' vCPU / ' + t.required_ram_mb + ' MB')"></option>
            </template>
        </select>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" x-text="eligibleCount() + ' of ' + templates.length + ' stacks can run on these specs.'"></p>
        @error('container_template_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div class="text-xs text-violet-800 dark:text-violet-300 border-t border-violet-200 dark:border-violet-800 pt-3">
        @if ($rateConfigured)
            <p>Platform cost for these specs: <span class="font-semibold" x-text="'KES ' + wholesale().toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '/mo'"></span>. Your monthly price minus that is your margin.</p>
        @else
            <p>Backups, SSL, the file manager, database console and Container Doctor are included for every site on this plan.</p>
        @endif
    </div>
</div>

@once
<script>
    function resellerContainerPlan(config) {
        return {
            cpu: config.cpu,
            memoryMb: config.memoryMb,
            diskGb: config.diskGb,
            bandwidthGb: config.bandwidthGb,
            templateId: config.templateId || '',
            templates: config.templates || [],
            rates: config.rates || {},
            fits(t) {
                return Number(t.required_cpu_cores || 0) <= Number(this.cpu || 0) && Number(t.required_ram_mb || 0) <= Number(this.memoryMb || 0);
            },
            eligibleCount() {
                return this.templates.filter(t => this.fits(t)).length;
            },
            wholesale() {
                const r = this.rates;
                return Number(this.cpu || 0) * Number(r.cpu_core || 0)
                    + (Number(this.memoryMb || 0) / 1024) * Number(r.memory_gb || 0)
                    + Number(this.diskGb || 0) * Number(r.disk_gb || 0)
                    + Number(this.bandwidthGb || 0) * Number(r.bandwidth_gb || 0);
            },
        };
    }
</script>
@endonce

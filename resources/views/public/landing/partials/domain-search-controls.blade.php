{{-- Domain mode + years controls for landing hero search --}}
<div class="{{ $controlsClass ?? 'mt-4 flex flex-wrap items-center justify-center gap-3 text-sm' }}">
    <div class="inline-flex rounded-lg overflow-hidden border {{ $toggleBorderClass ?? 'border-white/30 bg-white/10' }}">
        <button type="button" @click="domainMode = 'register'"
            :class="domainMode === 'register' ? '{{ $toggleActiveClass ?? 'bg-white text-slate-900' }}' : '{{ $toggleIdleClass ?? 'text-white/90 hover:bg-white/10' }}'"
            class="px-3 py-1.5 font-semibold">
            Register
        </button>
        <button type="button" @click="domainMode = 'transfer'"
            :class="domainMode === 'transfer' ? '{{ $toggleActiveClass ?? 'bg-white text-slate-900' }}' : '{{ $toggleIdleClass ?? 'text-white/90 hover:bg-white/10' }}'"
            class="px-3 py-1.5 font-semibold">
            Transfer
        </button>
    </div>
    <label class="inline-flex items-center gap-2 {{ $yearsLabelClass ?? 'text-white/90' }}" x-show="domainMode === 'register'">
        <span class="font-medium">Years</span>
        <select x-model.number="years"
            class="{{ $yearsSelectClass ?? 'rounded-md border-0 text-slate-900 text-sm font-semibold py-1.5 pl-2 pr-8' }}">
            <template x-for="y in [1,2,3,5,10]" :key="y">
                <option :value="y" x-text="y + (y === 1 ? ' year' : ' years')"></option>
            </template>
        </select>
    </label>
</div>

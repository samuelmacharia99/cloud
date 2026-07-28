{{-- Billing cycle toggle for hosting section --}}
<div class="{{ $cycleClass ?? 'flex flex-wrap items-center justify-center gap-3 mb-8' }}">
    <span class="text-sm font-medium {{ $cycleLabelClass ?? 'text-slate-600' }}">Billing</span>
    <div class="inline-flex rounded-lg overflow-hidden border border-slate-200 bg-white shadow-sm">
        <button type="button" @click="billingCycle = 'monthly'"
            :class="billingCycle === 'monthly' ? 'brand-btn text-white' : 'text-slate-700 hover:bg-slate-50'"
            class="px-4 py-2 text-sm font-semibold">
            Monthly
        </button>
        <button type="button" @click="billingCycle = 'annual'"
            :class="billingCycle === 'annual' ? 'brand-btn text-white' : 'text-slate-700 hover:bg-slate-50'"
            class="px-4 py-2 text-sm font-semibold">
            Yearly
        </button>
    </div>
</div>

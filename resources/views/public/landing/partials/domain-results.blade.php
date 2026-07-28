{{-- Shared domain search results + transfer form + hosting upsell --}}
<section x-show="domainMode === 'transfer'" x-cloak class="{{ $resultsClass ?? 'bg-slate-50 border-b border-slate-200' }}">
    <div class="{{ $resultsContainerClass ?? 'max-w-6xl mx-auto px-4 py-8' }}" id="transfer">
        <h2 class="{{ $resultsHeadingClass ?? 'text-xl font-bold text-slate-900 mb-4' }}">Transfer your domain</h2>
        <div class="{{ $resultsCardClass ?? 'bg-white rounded-lg border border-slate-200 shadow-sm' }} p-5 sm:p-6 max-w-xl space-y-4">
            <p class="text-sm opacity-70">Unlock the domain at your current registrar, then enter the EPP/auth code below.</p>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-60 mb-1">Domain</label>
                <input type="text" x-model="query" placeholder="example.com"
                    class="w-full px-3 py-2 rounded-md border border-slate-200 text-slate-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-60 mb-1">EPP / auth code</label>
                <input type="text" x-model="transferEpp" placeholder="Auth code from current registrar"
                    class="w-full px-3 py-2 rounded-md border border-slate-200 text-slate-900 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide opacity-60 mb-1">Current registrar</label>
                <input type="text" x-model="transferRegistrar" placeholder="e.g. GoDaddy, Namecheap"
                    class="w-full px-3 py-2 rounded-md border border-slate-200 text-slate-900 text-sm">
            </div>
            <button type="button" @click="orderTransfer()" :disabled="ordering"
                class="{{ $orderBtnClass ?? 'brand-btn text-white text-sm font-semibold px-4 py-2.5 rounded-md disabled:opacity-60' }}">
                <span x-text="ordering ? 'Adding…' : 'Add transfer to cart'"></span>
            </button>
            <p x-show="orderError" x-text="orderError" class="text-sm text-rose-600"></p>
        </div>
    </div>
</section>

<section x-show="domainMode === 'register' && (results.length || searched)" x-cloak class="{{ $resultsClass ?? 'bg-slate-50 border-b border-slate-200' }}">
    <div class="{{ $resultsContainerClass ?? 'max-w-6xl mx-auto px-4 py-8' }}" id="domains">
        <h2 class="{{ $resultsHeadingClass ?? 'text-xl font-bold text-slate-900 mb-4' }}">Domain search results</h2>
        <div class="{{ $resultsCardClass ?? 'bg-white rounded-lg border border-slate-200 overflow-hidden shadow-sm' }}">
            <template x-if="results.length === 0 && searched && !searching">
                <p class="p-6 text-sm opacity-70">No results. Try another name.</p>
            </template>
            <ul class="divide-y divide-slate-100/80">
                <template x-for="row in results" :key="row.full_domain">
                    <li class="px-4 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                        <div>
                            <p class="font-semibold" x-text="row.full_domain"></p>
                            <p class="text-xs mt-1"
                               :class="row.available ? 'text-emerald-600' : 'text-rose-600'"
                               x-text="row.available ? 'Available' : 'Unavailable'"></p>
                        </div>
                        <div class="flex items-center gap-4">
                            <span class="font-semibold" x-show="row.available && row.price != null">
                                KES <span x-text="Number(row.price).toLocaleString()"></span>
                                <span class="text-xs opacity-60 font-normal" x-text="' / ' + (row.period_years || years) + ((row.period_years || years) === 1 ? ' yr' : ' yrs')"></span>
                            </span>
                            <button
                                type="button"
                                x-show="row.available"
                                @click="orderDomain(row)"
                                :disabled="ordering"
                                class="{{ $orderBtnClass ?? 'brand-btn text-white text-sm font-semibold px-4 py-2 rounded-md disabled:opacity-60' }}"
                            >
                                Order now
                            </button>
                        </div>
                    </li>
                </template>
            </ul>
        </div>
        <p x-show="orderError && !upsellOpen" x-text="orderError" class="mt-3 text-sm text-rose-600"></p>

        {{-- Domain → hosting upsell --}}
        <div x-show="upsellOpen" x-cloak class="mt-6 {{ $resultsCardClass ?? 'bg-white rounded-lg border border-slate-200 shadow-sm' }} p-5 sm:p-6">
            <h3 class="text-lg font-bold {{ $upsellHeadingClass ?? 'text-slate-900' }}">Add hosting for <span x-text="upsellDomain"></span>?</h3>
            <p class="text-sm opacity-70 mt-1 mb-4">Pair your domain with a plan now — you can still change this later.</p>
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-for="plan in hostingOptions.slice(0, 6)" :key="plan.id">
                    <button type="button" @click="addHostingUpsell(plan.id)" :disabled="ordering"
                        class="text-left rounded-lg border border-slate-200 p-4 hover:border-[var(--brand)] transition disabled:opacity-60">
                        <p class="font-semibold text-slate-900" x-text="plan.name"></p>
                        <p class="text-sm mt-1 text-slate-600">
                            KES <span x-text="Number(displayPrice(plan)).toLocaleString()"></span>
                            <span x-text="priceLabel()"></span>
                        </p>
                    </button>
                </template>
            </div>
            <div class="mt-4 flex flex-wrap gap-3">
                <button type="button" @click="skipUpsell()" class="text-sm font-semibold text-slate-600 hover:text-slate-900">
                    No thanks — go to cart →
                </button>
            </div>
            <p x-show="orderError" x-text="orderError" class="mt-3 text-sm text-rose-600"></p>
        </div>
    </div>
</section>

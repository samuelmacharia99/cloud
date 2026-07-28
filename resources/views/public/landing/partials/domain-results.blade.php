{{-- Shared domain search results block --}}
<section x-show="results.length || searched" x-cloak class="{{ $resultsClass ?? 'bg-slate-50 border-b border-slate-200' }}">
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
                                <span class="text-xs opacity-60 font-normal">/yr</span>
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
        <p x-show="orderError" x-text="orderError" class="mt-3 text-sm text-rose-600"></p>
    </div>
</section>

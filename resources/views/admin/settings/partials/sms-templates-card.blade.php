@php
    $smsTemplatesPayload = $smsTemplatesList->map(fn ($t) => [
        'id' => $t->id,
        'name' => $t->name,
        'event_key' => $t->event_key,
        'body' => $t->body,
        'recipient_type' => $t->recipient_type,
        'description' => $t->description,
        'available_variables' => $t->available_variables ?? [],
        'reset_url' => route('admin.sms-templates.reset', $t),
    ])->values();
@endphp

<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden" x-data="smsTemplates(@js($smsTemplatesPayload))">
    <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">SMS Templates</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                <span x-text="items.length"></span> template<span x-show="items.length !== 1">s</span> — click a row to expand and edit. Max 320 characters per message.
            </p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button type="button" @click="expandAll()" class="px-3 py-1.5 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Expand all
            </button>
            <button type="button" @click="collapseAll()" class="px-3 py-1.5 text-sm font-medium rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Collapse all
            </button>
        </div>
    </div>

    <template x-if="items.length === 0">
        <div class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
            <p>No SMS templates found.</p>
            <p class="text-sm mt-1">Run <code class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 font-mono text-xs">php artisan db:seed --class=SmsTemplateSeeder</code> to load defaults.</p>
        </div>
    </template>

    <ul class="divide-y divide-slate-200 dark:divide-slate-800" x-show="items.length > 0">
        <template x-for="item in items" :key="item.id">
            <li class="group">
                <button
                    type="button"
                    @click="toggle(item.id)"
                    class="w-full flex items-center gap-3 px-6 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors"
                    :aria-expanded="isExpanded(item.id)"
                >
                    <svg
                        class="w-5 h-5 shrink-0 text-slate-400 transition-transform duration-200"
                        :class="{ 'rotate-90': isExpanded(item.id) }"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>

                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-slate-900 dark:text-white" x-text="item.name"></span>
                            <span
                                class="inline-flex px-2 py-0.5 rounded text-xs font-medium"
                                :class="recipientBadgeClass(item.recipient_type)"
                                x-text="recipientLabel(item.recipient_type)"
                            ></span>
                        </div>
                        <p class="text-sm text-slate-500 dark:text-slate-400 truncate mt-0.5" x-text="drafts[item.id]?.body"></p>
                    </div>

                    <span class="hidden sm:inline shrink-0 text-xs text-slate-400 dark:text-slate-500">
                        <span x-text="(drafts[item.id]?.body || '').length"></span>/320
                    </span>
                    <code class="hidden md:inline-block shrink-0 text-xs font-mono text-slate-400 dark:text-slate-500" x-text="item.event_key"></code>
                </button>

                <div
                    x-show="isExpanded(item.id)"
                    x-cloak
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 -translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="px-6 pb-6 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-950/30"
                >
                    <div class="pt-5 space-y-4">
                        <p x-show="item.description" class="text-sm text-slate-600 dark:text-slate-400" x-text="item.description"></p>

                        <div x-show="item.available_variables?.length > 0" class="space-y-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Insert variable</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="variable in item.available_variables" :key="variable">
                                    <button
                                        type="button"
                                        @click="insertVariable(item.id, '{' + variable + '}')"
                                        class="px-2 py-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-blue-400 text-slate-700 dark:text-slate-300 rounded text-xs font-mono transition-colors"
                                        x-text="'{' + variable + '}'"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Send to</label>
                            <select x-model="drafts[item.id].recipient_type" class="block w-full max-w-xs px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                                <option value="customer">Customer only</option>
                                <option value="admin">Admin only</option>
                                <option value="both">Both customer &amp; admin</option>
                            </select>
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="text-sm font-medium text-slate-700 dark:text-slate-300">Message</label>
                                <span class="text-xs text-slate-500 dark:text-slate-400"><span x-text="(drafts[item.id]?.body || '').length"></span>/320</span>
                            </div>
                            <textarea
                                x-model="drafts[item.id].body"
                                maxlength="320"
                                rows="4"
                                class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white resize-y text-sm leading-relaxed"
                            ></textarea>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 pt-2">
                            <button
                                type="button"
                                @click="save(item.id)"
                                :disabled="smsSaving[item.id]"
                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-medium rounded-lg text-sm transition-colors"
                            >
                                <span x-show="!smsSaving[item.id]">Save template</span>
                                <span x-show="smsSaving[item.id]">Saving…</span>
                            </button>
                            <button
                                type="button"
                                @click="resetTemplate(item.id, item.reset_url)"
                                class="px-4 py-2 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg text-sm transition-colors"
                            >
                                Reset to default
                            </button>
                            <div class="flex-1"></div>
                            <span
                                x-show="smsStatus[item.id]"
                                :class="smsStatus[item.id]?.type === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                class="text-sm font-medium"
                                x-text="smsStatus[item.id]?.msg"
                            ></span>
                        </div>
                    </div>
                </div>
            </li>
        </template>
    </ul>
</div>

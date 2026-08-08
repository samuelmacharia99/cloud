@php
    $emailTemplatesPayload = collect($emailTemplatesList ?? [])->map(fn ($t) => [
        'id' => $t['event_key'],
        'name' => $t['name'],
        'event_key' => $t['event_key'],
        'subject' => $t['subject'],
        'body' => $t['body'],
        'enabled' => $t['enabled'],
        'description' => $t['description'],
        'available_variables' => $t['available_variables'] ?? [],
        'is_overridden' => $t['is_overridden'] ?? false,
        'reset_url' => route('reseller.settings.email-templates.reset', $t['event_key']),
    ])->values();
@endphp

<div class="ui-card overflow-hidden mt-6" x-data="resellerEmailTemplates(@js($emailTemplatesPayload))">
    <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Email Templates</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Messages you send to your customers. Edit subject and body, or disable a template to stop that email.
                <code class="text-xs font-mono">{site_name}</code> uses your company name from the Branding tab.
                Customized templates replace the default branded HTML with your text.
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
            <p>No customer email templates available.</p>
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
                            <span x-show="item.is_overridden || drafts[item.id]?.is_overridden" class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200">Customized</span>
                            <span x-show="!drafts[item.id]?.enabled" class="inline-flex px-2 py-0.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">Disabled</span>
                        </div>
                        <p class="text-sm text-slate-500 dark:text-slate-400 truncate mt-0.5" x-text="drafts[item.id]?.subject"></p>
                    </div>

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

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" x-model="drafts[item.id].enabled" class="rounded border-slate-300 dark:border-slate-600">
                            <span class="text-sm text-slate-700 dark:text-slate-300">Template enabled</span>
                        </label>

                        <div x-show="item.available_variables?.length > 0" class="space-y-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Insert variable into body</p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="variable in item.available_variables" :key="variable">
                                    <button
                                        type="button"
                                        @click="insertVariable(item.id, '{' + variable + '}')"
                                        class="px-2 py-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-purple-400 text-slate-700 dark:text-slate-300 rounded text-xs font-mono transition-colors"
                                        x-text="'{' + variable + '}'"
                                    ></button>
                                </template>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Subject</label>
                            <input
                                type="text"
                                x-model="drafts[item.id].subject"
                                class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Body</label>
                            <textarea
                                x-model="drafts[item.id].body"
                                rows="8"
                                class="block w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white resize-y font-mono text-sm leading-relaxed"
                            ></textarea>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Plain text — line breaks are preserved in emails. Save to apply your custom wording.</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 pt-2">
                            <button
                                type="button"
                                @click="save(item.id)"
                                :disabled="emailSaving[item.id]"
                                class="px-4 py-2 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white font-medium rounded-lg text-sm transition-colors"
                            >
                                <span x-show="!emailSaving[item.id]">Save template</span>
                                <span x-show="emailSaving[item.id]">Saving…</span>
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
                                x-show="emailStatus[item.id]"
                                :class="emailStatus[item.id]?.type === 'success' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                                class="text-sm font-medium"
                                x-text="emailStatus[item.id]?.msg"
                            ></span>
                        </div>
                    </div>
                </div>
            </li>
        </template>
    </ul>
</div>

<div x-show="activeTab === 'registrars'" class="space-y-6" x-data="registrarSettings(@js([
    'registrars' => $registrars->map->toAdminArray()->values(),
    'domainExtensions' => $domainExtensions->map(fn ($ext) => [
        'id' => $ext->id,
        'extension' => $ext->extension,
        'enabled' => (bool) $ext->enabled,
        'legacy_registrar' => $ext->registrar,
    ])->values(),
    'drivers' => $registrarDrivers,
    'routes' => [
        'store' => route('admin.registrars.store'),
        'update' => route('admin.registrars.update', ['registrar' => '__ID__']),
        'destroy' => route('admin.registrars.destroy', ['registrar' => '__ID__']),
        'test' => route('admin.registrars.test', ['registrar' => '__ID__']),
    ],
]))">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sm:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between mb-6">
            <div>
                <h2 class="text-xl font-semibold text-slate-900 dark:text-white">Domain registrars</h2>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-400 max-w-2xl">
                    Connect registrar APIs and assign TLDs. Openprovider handles all extensions except Kenya zones (<code class="text-xs">*.ke</code>). Nameservers are taken from the customer&apos;s hosting node when linked, otherwise platform defaults.
                </p>
            </div>
            <button type="button" @click="openCreate()" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add registrar
            </button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Registrars</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white" x-text="registrars.length"></p>
            </div>
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Active</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white" x-text="registrars.filter(r => r.is_active).length"></p>
            </div>
            <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 p-4">
                <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">TLDs linked</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900 dark:text-white" x-text="registrars.reduce((sum, r) => sum + r.tld_count, 0)"></p>
            </div>
        </div>

        <template x-if="registrars.length === 0">
            <div class="rounded-lg border border-dashed border-slate-300 dark:border-slate-700 p-10 text-center">
                <p class="text-slate-700 dark:text-slate-300 font-medium">No registrars configured yet</p>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Add your first registrar to start connecting TLD APIs.</p>
                <button type="button" @click="openCreate()" class="mt-4 inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">
                    Add registrar
                </button>
            </div>
        </template>

        <template x-if="registrars.length > 0">
            <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800/80">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-300">Registrar</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-300">Driver</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-300">Environment</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-300">TLDs</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-300">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-300">Last test</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-600 dark:text-slate-300">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700 bg-white dark:bg-slate-900">
                        <template x-for="registrar in registrars" :key="registrar.id">
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900 dark:text-white" x-text="registrar.name"></div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400" x-text="registrar.slug"></div>
                                    <template x-if="registrar.is_default">
                                        <span class="mt-1 inline-flex items-center rounded-full bg-blue-100 dark:bg-blue-900/40 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300">Default</span>
                                    </template>
                                </td>
                                <td class="px-4 py-3 text-slate-700 dark:text-slate-300" x-text="registrar.driver_label"></td>
                                <td class="px-4 py-3 capitalize text-slate-700 dark:text-slate-300" x-text="registrar.environment"></td>
                                <td class="px-4 py-3 text-slate-700 dark:text-slate-300" x-text="registrar.tld_count"></td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                          :class="registrar.is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'"
                                          x-text="registrar.is_active ? 'Active' : 'Inactive'"></span>
                                </td>
                                <td class="px-4 py-3 text-slate-600 dark:text-slate-400">
                                    <template x-if="registrar.last_tested_at">
                                        <div>
                                            <div class="text-xs" x-text="new Date(registrar.last_tested_at).toLocaleString()"></div>
                                            <div class="text-xs mt-0.5 max-w-[220px] break-words"
                                                 :class="registrar.last_test_message?.startsWith('[OK]') ? 'text-green-700 dark:text-green-300' : (registrar.last_test_message?.startsWith('[FAIL]') ? 'text-red-700 dark:text-red-300' : 'text-slate-500')"
                                                 :title="registrar.last_test_message"
                                                 x-text="registrar.last_test_message"></div>
                                        </div>
                                    </template>
                                    <template x-if="!registrar.last_tested_at">
                                        <span class="text-xs">Not tested</span>
                                    </template>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" @click="testRegistrar(registrar)" :disabled="testingId === registrar.id" class="text-xs px-2.5 py-1.5 rounded-md border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 disabled:opacity-50" x-text="testingId === registrar.id ? 'Testing…' : 'Test'"></button>
                                        <button type="button" @click="openEdit(registrar)" class="text-xs px-2.5 py-1.5 rounded-md border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800">Edit</button>
                                        <button type="button" @click="deleteRegistrar(registrar)" class="text-xs px-2.5 py-1.5 rounded-md border border-red-200 dark:border-red-900 text-red-700 dark:text-red-300 hover:bg-red-50 dark:hover:bg-red-950/30">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-slate-900/60" @click="closeModal()"></div>
        <div class="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl">
            <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 px-6 py-4">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white" x-text="editingId ? 'Edit registrar' : 'Add registrar'"></h3>
                <button type="button" @click="closeModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form @submit.prevent="saveRegistrar()" class="p-6 space-y-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Name</label>
                        <input type="text" x-model="form.name" required class="block w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" placeholder="e.g. Openprovider">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Driver</label>
                        <select x-model="form.driver" @change="onDriverChange()" class="block w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                            <template x-for="driver in drivers" :key="driver.value">
                                <option :value="driver.value" x-text="driver.label"></option>
                            </template>
                        </select>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" x-text="selectedDriver?.description"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Environment</label>
                        <select x-model="form.environment" class="block w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                            <option value="sandbox">Sandbox (api.cte.openprovider.eu)</option>
                            <option value="production">Production (api.openprovider.eu)</option>
                        </select>
                        <p x-show="form.driver === 'openprovider'" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Autorenew is always sent as <strong>off</strong> to Openprovider.</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Description</label>
                        <textarea x-model="form.description" rows="2" class="block w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white" placeholder="Optional notes for admins"></textarea>
                    </div>
                </div>

                <div class="flex flex-wrap gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" x-model="form.is_active" class="rounded border-slate-300 dark:border-slate-600">
                        Active
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" x-model="form.is_default" class="rounded border-slate-300 dark:border-slate-600">
                        Default registrar
                    </label>
                </div>

                <template x-if="configFields.length > 0">
                    <fieldset class="rounded-lg border border-slate-200 dark:border-slate-700 p-4 space-y-4">
                        <legend class="px-1 text-sm font-semibold text-slate-900 dark:text-white">API configuration</legend>
                        <template x-for="field in configFields" :key="field.key">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5" x-text="field.label"></label>
                                <template x-if="field.type === 'textarea'">
                                    <textarea :name="'config[' + field.key + ']'" x-model="form.config[field.key]" rows="3" class="block w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm"></textarea>
                                </template>
                                <template x-if="field.type !== 'textarea'">
                                    <input :type="field.type" :name="'config[' + field.key + ']'" x-model="form.config[field.key]" :placeholder="field.placeholder || ''" autocomplete="off" class="block w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white font-mono text-sm">
                                </template>
                                <p x-show="field.help" class="mt-1 text-xs text-slate-500 dark:text-slate-400" x-text="field.help"></p>
                                <p x-show="field.type === 'password' && editingId" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Leave blank to keep the current value.</p>
                            </div>
                        </template>
                    </fieldset>
                </template>

                <fieldset class="rounded-lg border border-slate-200 dark:border-slate-700 p-4">
                    <legend class="px-1 text-sm font-semibold text-slate-900 dark:text-white mb-3">Assigned TLDs</legend>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-48 overflow-y-auto">
                        <template x-for="tld in domainExtensions" :key="tld.id">
                            <label class="inline-flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                <input type="checkbox" :value="tld.id" :checked="form.tld_ids.includes(tld.id)" @change="toggleTld(tld.id, $event.target.checked)" class="rounded border-slate-300 dark:border-slate-600">
                                <span x-text="tld.extension"></span>
                                <span x-show="!tld.enabled" class="text-xs text-amber-600 dark:text-amber-400">(disabled)</span>
                            </label>
                        </template>
                    </div>
                </fieldset>

                <p x-show="formError" class="text-sm text-red-600 dark:text-red-400" x-text="formError"></p>

                <div class="flex justify-end gap-3 pt-2 border-t border-slate-200 dark:border-slate-700">
                    <button type="button" @click="closeModal()" class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg">Cancel</button>
                    <button type="submit" :disabled="saving" class="px-4 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white rounded-lg" x-text="saving ? 'Saving…' : (editingId ? 'Update registrar' : 'Create registrar')"></button>
                </div>
            </form>
        </div>
    </div>
</div>

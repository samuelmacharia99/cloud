                        <div x-data="redeployStackPanel(@js($redeployStackOptions ?? null))" x-init="open = open || @js((bool) ($openRedeployModal ?? false))">
                            <button type="button" @click="open = true" class="{{ $redeployButtonClass ?? 'px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-lg font-medium transition hover:bg-slate-200 dark:hover:bg-slate-600' }}">
                                {{ $redeployButtonLabel ?? 'Redeploy stack' }}
                            </button>

                            <template x-teleport="body">
                                <div
                                    x-show="open"
                                    x-cloak
                                    class="fixed inset-0 z-[80] flex items-center justify-center p-4 bg-black/50"
                                    @keydown.escape.window="open = false"
                                >
                                    <div
                                        class="w-full max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 shadow-xl p-6"
                                        @click.outside="open = false"
                                    >
                                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">{{ $redeployButtonLabel ?? 'Redeploy stack' }}</h3>
                                        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1 mb-4">
                                            Recreate the application runtime. Files in <code class="font-mono text-xs">/app</code> are kept unless you reset the database.
                                            @if (!empty($redeployStackOptions))
                                                Choose frontend and database the same way as when you first deployed.
                                            @endif
                                            @if (($templateSlug ?? '') === 'nodejs')
                                                Expo/React Native apps are not a browser frontend — choose <strong>None</strong> to deploy the API, or point the frontend directory at a web app such as <code class="font-mono text-xs">apps/web</code>.
                                            @endif
                                        </p>

                                        <form method="POST" action="{{ $redeployFormAction ?? container_route('redeploy', $service) }}" @submit.prevent="submitRedeploy($el)">
                                            @csrf

                                            @if (!empty($redeployStackOptions) && empty($redeployStackOptions['skip_modal']))
                                                <template x-if="options.framework?.show">
                                                    <div class="mb-4">
                                                        <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2">
                                                            Framework
                                                            <span class="text-red-500" x-show="options.framework.required">*</span>
                                                        </p>
                                                        <div class="space-y-2">
                                                            <template x-for="option in options.framework.options" :key="option.value">
                                                                <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                                                    :class="selectedFramework === option.value
                                                                        ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                                        : 'border-slate-200 dark:border-slate-700 hover:border-blue-400'">
                                                                    <div class="flex items-center gap-3">
                                                                        <input type="radio" name="framework" :value="option.value" x-model="selectedFramework" @change="onFrameworkChange()" class="mt-0.5">
                                                                        <span class="font-medium text-slate-900 dark:text-white" x-text="option.label"></span>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>

                                                <template x-if="options.frontend?.show">
                                                    <div class="mb-4">
                                                        <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2">
                                                            Frontend
                                                            <span class="text-red-500" x-show="options.frontend.required">*</span>
                                                        </p>
                                                        <div class="space-y-2">
                                                            <template x-for="option in options.frontend.options" :key="option.value">
                                                                <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                                                    :class="selectedFrontend === option.value
                                                                        ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                                        : 'border-slate-200 dark:border-slate-700 hover:border-blue-400'">
                                                                    <div class="flex items-center gap-3">
                                                                        <input type="radio" name="frontend" :value="option.value" x-model="selectedFrontend" :disabled="option.locked && options.frontend.options.length === 1" class="mt-0.5">
                                                                        <span class="font-medium text-slate-900 dark:text-white" x-text="option.label"></span>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                        </div>
                                                        <p class="text-xs text-amber-700 dark:text-amber-300 mt-2" x-show="selectedFrontend && selectedFrontend !== 'none'" x-text="options.frontend.deferred_note"></p>
                                                    </div>
                                                </template>

                                                @if (in_array($templateSlug ?? '', ['nodejs', 'python', 'ruby', 'go'], true))
                                                    <div class="mb-4 rounded-lg border border-slate-200 dark:border-slate-700 p-3">
                                                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Advanced workload roots</p>
                                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1 mb-3">
                                                            Leave blank to detect both applications. A split stack needs two directories (for example apps/api and apps/web). If they are the same, this host runs the API only.
                                                            If Frontend is None, only the API directory is used.
                                                        </p>
                                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                            <label class="text-xs font-medium text-slate-700 dark:text-slate-300">
                                                                Backend directory
                                                                <input type="text" name="backend_root" x-model="backendRoot" placeholder="Auto (for example apps/api)"
                                                                    class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
                                                            </label>
                                                            <label class="text-xs font-medium text-slate-700 dark:text-slate-300">
                                                                Frontend directory
                                                                <input type="text" name="frontend_root" x-model="frontendRoot" placeholder="Auto (for example apps/web)"
                                                                    class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-600 dark:bg-slate-800 text-sm">
                                                            </label>
                                                        </div>
                                                        <template x-if="options.current?.node_workloads?.topology === 'split_web_api'">
                                                            <p class="mt-3 text-xs text-emerald-700 dark:text-emerald-300">
                                                                Current detection:
                                                                <code x-text="options.current.node_workloads.backend?.root"></code>
                                                                + <code x-text="options.current.node_workloads.frontend?.root"></code>
                                                            </p>
                                                        </template>
                                                    </div>
                                                @endif

                                                <template x-if="options.version_picker?.show">
                                                    <div class="mb-4">
                                                        <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2" x-text="options.version_picker.label || 'Runtime version'"></p>
                                                        <p class="text-xs text-slate-500 dark:text-slate-400 mb-2" x-show="options.version_picker.help" x-text="options.version_picker.help"></p>
                                                        <div class="space-y-2">
                                                            <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                                                :class="selectedVersion === ''
                                                                    ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                                    : 'border-slate-200 dark:border-slate-700 hover:border-blue-400'">
                                                                <div class="flex items-start gap-3">
                                                                    <input type="radio" name="selected_version" value="" x-model="selectedVersion" class="mt-1">
                                                                    <div>
                                                                        <span class="font-semibold text-slate-900 dark:text-white">Auto detect</span>
                                                                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Use package.json <code class="font-mono">engines.node</code> on deploy and every Git pull.</p>
                                                                    </div>
                                                                </div>
                                                            </label>
                                                            <template x-for="option in options.version_picker.options" :key="option.value">
                                                                <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                                                    :class="selectedVersion === option.value
                                                                        ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                                        : 'border-slate-200 dark:border-slate-700 hover:border-blue-400'">
                                                                    <div class="flex items-start gap-3">
                                                                        <input type="radio" name="selected_version" :value="option.value" x-model="selectedVersion" class="mt-1">
                                                                        <div>
                                                                            <span class="font-semibold text-slate-900 dark:text-white" x-text="option.label"></span>
                                                                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-show="option.description" x-text="option.description"></p>
                                                                        </div>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>

                                                <template x-if="options.database?.show">
                                                    <div class="mb-4">
                                                        <p class="text-sm font-semibold text-slate-900 dark:text-white mb-2">
                                                            Database
                                                            <span class="text-red-500" x-show="options.database.required">*</span>
                                                        </p>
                                                        <div class="space-y-2">
                                                            <template x-if="options.database.allow_none">
                                                                <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                                                    :class="selectedDatabaseId === ''
                                                                        ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                                        : 'border-slate-200 dark:border-slate-700 hover:border-blue-400'">
                                                                    <div class="flex items-center gap-3">
                                                                        <input type="radio" name="database_id" value="" x-model="selectedDatabaseId" class="mt-0.5">
                                                                        <span class="font-medium text-slate-900 dark:text-white">None</span>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                            <template x-for="db in options.database.options" :key="db.id">
                                                                <label class="block p-3 border-2 rounded-lg cursor-pointer transition-all"
                                                                    :class="String(selectedDatabaseId) === String(db.id)
                                                                        ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800'
                                                                        : 'border-slate-200 dark:border-slate-700 hover:border-blue-400'">
                                                                    <div class="flex items-start gap-3">
                                                                        <input type="radio" name="database_id" :value="db.id" x-model="selectedDatabaseId" class="mt-1">
                                                                        <div>
                                                                            <span class="font-semibold text-slate-900 dark:text-white" x-text="db.name"></span>
                                                                            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1" x-text="'Type: ' + db.type"></p>
                                                                        </div>
                                                                    </div>
                                                                </label>
                                                            </template>
                                                        </div>
                                                        <p class="text-xs text-amber-700 dark:text-amber-300 mt-2" x-show="databaseChanged">
                                                            Changing the database will wipe the current database volume on redeploy.
                                                        </p>
                                                    </div>
                                                </template>
                                            @endif

                                            <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300 mb-2">
                                                <input type="checkbox" name="replace_application" value="1" class="rounded border-slate-300 dark:border-slate-600 mt-0.5">
                                                <span>Replace application files (clones the connected Git repo / Open Source POS into /app; keeps uploads)</span>
                                            </label>
                                            <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300 mb-4">
                                                <input type="checkbox" name="reset_database" value="1" x-model="resetDatabase" :disabled="databaseChanged" class="rounded border-slate-300 dark:border-slate-600 mt-0.5">
                                                <span>Reset database (deletes all DB data)</span>
                                            </label>
                                            <template x-if="databaseChanged">
                                                <input type="hidden" name="reset_database" value="1">
                                            </template>

                                            <div class="flex gap-2">
                                                <button type="button" @click="open = false" class="btn-secondary flex-1 btn-sm">Cancel</button>
                                                <button type="submit" class="flex-1 px-3 py-2 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-sm font-medium">
                                                    {{ $redeploySubmitLabel ?? 'Redeploy' }}
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </template>
                        </div>

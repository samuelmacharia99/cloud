@php
    $environmentPanel = $environmentPanel ?? ['variables' => [], 'can_save' => false, 'can_apply' => false, 'applies_dotenv' => false];
    $canSaveEnvironment = ! empty($environmentPanel['can_save']) || ! empty($environmentPanel['can_apply']);
@endphp

<div
    class="space-y-6"
    x-data="containerEnvironmentPanel(@js($environmentPanel['variables'] ?? []))"
>
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
            <h3 class="text-xl font-bold text-slate-900 dark:text-white">Environment &amp; secrets</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Manage runtime variables without digging through Files.
                @if (! empty($environmentPanel['applies_dotenv']))
                    Changes also sync into your app <code class="font-mono text-xs">.env</code> when present.
                @endif
                Applying changes restarts the app briefly.
            </p>
        </div>
    </div>

    <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-100">
        Platform-managed database keys are editable but tied to your sidecar. Changing them restarts the stack and may require credential repair from the Database tab.
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 p-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h4 class="font-semibold text-slate-900 dark:text-white">Import a .env file</h4>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Autofills the table below and overwrites matching keys. Existing keys absent from the file are retained.
                    The file is parsed in your browser and is not uploaded separately.
                </p>
            </div>
            <div class="shrink-0">
                <input
                    x-ref="dotenvFile"
                    type="file"
                    accept=".env,text/plain"
                    class="hidden"
                    @change="importDotEnv($event)"
                >
                <button
                    type="button"
                    @click="$refs.dotenvFile.click()"
                    :disabled="importing || saving"
                    class="inline-flex items-center px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-100 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600 disabled:opacity-50 disabled:cursor-not-allowed"
                    @if (! $canSaveEnvironment) disabled @endif
                >
                    <span x-text="importing ? 'Reading…' : 'Choose .env file'"></span>
                </button>
            </div>
        </div>

        <p
            x-show="importMessage"
            x-text="importMessage"
            class="mt-3 text-sm"
            :class="importError ? 'text-red-600 dark:text-red-400' : 'text-emerald-700 dark:text-emerald-400'"
        ></p>
    </div>

    @if (! $canSaveEnvironment)
        <div class="rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 p-4 text-sm text-slate-700 dark:text-slate-300">
            Environment editing is unavailable until this application is deployed.
            @if (! empty($environmentPanel['deployment_status']))
                <span class="font-mono text-xs">(status: {{ $environmentPanel['deployment_status'] }})</span>
            @endif
        </div>
    @endif

    <form
        method="POST"
        action="{{ route('customer.services.container.environment.update', $service) }}"
        class="space-y-4"
        @submit="prepareSubmit($event)"
    >
        @csrf
        @method('PUT')
        <input type="hidden" name="restart" value="1">

        <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800/80 text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Key</th>
                        <th class="px-4 py-3 font-semibold">Value</th>
                        <th class="px-4 py-3 font-semibold w-28"> </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <template x-for="(row, index) in rows" :key="row._id">
                        <tr class="bg-white dark:bg-slate-900">
                            <td class="px-4 py-3 align-top">
                                <input
                                    type="text"
                                    :name="'variables[' + index + '][key]'"
                                    x-model="row.key"
                                    :readonly="row.platform_managed && !row.isNew"
                                    class="w-full min-w-[10rem] px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 font-mono text-xs text-slate-900 dark:text-white"
                                    placeholder="MY_KEY"
                                    required
                                >
                                <p x-show="row.platform_managed" class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Platform-managed</p>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex gap-2">
                                    <input
                                        :type="row.sensitive && !row.reveal ? 'password' : 'text'"
                                        :name="'variables[' + index + '][value]'"
                                        x-model="row.value"
                                        class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 font-mono text-xs text-slate-900 dark:text-white"
                                        placeholder="value"
                                    >
                                    <button
                                        type="button"
                                        x-show="row.sensitive"
                                        @click="row.reveal = !row.reveal"
                                        class="shrink-0 px-2 py-1 text-xs text-slate-600 dark:text-slate-300 hover:underline"
                                        x-text="row.reveal ? 'Hide' : 'Show'"
                                    ></button>
                                </div>
                            </td>
                            <td class="px-4 py-3 align-top text-right">
                                <button
                                    type="button"
                                    x-show="!row.platform_managed"
                                    @click="removeRow(index)"
                                    class="text-xs text-red-600 dark:text-red-400 hover:underline"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="rows.length === 0">
                        <td colspan="3" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">
                            No environment variables yet. Add your first key below.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex flex-wrap gap-2 items-center">
            <button type="button" @click="addRow()" class="px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-100 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600" @if (! $canSaveEnvironment) disabled @endif>
                Add variable
            </button>
            <button
                type="submit"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                @if (! $canSaveEnvironment) disabled @endif
                x-bind:disabled="saving"
            >
                <span x-text="saving ? 'Saving…' : 'Save & apply'"></span>
            </button>
            <p class="text-xs text-slate-500 dark:text-slate-400" x-show="saving">
                Saving can take a minute while the stack restarts…
            </p>
        </div>
    </form>
</div>

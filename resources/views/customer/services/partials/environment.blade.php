@php
    $environmentPanel = $environmentPanel ?? ['variables' => [], 'can_apply' => false, 'applies_dotenv' => false];
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
                Applying changes restarts the container briefly.
            </p>
        </div>
    </div>

    <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-sm text-amber-900 dark:text-amber-100">
        Platform-managed database keys are editable but tied to your sidecar. Changing them restarts the stack and may require credential repair from the Database tab.
    </div>

    <form
        method="POST"
        action="{{ route('customer.services.container.environment.update', $service) }}"
        class="space-y-4"
        @submit="prepareSubmit"
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
                    <template x-for="(row, index) in rows" :key="index">
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

        <div class="flex flex-wrap gap-2">
            <button type="button" @click="addRow()" class="px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-800 dark:text-slate-100 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-600">
                Add variable
            </button>
            <button
                type="submit"
                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium"
                @if (empty($environmentPanel['can_apply'])) disabled @endif
            >
                Save &amp; apply
            </button>
        </div>
    </form>
</div>

<script>
function containerEnvironmentPanel(initialRows) {
    return {
        rows: (initialRows || []).map((row) => ({
            key: row.key || '',
            value: row.value || '',
            sensitive: !!row.sensitive,
            platform_managed: !!row.platform_managed,
            reveal: false,
            isNew: false,
        })),
        addRow() {
            this.rows.push({
                key: '',
                value: '',
                sensitive: false,
                platform_managed: false,
                reveal: true,
                isNew: true,
            });
        },
        removeRow(index) {
            const row = this.rows[index];
            if (!row) return;
            if (row.platform_managed && !row.isNew) return;

            if (row.isNew || !row.key) {
                this.rows.splice(index, 1);
                return;
            }

            if (!confirm(`Remove ${row.key}? The container will restart to apply.`)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = @js(route('customer.services.container.environment.delete', $service));
            form.innerHTML = `
                <input type="hidden" name="_token" value="${document.querySelector('meta[name=csrf-token]')?.content || ''}">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="keys[]" value="${row.key}">
                <input type="hidden" name="restart" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        },
        prepareSubmit() {
            this.rows.forEach((row) => {
                row.key = (row.key || '').trim().toUpperCase();
            });
        },
    };
}
</script>

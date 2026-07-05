@if ($deployment)
<div id="cron" class="space-y-8">
    <div>
        <h3 class="text-xl font-bold text-slate-900 dark:text-white">Cron Jobs</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2 max-w-3xl">
            Schedule commands to run inside your container automatically. Jobs are managed here and executed by the platform
            (you cannot use <code class="font-mono text-xs">crontab -e</code> in the terminal).
            Use standard five-field schedules, e.g. <code class="font-mono text-xs">*/5 * * * *</code> or <code class="font-mono text-xs">0 2 * * *</code>.
        </p>
    </div>

    @error('cron')
        <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/30 px-4 py-3 text-sm text-red-800 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    @if ($deployment->status !== 'running')
        <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-800 dark:text-amber-200 text-sm">
            Start the container before adding cron jobs. Scheduled commands only run while the container is active.
        </div>
    @endif

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 flex items-center justify-between gap-3">
            <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Scheduled jobs</h4>
            <span class="text-xs text-slate-500 dark:text-slate-400">{{ count($containerCronJobs ?? []) }} / {{ config('containers.cron.max_jobs_per_service', 20) }}</span>
        </div>

        @if (! empty($containerCronJobs))
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-400">Name</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-400">Schedule</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-400">Command</th>
                            <th class="px-4 py-3 text-left font-medium text-slate-600 dark:text-slate-400">Last run</th>
                            <th class="px-4 py-3 text-right font-medium text-slate-600 dark:text-slate-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700 bg-white dark:bg-slate-800">
                        @foreach ($containerCronJobs as $job)
                            <tr>
                                <td class="px-4 py-3 align-top">
                                    <div class="font-medium text-slate-900 dark:text-white">{{ $job->name }}</div>
                                    @if (! $job->enabled)
                                        <span class="inline-flex mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">Disabled</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top font-mono text-xs text-slate-700 dark:text-slate-300">{{ $job->schedule }}</td>
                                <td class="px-4 py-3 align-top font-mono text-xs text-slate-700 dark:text-slate-300 break-all">{{ $job->command }}</td>
                                <td class="px-4 py-3 align-top text-xs text-slate-600 dark:text-slate-400">
                                    @if ($job->last_run_at)
                                        <div>{{ $job->last_run_at->diffForHumans() }}</div>
                                        <div class="{{ $job->last_status === 'success' ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ ucfirst($job->last_status ?? 'unknown') }}
                                        </div>
                                        @if ($job->last_output)
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-blue-600 dark:text-blue-400">Output</summary>
                                                <pre class="mt-1 whitespace-pre-wrap break-all text-[11px] text-slate-600 dark:text-slate-400">{{ $job->last_output }}</pre>
                                            </details>
                                        @endif
                                    @else
                                        <span>Not run yet</span>
                                        @if ($job->next_run_at)
                                            <div class="mt-1">Next: {{ $job->next_run_at->format('M d, H:i') }}</div>
                                        @endif
                                    @endif
                                </td>
                                <td class="px-4 py-3 align-top text-right whitespace-nowrap">
                                    <details class="inline-block text-left">
                                        <summary class="cursor-pointer text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">Edit</summary>
                                        <form method="POST" action="{{ route('customer.services.container.cron-jobs.update', [$service, $job]) }}" class="mt-3 p-4 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40 space-y-3 min-w-[18rem]">
                                            @csrf
                                            @method('PUT')
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Name</label>
                                                <input type="text" name="name" value="{{ old('name', $job->name) }}" required class="w-full px-3 py-2 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Schedule</label>
                                                <input type="text" name="schedule" value="{{ old('schedule', $job->schedule) }}" required class="w-full px-3 py-2 text-sm font-mono rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 dark:text-slate-400 mb-1">Command</label>
                                                <input type="text" name="command" value="{{ old('command', $job->command) }}" required class="w-full px-3 py-2 text-sm font-mono rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800">
                                            </div>
                                            <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                                                <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $job->enabled)) class="rounded border-slate-300 dark:border-slate-600">
                                                Enabled
                                            </label>
                                            <button type="submit" class="w-full px-3 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white">Save changes</button>
                                        </form>
                                    </details>
                                    <form method="POST" action="{{ route('customer.services.container.cron-jobs.delete', [$service, $job]) }}" class="inline-block ml-2" data-confirm="Delete this cron job?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-medium text-red-600 dark:text-red-400 hover:underline">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="p-5 text-sm text-slate-600 dark:text-slate-400">No cron jobs yet. Add one below.</div>
        @endif
    </div>

    @if (count($containerCronJobs ?? []) < config('containers.cron.max_jobs_per_service', 20))
        <form method="POST" action="{{ route('customer.services.container.cron-jobs.store', $service) }}" class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-5 space-y-4">
            @csrf
            <h4 class="text-sm font-semibold text-slate-900 dark:text-white">Add cron job</h4>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="cron_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Name</label>
                    <input id="cron_name" type="text" name="name" value="{{ old('name') }}" required placeholder="Laravel scheduler" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900">
                </div>
                <div>
                    <label for="cron_schedule" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Schedule</label>
                    <input id="cron_schedule" type="text" name="schedule" value="{{ old('schedule', '*/5 * * * *') }}" required placeholder="*/5 * * * *" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 font-mono text-sm">
                </div>
            </div>

            <div>
                <label for="cron_command" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Command</label>
                <input id="cron_command" type="text" name="command" value="{{ old('command', 'php artisan schedule:run') }}" required placeholder="php artisan schedule:run" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 font-mono text-sm">
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                    Allowed prefixes: php, node, npm run, yarn, python, bundle exec. No shell operators (<code class="font-mono">;</code>, <code class="font-mono">|</code>, <code class="font-mono">&amp;</code>).
                </p>
            </div>

            <div class="flex justify-end">
                <button type="submit" @disabled($deployment->status !== 'running') class="px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium">
                    Add cron job
                </button>
            </div>
        </form>
    @endif
</div>
@endif

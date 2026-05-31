@if (!empty($supportsGitRepository) && $deployment)
@php
    $gitUrl = $gitRepository['url'] ?? '';
    $gitBranch = $gitRepository['branch'] ?? 'main';
    $gitSyncedAt = $gitRepository['synced_at'] ?? null;
@endphp
<div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900/40 p-6 space-y-5">
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="max-w-3xl">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">GitHub repository</h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">
                Connect a Git repository and pull the latest code into <code class="font-mono text-xs">/app</code>
                without using the terminal. Use an HTTPS URL; for private repositories include a personal access token in the URL.
            </p>
        </div>
    </div>

    <form method="POST" action="{{ route('customer.services.container.git-repository.update', $service) }}" class="grid lg:grid-cols-2 gap-4">
        @csrf
        <div>
            <label for="source_repo_url" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Repository URL</label>
            <input
                id="source_repo_url"
                type="url"
                name="source_repo_url"
                value="{{ old('source_repo_url', $gitUrl) }}"
                placeholder="https://github.com/your-org/your-app.git"
                required
                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-950 px-3 py-2 text-sm"
            >
        </div>
        <div>
            <label for="source_repo_branch" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Branch</label>
            <input
                id="source_repo_branch"
                type="text"
                name="source_repo_branch"
                value="{{ old('source_repo_branch', $gitBranch) }}"
                placeholder="main"
                class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-950 px-3 py-2 text-sm"
            >
        </div>
        <div class="lg:col-span-2">
            <button
                type="submit"
                class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 text-white text-sm font-medium"
            >
                Save repository
            </button>
        </div>
    </form>

    @if ($gitUrl !== '')
        <div class="border-t border-slate-200 dark:border-slate-700 pt-5 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="text-sm text-slate-600 dark:text-slate-300">
                    <p><span class="font-medium text-slate-900 dark:text-white">Connected:</span> {{ $gitUrl }}</p>
                    <p><span class="font-medium text-slate-900 dark:text-white">Branch:</span> {{ $gitBranch }}</p>
                    @if ($gitSyncedAt)
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Last synced {{ \Illuminate\Support\Carbon::parse($gitSyncedAt)->diffForHumans() }}</p>
                    @endif
                </div>
            </div>

            <form
                method="POST"
                action="{{ route('customer.services.container.git-repository.pull', $service) }}"
                class="space-y-3"
                onsubmit="return confirm('Pull the latest code from Git into /app?');"
            >
                @csrf
                <div class="flex flex-col gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="replace_existing" value="1" class="rounded border-slate-300 dark:border-slate-600">
                        Replace /app contents on first clone (required if /app was created by Initialize Laravel)
                    </label>
                    @if (!empty($isLaravelTemplate))
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="run_composer" value="1" checked class="rounded border-slate-300 dark:border-slate-600">
                            Run <code class="font-mono text-xs">composer install</code> after pull
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="run_migrations" value="1" checked class="rounded border-slate-300 dark:border-slate-600">
                            Run <code class="font-mono text-xs">php artisan migrate</code> after pull
                        </label>
                    @endif
                </div>
                <button
                    type="submit"
                    @disabled(!$deployment->isRunning())
                    class="px-5 py-2.5 rounded-lg font-medium transition {{ $deployment->isRunning() ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-slate-300 dark:bg-slate-700 text-slate-500 cursor-not-allowed' }}"
                >
                    ⬇️ Pull latest from Git
                </button>
            </form>
        </div>
    @endif
</div>
@endif

@if (!empty($hermesDashboardPanel))
<div
    class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/70 dark:bg-amber-950/20 p-6"
    x-data="{ revealPassword: false }"
>
    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="max-w-3xl">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Hermes dashboard</h3>
            <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">
                The control UI already runs in this container. Open it, then sign in with the username and password below.
                You do not register this service on a separate Hermes site.
            </p>
        </div>
        @if (!empty($hermesDashboardPanel['url']) && !empty($hermesDashboardPanel['container_running']))
            <a
                href="{{ $hermesDashboardPanel['url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="shrink-0 px-4 py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-lg font-medium transition"
                onclick="window.open(this.href, '_blank'); return false;"
            >
                Open dashboard
            </a>
        @elseif (empty($hermesDashboardPanel['container_running']))
            <span class="shrink-0 px-4 py-2.5 bg-slate-200 dark:bg-slate-700 text-slate-500 dark:text-slate-400 rounded-lg font-medium">
                Start the app first
            </span>
        @endif
    </div>

    <dl class="mt-5 grid sm:grid-cols-2 gap-4 text-sm">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Username</dt>
            <dd class="mt-1 font-mono text-slate-900 dark:text-white">{{ $hermesDashboardPanel['username'] }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Password</dt>
            <dd class="mt-1 flex items-center gap-2">
                <code class="font-mono text-slate-900 dark:text-white break-all">{{ $hermesDashboardPanel['password'] !== '' ? '••••••••' : 'Not generated yet — apply Environment once.' }}</code>
                @if ($hermesDashboardPanel['password'] !== '')
                    <button
                        type="button"
                        class="text-xs font-medium text-amber-800 dark:text-amber-200 hover:underline"
                        @click="revealPassword = !revealPassword"
                        x-text="revealPassword ? 'Hide' : 'Reveal'"
                    ></button>
                @endif
            </dd>
            <p
                x-show="revealPassword"
                x-cloak
                class="mt-2 font-mono text-xs break-all text-slate-900 dark:text-white bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg px-3 py-2"
            >{{ $hermesDashboardPanel['password'] }}</p>
        </div>
    </dl>

    @include('customer.services.partials.hermes-ollama-link')

    <p class="mt-4 text-xs text-slate-600 dark:text-slate-400">
        Change these under <strong>Environment</strong> (<code class="font-mono">HERMES_DASHBOARD_BASIC_AUTH_USERNAME</code>
        and <code class="font-mono">HERMES_DASHBOARD_BASIC_AUTH_PASSWORD</code>), then apply so the container restarts.
    </p>
</div>
@endif

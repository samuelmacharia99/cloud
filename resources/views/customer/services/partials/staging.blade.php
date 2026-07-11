@php
    $staging = $stagingPanel ?? ['staging_service_id' => null, 'staging_name' => null, 'candidates' => []];
@endphp
<div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-900/40 p-5 space-y-4">
    <div>
        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Staging environment</h3>
        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">
            Link another container on the same stack as a staging sibling. Deploy a second plan from
            <a href="{{ route('customer.select-techstack') }}" class="text-blue-600 dark:text-blue-400 hover:underline">App Hosting</a>
            if you do not have one yet.
        </p>
    </div>

    @if (!empty($staging['staging_service_id']))
        <p class="text-sm text-slate-700 dark:text-slate-300">
            Linked staging:
            <a href="{{ route('customer.services.container.show', $staging['staging_service_id']) }}" class="font-medium text-blue-600 dark:text-blue-400 hover:underline">
                {{ $staging['staging_name'] ?? ('#'.$staging['staging_service_id']) }}
            </a>
        </p>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('customer.services.container.staging.update', $service) }}">
                @csrf
                <input type="hidden" name="action" value="sync_env">
                <button type="submit" class="px-3 py-1.5 text-sm rounded-lg bg-violet-600 hover:bg-violet-700 text-white">Sync env to staging</button>
            </form>
            <form method="POST" action="{{ route('customer.services.container.staging.update', $service) }}">
                @csrf
                <input type="hidden" name="action" value="unlink">
                <button type="submit" class="px-3 py-1.5 text-sm rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300">Unlink</button>
            </form>
        </div>
    @elseif (!empty($staging['candidates']))
        <form method="POST" action="{{ route('customer.services.container.staging.update', $service) }}" class="flex flex-col sm:flex-row gap-2">
            @csrf
            <input type="hidden" name="action" value="link">
            <select name="staging_service_id" required class="flex-1 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 px-3 py-2 text-sm">
                @foreach ($staging['candidates'] as $candidate)
                    <option value="{{ $candidate['id'] }}">#{{ $candidate['id'] }} — {{ $candidate['name'] }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-slate-900 dark:bg-slate-700 text-white">Link staging</button>
        </form>
    @else
        <p class="text-sm text-slate-500 dark:text-slate-400">No other same-stack containers available to link.</p>
    @endif
</div>

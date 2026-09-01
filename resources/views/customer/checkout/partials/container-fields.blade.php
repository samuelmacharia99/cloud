<div class="space-y-4">
    @php
        $supportsGit = app(\App\Services\Provisioning\ContainerGitRepositoryService::class)
            ->supportsTemplate($template->slug ?? null);
    @endphp
    @if ($supportsGit)
        <p class="text-sm text-slate-600 dark:text-slate-400">
            Optional now — you can connect Git after checkout from the app console (Git tab), then manage secrets under <strong>Environment</strong>.
        </p>
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Source Repository URL</label>
            <input type="url" name="source_repo_url[{{ $product['key'] }}]" value="{{ old("source_repo_url.{$product['key']}") }}"
                placeholder="https://github.com/your-org/your-app.git"
                class="w-full px-4 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-900 dark:text-white">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Source Branch</label>
            <input type="text" name="source_repo_branch[{{ $product['key'] }}]" value="{{ old("source_repo_branch.{$product['key']}", 'main') }}"
                class="w-full px-4 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-900 dark:text-white">
        </div>
    @else
        <p class="text-sm text-slate-600 dark:text-slate-400">
            This stack is provisioned by the platform. Connect domains and manage content from the app console after checkout.
        </p>
    @endif
    @if ($template->versions && count($template->versions) > 0)
        @php
            $versionPicker = \App\Services\TechStackRoutingService::versionPickerPayload($template);
            $selectedVersion = old("selected_version.{$key}", session('selected_techstack.selected_version'));
        @endphp
        <div>
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                {{ $versionPicker['show'] ? $versionPicker['label'] : 'Select Version' }}
            </label>
            <select name="selected_version[{{ $key }}]" required
                class="w-full px-4 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-900 dark:text-white">
                <option value="">{{ $versionPicker['show'] ? '— Choose a model size —' : '-- Choose a version --' }}</option>
                @foreach($template->versions as $version)
                    <option value="{{ $version }}" @selected($selectedVersion === $version)>
                        {{ $versionPicker['show']
                            ? \App\Services\TechStackRoutingService::versionLabel($template, $version)
                            : $version }}
                    </option>
                @endforeach
            </select>
            @if($versionPicker['show'] && $versionPicker['help'])
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5">{{ $versionPicker['help'] }}</p>
            @endif
        </div>
    @endif
    @if ($template->environment_variables)
        @foreach($template->environment_variables as $envVar)
            @php
                $fieldName = "env_values[{$key}][{$envVar['key']}]";
            @endphp
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">{{ $envVar['label'] ?? $envVar['key'] }}</label>
                <input type="{{ ($envVar['secret'] ?? false) ? 'password' : 'text' }}" name="{{ $fieldName }}"
                    value="{{ old($fieldName, $envVar['default'] ?? '') }}"
                    class="w-full px-4 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-900 dark:text-white">
            </div>
        @endforeach
    @endif
</div>

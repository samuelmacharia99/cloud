@if (!empty($bundledContainerItems))
    <div class="ui-card p-6">
        <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-2">App &amp; email domain</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">
            This plan includes email. Enter the domain you will use for your application — the same domain is used for mailboxes.
        </p>
        <div class="space-y-4">
            @foreach ($bundledContainerItems as $entry)
                @php $key = $entry['key']; @endphp
                <div class="rounded-lg border border-slate-200 dark:border-slate-700 p-4">
                    <p class="text-sm font-medium text-slate-900 dark:text-white mb-1">
                        {{ $entry['product']->name }}
                        <span class="text-slate-500 font-normal">+ {{ $entry['email_product']->name }}</span>
                    </p>
                    <label for="bundle_primary_domain_{{ $key }}" class="block text-xs text-slate-500 dark:text-slate-400 mb-1">Primary domain</label>
                    <input
                        type="text"
                        id="bundle_primary_domain_{{ $key }}"
                        name="bundle_primary_domain[{{ $key }}]"
                        value="{{ old('bundle_primary_domain.'.$key) }}"
                        placeholder="example.com"
                        required
                        class="w-full px-3 py-2 text-sm border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg text-slate-900 dark:text-white @error('bundle_primary_domain.'.$key) border-red-500 @enderror"
                    >
                    @error('bundle_primary_domain.'.$key)
                        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="pt-6 border-t border-slate-200 dark:border-slate-800">
    <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-1">DirectAdmin</h3>
    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
        Reseller account on the hosting panel. Used to suspend or unsuspend the reseller on the server and to count hosted users against package limits.
    </p>

    <div class="space-y-4">
        <div>
            <label for="directadmin_username" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                DirectAdmin username
                <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span>
            </label>
            <input
                type="text"
                id="directadmin_username"
                name="directadmin_username"
                value="{{ old('directadmin_username', $user->directadmin_username ?? '') }}"
                placeholder="e.g. reseller_acme"
                autocomplete="off"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm font-mono @error('directadmin_username') border-red-500 @enderror"
            >
            @error('directadmin_username')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Letters, numbers, and underscores only. Must match the reseller login on DirectAdmin.</p>
        </div>

        <div>
            <label for="reseller_node_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                DirectAdmin node
                <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span>
            </label>
            <select
                id="reseller_node_id"
                name="reseller_node_id"
                class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('reseller_node_id') border-red-500 @enderror"
            >
                <option value="">— Auto-detect from managed services —</option>
                @foreach ($directAdminNodes as $node)
                    <option value="{{ $node->id }}" @selected(old('reseller_node_id', $user->reseller_node_id ?? null) == $node->id)>
                        {{ $node->name }} ({{ $node->hostname }})
                    </option>
                @endforeach
            </select>
            @error('reseller_node_id')
                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
            @if ($directAdminNodes->isEmpty())
                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                    No active DirectAdmin nodes. <a href="{{ route('admin.nodes.create', ['type' => 'directadmin']) }}" class="underline">Add a node</a> to enable API actions.
                </p>
            @endif
        </div>
    </div>
</div>

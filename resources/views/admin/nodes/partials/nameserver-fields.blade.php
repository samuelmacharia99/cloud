@props([
    'node' => null,
    'required' => false,
])

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div>
        <label for="nameserver_1" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
            NS1 @if ($required)<span class="text-red-500">*</span>@endif
        </label>
        <input
            type="text"
            id="nameserver_1"
            name="nameserver_1"
            value="{{ old('nameserver_1', $node?->nameserver_1) }}"
            placeholder="ns1.example.com"
            @if ($required) required @endif
            class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('nameserver_1') border-red-500 @enderror"
        >
        @error('nameserver_1')<p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="nameserver_2" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">NS2</label>
        <input type="text" id="nameserver_2" name="nameserver_2" value="{{ old('nameserver_2', $node?->nameserver_2) }}" placeholder="ns2.example.com" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
    </div>
    <div>
        <label for="nameserver_3" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">NS3 <span class="text-slate-400 font-normal">(optional)</span></label>
        <input type="text" id="nameserver_3" name="nameserver_3" value="{{ old('nameserver_3', $node?->nameserver_3) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
    </div>
    <div>
        <label for="nameserver_4" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">NS4 <span class="text-slate-400 font-normal">(optional)</span></label>
        <input type="text" id="nameserver_4" name="nameserver_4" value="{{ old('nameserver_4', $node?->nameserver_4) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm">
    </div>
</div>

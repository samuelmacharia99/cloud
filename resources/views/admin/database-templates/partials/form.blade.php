<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Name</label>
        <input type="text" name="name" value="{{ old('name', $template->name ?? '') }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" required>
        @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Slug</label>
        <input type="text" name="slug" value="{{ old('slug', $template->slug ?? '') }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" required>
        @error('slug') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Type</label>
        <select name="type" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" required>
            @php $type = old('type', $template->type ?? 'mysql'); @endphp
            @foreach(['mysql','mariadb','postgresql','mongodb','redis'] as $option)
                <option value="{{ $option }}" @selected($type === $option)>{{ strtoupper($option) }}</option>
            @endforeach
        </select>
        @error('type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Description</label>
        <textarea name="description" rows="2" class="w-full rounded-lg border-slate-300 dark:bg-slate-800">{{ old('description', $template->description ?? '') }}</textarea>
        @error('description') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Docker Image</label>
        <input type="text" name="docker_image" value="{{ old('docker_image', $template->docker_image ?? '') }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" required>
        @error('docker_image') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Default Port</label>
        <input type="number" name="default_port" value="{{ old('default_port', $template->default_port ?? 3306) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" required>
        @error('default_port') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Required RAM (MB)</label>
        <input type="number" name="required_ram_mb" value="{{ old('required_ram_mb', $template->required_ram_mb ?? 256) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" required>
        @error('required_ram_mb') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Hosting Type</label>
        @php $hostingType = old('hosting_type', $template->hosting_type ?? 'container'); @endphp
        <select name="hosting_type" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" required>
            <option value="container" @selected($hostingType === 'container')>Container</option>
            <option value="directadmin" @selected($hostingType === 'directadmin')>DirectAdmin</option>
        </select>
        @error('hosting_type') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Order</label>
        <input type="number" name="order" value="{{ old('order', $template->order ?? 0) }}" class="w-full rounded-lg border-slate-300 dark:bg-slate-800">
        @error('order') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Versions (JSON array)</label>
        <textarea name="versions" rows="3" class="w-full rounded-lg border-slate-300 dark:bg-slate-800" placeholder='["8.0","5.7"]'>{{ old('versions', isset($template) && $template?->versions ? json_encode($template->versions) : '[]') }}</textarea>
        @error('versions') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="md:col-span-2">
        <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active ?? true)) class="rounded border-slate-300">
            <span class="text-sm font-medium">Active</span>
        </label>
        @error('is_active') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>
</div>

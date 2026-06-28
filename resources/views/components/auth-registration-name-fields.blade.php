@props([
    'autofocusFirst' => true,
])

<div {{ $attributes->merge(['class' => 'grid grid-cols-1 sm:grid-cols-2 gap-4']) }}>
    <div class="space-y-2.5">
        <label for="first_name" class="block text-sm font-semibold text-slate-900 dark:text-white">
            First name
        </label>
        <input
            type="text"
            id="first_name"
            name="first_name"
            value="{{ old('first_name') }}"
            required
            @if($autofocusFirst) autofocus @endif
            autocomplete="given-name"
            placeholder="Jane"
            class="auth-input"
        />
        @error('first_name')
            <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-2.5">
        <label for="last_name" class="block text-sm font-semibold text-slate-900 dark:text-white">
            Last name
            <span class="font-normal text-slate-500 dark:text-slate-400 text-xs ml-1">(optional)</span>
        </label>
        <input
            type="text"
            id="last_name"
            name="last_name"
            value="{{ old('last_name') }}"
            autocomplete="family-name"
            placeholder="Doe"
            class="auth-input"
        />
        @error('last_name')
            <p class="mt-1.5 text-xs font-medium auth-input-error">{{ $message }}</p>
        @enderror
    </div>
</div>

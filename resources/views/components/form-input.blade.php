@props(['label', 'name', 'type' => 'text', 'value' => null, 'placeholder' => '', 'required' => false, 'readonly' => false, 'disabled' => false, 'help' => null, 'error' => null, 'useOld' => true])

@php
    $hasError = $errors->has($name) || $error;
    $errorMessage = $error ?? $errors->first($name);
    $inputValue = $useOld ? old($name, $value) : $value;
@endphp

<div class="space-y-2">
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-medium text-slate-700 dark:text-slate-300">
            {{ $label }}
            @if ($required)
                <span class="text-red-500">*</span>
            @endif
        </label>
    @endif

    <input
        type="{{ $type }}"
        name="{{ $name }}"
        id="{{ $name }}"
        value="{{ $inputValue }}"
        placeholder="{{ $placeholder }}"
        @if ($required) required @endif
        @if ($readonly) readonly @endif
        @if ($disabled) disabled @endif
        {{ $attributes->merge([
            'class' => 'block w-full px-4 py-2.5 rounded-lg border transition-colors ' .
            ($hasError
                ? 'border-red-300 bg-red-50 text-red-900 placeholder-red-300 focus:border-red-500 focus:ring-red-500 dark:border-red-700 dark:bg-red-900/20 dark:text-red-100 dark:placeholder-red-400 dark:focus:border-red-500 dark:focus:ring-red-500'
                : 'border-slate-300 bg-white text-slate-900 placeholder-slate-400 focus:border-blue-500 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-800 dark:text-white dark:placeholder-slate-500 dark:focus:border-blue-400 dark:focus:ring-blue-400'
            ) .
            ($disabled ? ' opacity-50 cursor-not-allowed' : '')
        ]) }}
    />

    @if ($hasError)
        <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $errorMessage }}</p>
    @elseif ($help)
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $help }}</p>
    @endif
</div>

@props([
    'name' => 'country',
    'value' => null,
    'label' => 'Country',
    'required' => false,
    'placeholder' => 'Select country...',
    'priority' => ['KE'],
    'variant' => 'default',
    'class' => '',
])

@php
    $selected = old($name, $value);
    $options = \App\Support\Countries::optionsForSelect($priority);

    if ($selected && ! isset($options[$selected])) {
        $legacyLabel = \App\Support\Countries::name($selected) ?? $selected;
        $options = [$selected => $legacyLabel] + $options;
    }

    $selectClass = match ($variant) {
        'auth' => 'auth-input',
        'public' => 'input-dark w-full',
        'reseller' => 'w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-purple-500 dark:focus:ring-purple-400 text-slate-900 dark:text-white',
        default => 'w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm',
    };
@endphp

<div @class(['space-y-2.5' => $variant === 'auth'])>
    @if ($label)
        <label for="{{ $name }}" @class([
            'block text-sm font-semibold text-slate-900 dark:text-white' => $variant === 'auth',
            'block text-sm font-medium text-white mb-2' => $variant === 'public',
            'block text-sm font-medium text-slate-900 dark:text-white mb-2' => ! in_array($variant, ['auth', 'public'], true),
        ])>
            {{ $label }}
            @if ($required)
                <span class="text-red-500">*</span>
            @elseif ($variant !== 'auth')
                <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span>
            @endif
        </label>
    @endif

    <select
        id="{{ $name }}"
        name="{{ $name }}"
        @if ($required) required @endif
        {{ $attributes->merge(['class' => trim($selectClass.' '.$class)]) }}
    >
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $code => $countryName)
            <option value="{{ $code }}" @selected((string) $selected === (string) $code)>{{ $countryName }}</option>
        @endforeach
    </select>

    @error($name)
        <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>

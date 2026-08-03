@props([
    'name' => 'agree_terms',
    'model' => null, // Alpine x-model binding, e.g. "agree"
    'variant' => 'order', // order|payment|authorize
    'required' => true,
    'linkClass' => 'text-blue-600 dark:text-blue-400 hover:underline font-medium',
])

@php
    $copy = match ($variant) {
        'payment' => 'I agree to the :terms and :privacy, and authorize payment of the amount due.',
        'authorize' => 'I agree to the :terms and :privacy, and authorize the charge for this order.',
        default => 'I agree to the :terms and :privacy, and understand that an invoice will be generated after placing this order.',
    };

    $terms = '<a href="'.e(route('terms')).'" target="_blank" rel="noopener" class="'.$linkClass.'">Terms of Service</a>';
    $privacy = '<a href="'.e(route('privacy')).'" target="_blank" rel="noopener" class="'.$linkClass.'">Privacy Policy</a>';
    $labelHtml = str_replace([':terms', ':privacy'], [$terms, $privacy], $copy);
@endphp

<label {{ $attributes->merge(['class' => 'flex items-start gap-3 cursor-pointer']) }}>
    <input
        type="checkbox"
        name="{{ $name }}"
        value="1"
        @if ($required) required @endif
        @if ($model) x-model="{{ $model }}" @endif
        class="mt-1 rounded border-slate-300 dark:border-slate-600 text-blue-600 focus:ring-blue-500"
    >
    <span class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed">
        {!! $labelHtml !!}
    </span>
</label>

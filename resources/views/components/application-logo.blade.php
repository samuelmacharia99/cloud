@php
    $logoUrl = \App\Models\Setting::getValue('logo_url', '');
@endphp

<div class="flex items-center gap-2" {{ $attributes->merge(['class' => 'h-8 w-auto']) }}>
    @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="Talksasa Cloud" class="h-8 w-auto object-contain">
    @else
        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
            <span class="text-white font-bold text-sm">TC</span>
        </div>
        <div class="font-bold text-slate-900 dark:text-white">
            <span class="text-base">Talksasa</span>
            <span class="text-xs text-slate-500 dark:text-slate-400 block">Cloud</span>
        </div>
    @endif
</div>

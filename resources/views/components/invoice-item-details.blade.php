@props(['item', 'titleClass' => 'font-medium text-slate-900 dark:text-white', 'metaClass' => 'text-xs text-slate-600 dark:text-slate-400'])

<div>
    <p class="{{ $titleClass }}">
        @if($item->domain_id)
            Domain
        @else
            {{ $item->product->name ?? 'Unknown Product' }}
        @endif
    </p>
    <p class="{{ $metaClass }}">{{ $item->description }}</p>
    @if($attachedDomain = $item->attachedDomainLabel())
        <p class="{{ $metaClass }} mt-1">
            <span class="font-medium text-slate-600 dark:text-slate-300">Domain:</span>
            <span class="font-mono">{{ $attachedDomain }}</span>
        </p>
    @endif
</div>

@props(['count' => null])

<span
    {{ $attributes->merge(['class' => 'relative inline-flex h-2 w-2 shrink-0']) }}
    @if($count) title="{{ $count }} need{{ $count === 1 ? 's' : '' }} attention" aria-label="{{ $count }} pending" @else title="Needs attention" aria-label="Needs attention" @endif
>
    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500 ring-2 ring-emerald-500/30"></span>
</span>

@props(['attention' => 0])

<span {{ $attributes->merge(['class' => 'flex flex-1 items-center justify-between gap-2 min-w-0']) }}>
    <span class="truncate">{{ $slot }}</span>
    @if($attention > 0)
        <x-admin-attention-dot :count="$attention" />
    @endif
</span>

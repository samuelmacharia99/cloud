@props([
    'user' => null,
    'label' => null,
    'fallback' => '—',
])

@php
    $display = $label ?? $user?->name;
    $isManagedCustomer = $user
        && (int) $user->id !== (int) auth()->id()
        && (int) $user->reseller_id === (int) auth()->id();
@endphp

@if ($isManagedCustomer)
    <a href="{{ route('reseller.customers.show', $user) }}" {{ $attributes->merge(['class' => 'font-medium text-purple-700 dark:text-purple-300 hover:underline']) }}>{{ $display }}</a>
@elseif (filled($display))
    <span {{ $attributes }}>{{ $display }}</span>
@else
    <span {{ $attributes->merge(['class' => 'text-slate-400']) }}>{{ $fallback }}</span>
@endif

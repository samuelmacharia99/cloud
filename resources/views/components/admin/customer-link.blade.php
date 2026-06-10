@props(['user' => null, 'label' => null, 'class' => ''])

@if ($user && $user->isLinkableAdminProfile())
    <a href="{{ $user->adminProfileUrl() }}" {{ $attributes->merge(['class' => 'text-blue-600 dark:text-blue-400 hover:underline font-medium '.$class]) }}>
        {{ $label ?? $user->name }}
    </a>
@elseif ($user)
    <span {{ $attributes->merge(['class' => $class]) }}>{{ $label ?? $user->name }}</span>
@else
    <span {{ $attributes->merge(['class' => 'text-slate-500 dark:text-slate-400 '.$class]) }}>{{ $label ?? '—' }}</span>
@endif

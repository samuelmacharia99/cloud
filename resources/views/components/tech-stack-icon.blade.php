@props([
    'slug',
    'class' => 'w-8 h-8',
])

@php
    $slug = strtolower((string) $slug);
@endphp

@switch($slug)
    @case('wordpress')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#21759B" aria-hidden="true">
            <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 18.5A8.5 8.5 0 1 1 12 3.5a8.5 8.5 0 0 1 0 17z"/>
            <path d="M4.86 12c0 2.77 1.58 5.17 3.9 6.35L5.32 9.18A7.48 7.48 0 0 0 4.86 12zm10.23-.4c0-.81-.29-1.37-.54-1.81-.33-.54-.64-1-.64-1.54 0-.6.46-1.16 1.1-1.16h.08a7.48 7.48 0 0 0-7.56-.47l3.34 9.13 1.98-5.91a6.2 6.2 0 0 1-.1-.87c.34-.02.68-.09.68-.09.32-.04.28-.51-.04-.49 0 0-.99.08-1.63.08-.95 0-1.14-.14-1.14-.14-.31-.02-.35-.44-.03-.48 0 0 .97-.11 1.98-.11 1.26 0 2.04.69 2.04 2.02 0 .75-.14 1.68-.54 2.79l-.71 2.37 2.08 5.69A8.51 8.51 0 0 0 20.5 12c0-2.94-1.49-5.53-3.74-7.04-.14 1.19-.75 3.22-1.67 5.64zm-3.12 1.1-2.73 7.92A8.48 8.48 0 0 0 12 20.5c1.1 0 2.14-.22 3.09-.61l.05-.02-2.17-5.92z"/>
        </svg>
        @break

    @case('nodejs')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#339933" aria-hidden="true">
            <path d="M11.998 1.5 2.5 6.98v10.04l9.498 5.48 9.502-5.48V6.98L11.998 1.5zm0 1.73 7.76 4.48v8.58l-7.76 4.48-7.76-4.48V7.71l7.76-4.48z"/>
            <path d="M12 7.2c-1.9 0-2.95.82-3.47 2.47l1.62.95c.27-.86.57-1.28 1.5-1.28.77 0 1.23.34 1.23.93v.05c0 .64-.33.94-1.86 1.42-1.82.56-2.8 1.25-2.8 2.9 0 1.6 1.06 2.6 2.85 2.6 1.55 0 2.55-.6 3.2-1.95l-1.52-1c-.35.75-.75 1.1-1.68 1.1-.74 0-1.17-.36-1.17-.98v-.04c0-.7.4-1 1.98-1.5 1.86-.58 2.74-1.35 2.74-2.96 0-1.55-1.1-2.7-2.92-2.7z"/>
        </svg>
        @break

    @case('python')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" aria-hidden="true">
            <path fill="#3776AB" d="M12.1 2c-2.4 0-2.25.96-2.25.96V5h4.4v.3c0 1.9-1.7 3.45-3.85 3.45H6.2S4 8.9 4 12.15v2.2s-.1 2.55 2.7 2.55h1.75V14.4s.05-1.95 2.1-1.95h4.2s2 0 2-2.2V5.2S19.1 2 12.1 2z"/>
            <path fill="#FFD43B" d="M11.9 22c2.4 0 2.25-.96 2.25-.96V19h-4.4v-.3c0-1.9 1.7-3.45 3.85-3.45h4.2S20 15.1 20 11.85v-2.2s.1-2.55-2.7-2.55h-1.75v2.5s-.05 1.95-2.1 1.95H9.25s-2 0-2 2.2v4.65S4.9 22 11.9 22z"/>
            <circle fill="#3776AB" cx="10.2" cy="4.4" r=".85"/>
            <circle fill="#FFD43B" cx="13.8" cy="19.6" r=".85"/>
        </svg>
        @break

    @case('static-site')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <rect x="3" y="4" width="18" height="16" rx="2" fill="#0EA5E9"/>
            <path d="M3 8h18" stroke="white" stroke-width="1.5"/>
            <circle cx="6.2" cy="6" r=".8" fill="white"/>
            <circle cx="8.5" cy="6" r=".8" fill="white"/>
            <circle cx="10.8" cy="6" r=".8" fill="white"/>
            <path d="M7.5 14.5 10 12l2 2 3.5-4.5 2 2.5" stroke="white" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        @break

    @case('laravel')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#FF2D20" aria-hidden="true">
            <path d="m21.5 6.3-4.2-2.4a.7.7 0 0 0-.7 0L12.4 6.3a.7.7 0 0 0-.35.6v4.8L8.3 9.5V4.7a.7.7 0 0 0-.35-.6L3.7 1.7a.7.7 0 0 0-.7 0L.8 3.5a.7.7 0 0 0-.35.6v9.6c0 .25.13.48.35.6l3.85 2.2a.7.7 0 0 0 .7 0l3.85-2.2a.7.7 0 0 0 .35-.6v-4.8l3.75 2.15v4.8c0 .25.13.48.35.6l4.2 2.4a.7.7 0 0 0 .7 0l4.2-2.4a.7.7 0 0 0 .35-.6V6.9a.7.7 0 0 0-.35-.6z"/>
        </svg>
        @break

    @case('php')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#777BB4" aria-hidden="true">
            <ellipse cx="12" cy="12" rx="10" ry="6.5"/>
            <text x="12" y="14.2" text-anchor="middle" font-size="7" font-weight="700" fill="white" font-family="Arial, sans-serif">PHP</text>
        </svg>
        @break

    @case('ruby')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#CC342D" aria-hidden="true">
            <path d="M18.5 8.2 12 2.5 5.5 8.2 12 21.5l6.5-13.3z"/>
            <path fill="#F04440" d="M12 2.5 5.5 8.2h13L12 2.5z"/>
            <path fill="#A91401" d="m5.5 8.2 6.5 13.3L12 10.4 5.5 8.2zm13 0L12 10.4l.05 11.1 6.45-13.3z"/>
        </svg>
        @break

    @case('ghost')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#15171A" aria-hidden="true">
            <path d="M12 2C7.6 2 4.5 5.2 4.5 9.8V19c0 1.4 1.5 2.2 2.6 1.4l1.7-1.2c.4-.3.9-.3 1.3 0l1.2.9c.4.3 1 .3 1.4 0l1.2-.9c.4-.3.9-.3 1.3 0l1.7 1.2c1.1.8 2.6 0 2.6-1.4V9.8C19.5 5.2 16.4 2 12 2z"/>
            <circle cx="9.2" cy="11" r="1.3" fill="white"/>
            <circle cx="14.8" cy="11" r="1.3" fill="white"/>
        </svg>
        @break

    @case('strapi')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#4945FF" aria-hidden="true">
            <path d="M4 4h9.5v6.5H4V4zm0 9.5H10V20H4v-6.5zm9.5 0H20V20h-6.5v-6.5z"/>
            <path opacity=".55" d="M13.5 4H20v6.5h-6.5V4z"/>
        </svg>
        @break

    @case('go')
    @case('golang')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="#00ADD8" aria-hidden="true">
            <path d="M2.5 10.2h4.2c.2-1 .8-1.5 1.8-1.5.9 0 1.4.5 1.4 1.2 0 .8-.5 1.3-1.8 1.7l-1.2.4c-2 .6-3 1.7-3 3.4 0 2.1 1.5 3.4 3.9 3.4 2.2 0 3.7-1 4.2-2.8l-1.9-.4c-.3 1-.9 1.5-2.1 1.5-1 0-1.6-.5-1.6-1.3 0-.8.5-1.3 1.9-1.7l1.2-.4c2.2-.7 3.2-1.8 3.2-3.6 0-2.1-1.6-3.4-4-3.4-2.4 0-4 1.2-4.4 3.3zm12.7 7.5h1.9V6.4h-1.9v11.3zm4.2 0h1.9v-4.4h.1l2.4 4.4h2.1l-2.7-4.6c1.4-.5 2.2-1.6 2.2-3.1 0-2.2-1.5-3.5-4.1-3.5h-3.9v11.2zm1.9-9.5h1.7c1.2 0 1.9.5 1.9 1.5s-.7 1.6-1.9 1.6h-1.7V8.2z"/>
        </svg>
        @break

    @default
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7 4 12l4 5M16 7l4 5-4 5M13 5l-2 14"/>
        </svg>
@endswitch

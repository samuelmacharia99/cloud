@props([
    'attachments',
    'ticket',
    'routeName' => 'tickets.attachments.show',
])

@if ($attachments->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'mt-4 flex flex-wrap gap-3']) }}>
        @foreach ($attachments as $attachment)
            @php
                $url = route($routeName, [$ticket, $attachment]);
            @endphp
            @if ($attachment->isImage())
                <a href="{{ $url }}" target="_blank" rel="noopener" class="group block">
                    <div class="rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden bg-slate-50 dark:bg-slate-800">
                        <img
                            src="{{ $url }}"
                            alt="{{ $attachment->original_name }}"
                            class="max-h-40 max-w-xs object-contain"
                            loading="lazy"
                        >
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 group-hover:text-blue-600 dark:group-hover:text-blue-400 truncate max-w-[12rem]">
                        {{ $attachment->original_name }}
                    </p>
                </a>
            @else
                <a
                    href="{{ $url }}"
                    class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm text-slate-700 dark:text-slate-300 hover:border-blue-400 hover:text-blue-600 dark:hover:text-blue-400 transition"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.586-6.586a4 4 0 00-5.656-5.656l-6.586 6.586a6 6 0 108.486 8.486L20.5 13"/>
                    </svg>
                    <span class="truncate max-w-[10rem]">{{ $attachment->original_name }}</span>
                    <span class="text-xs text-slate-400">({{ $attachment->formattedSize() }})</span>
                </a>
            @endif
        @endforeach
    </div>
@endif

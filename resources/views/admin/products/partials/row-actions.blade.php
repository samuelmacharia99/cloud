<div class="inline-flex items-center justify-end gap-0.5">
    <a
        href="{{ route('admin.products.show', $product) }}"
        class="action-icon-btn text-brand-600 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-950/40"
        title="View product"
    >
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        </svg>
    </a>

    <a
        href="{{ route('admin.products.edit', $product) }}"
        class="action-icon-btn text-slate-600 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800"
        title="Edit product"
    >
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
        </svg>
    </a>

    @if ($product->type === 'container_hosting')
        <form action="{{ route('admin.products.duplicate', $product) }}" method="POST" class="inline">
            @csrf
            <button
                type="submit"
                class="action-icon-btn text-violet-600 dark:text-violet-400 hover:bg-violet-50 dark:hover:bg-violet-950/40"
                title="Duplicate product"
            >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
            </button>
        </form>
    @endif

    <form
        action="{{ route('admin.products.destroy', $product) }}"
        method="POST"
        class="inline"
        data-confirm="Are you sure you want to delete this product? This action cannot be undone."
    >
        @csrf
        @method('DELETE')
        <button
            type="submit"
            class="action-icon-btn text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40"
            title="Delete product"
        >
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </button>
    </form>
</div>

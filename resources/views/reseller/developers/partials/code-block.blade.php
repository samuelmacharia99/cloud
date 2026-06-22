<div class="relative group">
    <pre class="text-xs sm:text-sm leading-relaxed overflow-x-auto p-4 rounded-xl bg-slate-900 text-slate-100 font-mono"><code>{{ $code }}</code></pre>
    <button type="button"
        @click="copy(@js($code), '{{ $id }}')"
        class="absolute top-2 right-2 px-2.5 py-1 text-xs font-medium rounded-md bg-slate-700 hover:bg-slate-600 text-slate-200 opacity-0 group-hover:opacity-100 focus:opacity-100 transition"
        x-text="copied === '{{ $id }}' ? 'Copied' : 'Copy'"></button>
</div>

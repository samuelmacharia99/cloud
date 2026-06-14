<section class="space-y-6">
    <div class="rounded-xl border border-teal-200 dark:border-teal-800 bg-teal-50/50 dark:bg-teal-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Build your site locally (<code class="font-mono text-xs">npm run build</code>, Hugo, Jekyll, etc.) and upload the output to <code class="font-mono text-xs">/usr/share/nginx/html</code> via the Files tab.</li>
            <li>Alternatively, push static files to GitHub and pull — place <code class="font-mono text-xs">index.html</code> at the web root.</li>
            <li>Bind your domain — nginx serves files directly with no application server needed.</li>
        </ol>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
        <h4 class="font-semibold text-slate-900 dark:text-white">SPA routing</h4>
        <p class="text-sm text-slate-600 dark:text-slate-300">
            Single-page apps (React Router, Vue Router) need a fallback to <code class="font-mono text-xs">index.html</code> for client-side routes.
            Include an nginx config or use hash-based routing if deep links return 404.
        </p>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>403 / directory listing</strong> — Ensure <code class="font-mono text-xs">index.html</code> exists at the web root.</li>
            <li><strong>Stale content</strong> — Hard-refresh or purge CDN cache after uploading new files.</li>
        </ul>
    </div>
</section>

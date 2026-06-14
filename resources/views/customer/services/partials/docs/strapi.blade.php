<section class="space-y-6">
    <div class="rounded-xl border border-purple-200 dark:border-purple-800 bg-purple-50/50 dark:bg-purple-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Strapi provisions with its app directory at <code class="font-mono text-xs">/srv/app</code>.</li>
            <li>On first boot, complete the admin registration at <code class="font-mono text-xs">/admin</code>.</li>
            <li>Configure database credentials — use the MySQL sidecar details from the <strong>Database</strong> tab.</li>
            <li>Bind your domain and set the public URL in Strapi admin settings.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Custom Strapi project</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Pull your Strapi repo via the <strong>GitHub</strong> tab if supported for your deployment.</li>
                <li>Run <code class="font-mono text-xs">npm install</code> and <code class="font-mono text-xs">npm run build</code> after schema changes.</li>
                <li>Set <code class="font-mono text-xs">APP_KEYS</code>, <code class="font-mono text-xs">JWT_SECRET</code>, and database env vars.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Media & uploads</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Uploaded media is stored in the Strapi project — include uploads in backups.</li>
                <li>For production, configure S3 or similar via Strapi plugins.</li>
            </ul>
        </div>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>Admin panel 404</strong> — Rebuild admin UI: <code class="font-mono text-xs">npm run build</code> in Terminal.</li>
            <li><strong>Database connection</strong> — Strapi v4+ uses <code class="font-mono text-xs">DATABASE_*</code> env vars; match the Database tab host.</li>
        </ul>
    </div>
</section>

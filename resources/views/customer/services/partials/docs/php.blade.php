<section class="space-y-6">
    <div class="rounded-xl border border-violet-200 dark:border-violet-800 bg-violet-50/50 dark:bg-violet-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Upload or pull your PHP application into <code class="font-mono text-xs">/app</code>.</li>
            <li>Ensure a web entry point exists (<code class="font-mono text-xs">public/index.php</code> or <code class="font-mono text-xs">index.php</code> at root).</li>
            <li>Configure environment variables for your app via the <strong>Environment</strong> tab.</li>
            <li>Bind your domain under <strong>Domains</strong>.</li>
        </ol>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
        <h4 class="font-semibold text-slate-900 dark:text-white">Deployment notes</h4>
        <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
            <li>The PHP runtime serves your application on the configured port (default 8080).</li>
            <li>Use <code class="font-mono text-xs">composer install --no-dev</code> in Terminal after pulling a Composer-based project.</li>
            <li>For database apps, use credentials from the <strong>Database</strong> tab if MySQL is bundled with your plan.</li>
            <li>Need Laravel-specific tooling? Consider the dedicated <strong>Laravel Application</strong> stack instead.</li>
        </ul>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>Blank page / 500</strong> — Enable error logging, check <code class="font-mono text-xs">storage/logs</code> or PHP error output in Logs.</li>
            <li><strong>Missing extensions</strong> — Verify required PHP extensions are available in the runtime image.</li>
        </ul>
    </div>
</section>

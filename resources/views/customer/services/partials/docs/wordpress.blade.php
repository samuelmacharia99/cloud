<section class="space-y-6">
    <div class="rounded-xl border border-sky-200 dark:border-sky-800 bg-sky-50/50 dark:bg-sky-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>WordPress provisions automatically with a MySQL sidecar when you order this stack.</li>
            <li>Admin credentials are generated at checkout — save the email/password from your order confirmation.</li>
            <li>Visit your service URL and complete the WordPress setup wizard if prompted.</li>
            <li>Bind a custom domain under <strong>Domains</strong> and update site URL in WordPress settings.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Content & plugins</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Themes and plugins live under <code class="font-mono text-xs">wp-content</code> — use the Files tab or WP admin.</li>
                <li>Create a <strong>Backup</strong> before major plugin updates.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Database</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>MySQL credentials are pre-configured — see the <strong>Database</strong> tab for connection details.</li>
                <li>Use phpMyAdmin-style access via the database console if enabled on your plan.</li>
            </ul>
        </div>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>Redirect loop after domain change</strong> — Update <code class="font-mono text-xs">siteurl</code> and <code class="font-mono text-xs">home</code> in the database or wp-config.</li>
            <li><strong>White screen</strong> — Enable <code class="font-mono text-xs">WP_DEBUG</code> temporarily and check Logs.</li>
        </ul>
    </div>
</section>

<section class="space-y-6">
    <div class="rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50/50 dark:bg-indigo-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Order a <strong>Laravel</strong> app hosting plan — MySQL is included as a sidecar.</li>
            <li>On the <strong>Overview</strong> tab, click <strong>Initialize Laravel app</strong> for a fresh skeleton, <em>or</em> connect Git and pull your existing repo.</li>
            <li>Talksasa auto-writes database credentials into <code class="text-xs font-mono bg-white/70 dark:bg-slate-900 px-1.5 py-0.5 rounded">.env</code> on first setup.</li>
            <li>Git pulls preserve your existing <code class="text-xs font-mono bg-white/70 dark:bg-slate-900 px-1.5 py-0.5 rounded">.env</code>; only database and platform URL settings are refreshed. Add other secrets under <strong>Environment</strong>.</li>
            <li>Bind your domain under <strong>Domains</strong>, then visit the site.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Deploy from GitHub</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Open the <strong>Git</strong> tab and paste your repository URL + branch.</li>
                <li>Click <strong>Pull latest from Git</strong>. Talksasa runs <code class="font-mono text-xs">composer install</code> and <code class="font-mono text-xs">php artisan migrate</code> automatically.</li>
                <li>For <strong>private repositories</strong>, save a Personal Access Token on the Git tab (Contents: Read). Optional Composer token for private packages.</li>
                <li>Ensure your repo has Laravel at the root (<code class="font-mono text-xs">artisan</code>, <code class="font-mono text-xs">composer.json</code>) or in a subdirectory such as <code class="font-mono text-xs">core/</code> with a front controller at <code class="font-mono text-xs">index.php</code>.</li>
                <li>Set secrets (<code class="font-mono text-xs">APP_KEY</code>, mail, third-party APIs) via the <strong>Environment</strong> tab — not by digging through Files.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Fresh install (no Git)</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Use <strong>Clear /app</strong> if leftover files block initialization.</li>
                <li><strong>Initialize Laravel app</strong> scaffolds Laravel into <code class="font-mono text-xs">/app</code> and runs migrations.</li>
                <li>Upload custom code via the <strong>Files</strong> tab or connect Git later.</li>
            </ul>
        </div>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>500 error after deploy</strong> — Check <code class="font-mono text-xs">APP_KEY</code> is set and run migrations from Terminal: <code class="font-mono text-xs">php artisan migrate --force</code></li>
            <li><strong>Database connection refused</strong> — Use the credentials shown on the <strong>Database</strong> tab; host is the MySQL sidecar service name.</li>
            <li><strong>Redeploy stack</strong> recreates containers but keeps <code class="font-mono text-xs">/app</code> files. Enable <strong>Reset database</strong> only when you intentionally want a clean DB.</li>
        </ul>
    </div>
</section>

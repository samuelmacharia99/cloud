<section class="space-y-6">
    <div class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Push your Node.js app to GitHub (Express, Fastify, or a framework like Next.js).</li>
            <li>Connect the repo on the <strong>GitHub</strong> tab and pull code into <code class="font-mono text-xs">/app</code>.</li>
            <li>Talksasa detects your start command from <code class="font-mono text-xs">package.json</code> or a <code class="font-mono text-xs">Procfile</code>.</li>
            <li>Bind your domain and visit the service URL.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Express / plain Node</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Include a <code class="font-mono text-xs">start</code> script in <code class="font-mono text-xs">package.json</code>, e.g. <code class="font-mono text-xs">"start": "node server.js"</code>.</li>
                <li>Listen on <code class="font-mono text-xs">process.env.PORT</code> (Talksasa sets <code class="font-mono text-xs">PORT</code> automatically).</li>
                <li>After each Git pull, Talksasa performs a clean dependency install (removes stale <code class="font-mono text-xs">node_modules</code> first).</li>
                <li>Plain apps use <code class="font-mono text-xs">npm ci --omit=dev</code> when a lockfile is present.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Next.js / Nuxt / SSR frameworks</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>These apps need a <strong>production build</strong> before <code class="font-mono text-xs">next start</code> or <code class="font-mono text-xs">nuxt start</code> can run.</li>
                <li>Talksasa runs a prepare step before <code class="font-mono text-xs">npm run build</code> that patches TypeScript settings and relaxes Next.js type/lint blocking on hosted builds.</li>
                <li>For Next.js/Nuxt apps, Talksasa runs <code class="font-mono text-xs">npm install --include=dev</code> → prepare → <code class="font-mono text-xs">npm run build</code> after each Git pull.</li>
                <li>The build output (<code class="font-mono text-xs">.next</code> for Next.js) is <strong>not</strong> committed to Git — Talksasa rebuilds it on deploy.</li>
                <li>Do <strong>not</strong> use <code class="font-mono text-xs">npm run dev</code> in production; keep <code class="font-mono text-xs">"start": "next start"</code>.</li>
            </ul>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
        <h4 class="font-semibold text-slate-900 dark:text-white">Environment variables</h4>
        <p class="text-sm text-slate-600 dark:text-slate-300">
            Set <code class="font-mono text-xs">NODE_ENV=production</code> at order time or add variables in your repo.
            Framework secrets (database URLs, API keys) belong in <code class="font-mono text-xs">.env</code> or Talksasa service settings — never commit secrets to Git.
        </p>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>Container restart loop</strong> — Load <strong>Logs</strong>. If you see <em>"Could not find a production build in the '.next' directory"</em>, pull code again (build runs automatically) or run <code class="font-mono text-xs">npm run build</code> in Terminal.</li>
            <li><strong>Port already in use</strong> — Bind to <code class="font-mono text-xs">process.env.PORT</code>, not a hard-coded port.</li>
            <li><strong>Build fails on pull</strong> — Check build logs in Terminal. Often missing env vars required at build time (e.g. Next.js <code class="font-mono text-xs">NEXT_PUBLIC_*</code>).</li>
        </ul>
    </div>
</section>

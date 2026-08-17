<section class="space-y-6">
    <div class="rounded-xl border border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Push your Node.js app to GitHub (Express, Fastify, or a framework like Next.js).</li>
            <li>Connect the repo on the <strong>Git</strong> tab and pull code into <code class="font-mono text-xs">/app</code>.</li>
            <li>Talksasa detects your start command from <code class="font-mono text-xs">package.json</code> or a <code class="font-mono text-xs">Procfile</code>.</li>
            <li>Bind your domain and visit the service URL.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Express / plain Node</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Include a <code class="font-mono text-xs">start</code> script in <code class="font-mono text-xs">package.json</code>, e.g. <code class="font-mono text-xs">"start": "node server.js"</code>.</li>
                <li>Listen on <code class="font-mono text-xs">process.env.PORT</code> (Talksasa sets <code class="font-mono text-xs">PORT</code> automatically) and bind <code class="font-mono text-xs">0.0.0.0</code>.</li>
                <li>After each Git pull, Talksasa performs a clean dependency install (removes stale <code class="font-mono text-xs">node_modules</code> first).</li>
                <li>Plain apps use <code class="font-mono text-xs">npm ci --omit=dev</code> when a lockfile is present, and fall back to <code class="font-mono text-xs">npm install</code> if the lockfile is out of sync with <code class="font-mono text-xs">package.json</code>.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Next.js / Nuxt / SSR frameworks</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>These apps need a <strong>production build</strong> before <code class="font-mono text-xs">next start</code> or <code class="font-mono text-xs">nuxt start</code> can run.</li>
                <li>Talksasa runs a prepare step before <code class="font-mono text-xs">npm run build</code> that patches TypeScript settings and relaxes Next.js type/lint blocking on hosted builds.</li>
                <li>For Next.js/Nuxt apps, Talksasa prefers <code class="font-mono text-xs">npm ci --include=dev --legacy-peer-deps</code> (falls back to <code class="font-mono text-xs">npm install --include=dev --legacy-peer-deps</code> if the lockfile is stale or peers conflict) → prepare → <code class="font-mono text-xs">node node_modules/next/dist/bin/next build</code> (Next) / <code class="font-mono text-xs">npm run build</code> after each Git pull when a framework build is detected.</li>
                <li>Talksasa injects allowlisted build-time env vars from the <strong>Environment</strong> tab into production builds (<code class="font-mono text-xs">NEXT_PUBLIC_*</code>, <code class="font-mono text-xs">VITE_*</code>, <code class="font-mono text-xs">NUXT_PUBLIC_*</code>, and similar).</li>
                <li>If a build looks stuck or stale, pull again with <strong>Force clean rebuild</strong> enabled on the Git tab.</li>
                <li>The build output (<code class="font-mono text-xs">.next</code> for Next.js) is <strong>not</strong> committed to Git — Talksasa rebuilds it on deploy.</li>
                <li>Do <strong>not</strong> use <code class="font-mono text-xs">npm run dev</code> in production; keep <code class="font-mono text-xs">"start": "next start"</code>.</li>
            </ul>
        </div>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
        <h4 class="font-semibold text-slate-900 dark:text-white">Vite / React example templates</h4>
        <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
            <li>Many Vite starters use <code class="font-mono text-xs">"start": "tsx server.ts"</code> or <code class="font-mono text-xs">"start": "node dist/server.cjs"</code> with a Vite middleware server. That fails after production installs strip <code class="font-mono text-xs">vite</code>.</li>
            <li>Talksasa detects that pattern, runs <code class="font-mono text-xs">vite build</code>, then starts <code class="font-mono text-xs">vite preview --host 0.0.0.0 --port $PORT --strictPort</code>. Dev dependencies are kept installed for these apps because <code class="font-mono text-xs">vite.config</code> imports Vite and its plugins at boot.</li>
            <li>Vite only answers for hostnames on its allowlist. Every domain you bind is passed to the preview server automatically, so a newly bound domain briefly restarts the container.</li>
            <li>The first build after a deploy or a Doctor fix can run for several minutes. Doctor reports <em>build in progress</em> instead of an error while that runs.</li>
            <li>Prefer shipping <code class="font-mono text-xs">"build": "vite build"</code> and a production <code class="font-mono text-xs">start</code> that serves <code class="font-mono text-xs">dist/</code>. Custom API routes still need a real production Node entrypoint (not Vite middleware).</li>
            <li>If an older deploy is crash-looping on <code class="font-mono text-xs">Cannot find package 'vite'</code>, use Container Doctor → <strong>Switch to Vite production</strong>.</li>
        </ul>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
        <h4 class="font-semibold text-slate-900 dark:text-white">Environment variables</h4>
        <p class="text-sm text-slate-600 dark:text-slate-300">
            Manage runtime secrets under the <strong>Environment</strong> tab (recommended). Framework secrets (database URLs, API keys) can also live in <code class="font-mono text-xs">.env</code> — never commit secrets to Git.
            Rebuilds run when a framework build is detected or when you enable <strong>Force clean rebuild</strong>; otherwise production dependency install runs without a full rebuild.
        </p>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>App restart loop</strong> — Load <strong>Logs</strong>. If you see <em>"Could not find a production build in the '.next' directory"</em>, pull code again (build runs automatically) or run <code class="font-mono text-xs">npm run build</code> in Terminal.</li>
            <li><strong>Cannot find package/module 'vite'</strong> — The start script still depends on Vite at runtime (<code class="font-mono text-xs">tsx server.ts</code> or <code class="font-mono text-xs">node dist/server.cjs</code>). Use Container Doctor → Switch to Vite production, or change <code class="font-mono text-xs">start</code> to serve <code class="font-mono text-xs">dist/</code> without requiring Vite.</li>
            <li><strong>Blocked request. This host is not allowed</strong> — Vite is rejecting your domain. Bind the domain under <strong>Domains</strong>, then run Container Doctor → <strong>Allow bound domains</strong>.</li>
            <li><strong>Port already in use</strong> — Bind to <code class="font-mono text-xs">process.env.PORT</code>, not a hard-coded port.</li>
            <li><strong>Build fails on pull</strong> — Check build logs in Terminal. Often missing env vars required at build time (e.g. Next.js <code class="font-mono text-xs">NEXT_PUBLIC_*</code>).</li>
        </ul>
    </div>
</section>

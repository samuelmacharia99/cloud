<section class="space-y-6">
    <div class="rounded-xl border border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Include a <code class="font-mono text-xs">Gemfile</code> at the repo root.</li>
            <li>Connect GitHub and pull — Talksasa runs <code class="font-mono text-xs">bundle install --without development test</code>.</li>
            <li>Rails apps are detected via <code class="font-mono text-xs">bin/rails</code>; Rack apps via <code class="font-mono text-xs">config.ru</code>.</li>
            <li>Set <code class="font-mono text-xs">RAILS_ENV=production</code> and bind your domain.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Rails</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Run migrations from Terminal: <code class="font-mono text-xs">bundle exec rails db:migrate</code></li>
                <li>Precompile assets if needed: <code class="font-mono text-xs">bundle exec rails assets:precompile</code></li>
                <li>Configure <code class="font-mono text-xs">config/database.yml</code> to use the MySQL sidecar credentials from the <strong>Database</strong> tab.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Sinatra / Rack</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Ensure <code class="font-mono text-xs">config.ru</code> exists at the root.</li>
                <li>Alternatively, add a <code class="font-mono text-xs">Procfile</code> with <code class="font-mono text-xs">web: bundle exec rackup ...</code></li>
            </ul>
        </div>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>Bundle install fails</strong> — Native gem extensions may need build tools; simplify gems or use precompiled alternatives.</li>
            <li><strong>Database errors</strong> — Verify MySQL host/user/password match the <strong>Database</strong> tab.</li>
            <li><strong>Asset 404s</strong> — Run asset precompile and set <code class="font-mono text-xs">RAILS_SERVE_STATIC_FILES=true</code> if not using a CDN.</li>
        </ul>
    </div>
</section>

<section class="space-y-6">
    <div class="rounded-xl border border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-950/20 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Quick start</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Add a <code class="font-mono text-xs">requirements.txt</code> at the repo root listing all dependencies.</li>
            <li>Connect GitHub and pull code — Talksasa runs <code class="font-mono text-xs">pip install -r requirements.txt</code> automatically.</li>
            <li>Talksasa auto-detects Django, Gunicorn, Uvicorn, or a <code class="font-mono text-xs">Procfile</code> web process.</li>
            <li>Bind your domain and test the public URL.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Django</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Include <code class="font-mono text-xs">gunicorn</code> in requirements for production.</li>
                <li>Ensure <code class="font-mono text-xs">wsgi.py</code> is present — Talksasa starts Gunicorn when detected.</li>
                <li>Run migrations from Terminal: <code class="font-mono text-xs">python manage.py migrate</code></li>
                <li>Set <code class="font-mono text-xs">DJANGO_SETTINGS_MODULE</code> and <code class="font-mono text-xs">SECRET_KEY</code> via environment or <code class="font-mono text-xs">.env</code>.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">FastAPI / Flask</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>FastAPI: add <code class="font-mono text-xs">uvicorn</code> to requirements and expose <code class="font-mono text-xs">main:app</code>.</li>
                <li>Flask: use Gunicorn or a <code class="font-mono text-xs">Procfile</code> with your WSGI entry point.</li>
                <li>Listen on <code class="font-mono text-xs">0.0.0.0</code> and use <code class="font-mono text-xs">${PORT}</code> for the port.</li>
            </ul>
        </div>
    </div>

    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50/60 dark:bg-amber-950/20 p-6 space-y-3">
        <h4 class="font-semibold text-amber-900 dark:text-amber-100">Troubleshooting</h4>
        <ul class="text-sm text-amber-900/90 dark:text-amber-100/90 space-y-2">
            <li><strong>Module not found</strong> — Package missing from <code class="font-mono text-xs">requirements.txt</code>. Pull again after fixing.</li>
            <li><strong>502 / connection refused</strong> — App must bind to <code class="font-mono text-xs">0.0.0.0:${PORT}</code>, not <code class="font-mono text-xs">127.0.0.1:8000</code>.</li>
            <li><strong>Static files</strong> — Configure whitenoise (Django) or serve static assets via nginx/CDN for production.</li>
        </ul>
    </div>
</section>

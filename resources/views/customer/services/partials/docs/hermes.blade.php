<section class="space-y-6">
    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-900/40 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Use the Hermes dashboard</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>The dashboard is this service — click <strong>Open dashboard</strong> on Overview (or Visit service).</li>
            <li>Sign in with the username and password shown on Overview. Reveal the password there, or under Environment.</li>
            <li>From the dashboard you can start/stop the gateway, set the model, and connect Telegram or other channels.</li>
        </ol>
    </div>

    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-900/40 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Connect a language model</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Add <code class="font-mono text-xs">OPENAI_API_KEY</code>, <code class="font-mono text-xs">ANTHROPIC_API_KEY</code>, or an OpenRouter key under Environment, then apply. That is the supported way to run Hermes as an agent.</li>
            <li>Ollama is not offered as a new stack. CPU local models are too slow for Hermes tool use. If this project already has Ollama, you can still connect it from Overview.</li>
            <li>If Chat shows <strong>connection interrupted (code 1006)</strong>, restart Hermes so the domain proxy can upgrade WebSockets.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">LLM keys</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Add <code class="font-mono text-xs">OPENAI_API_KEY</code> / <code class="font-mono text-xs">ANTHROPIC_API_KEY</code> under Environment, then apply.</li>
                <li>Existing project Ollama can still be connected from Overview (custom endpoint <code class="font-mono text-xs">http://&lt;ollama-container&gt;:11434/v1</code>).</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Nous Portal (optional)</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Only needed if you want Sign in with Nous Research instead of the password.</li>
                <li>On <a href="https://portal.nousresearch.com/local-dashboards" class="text-blue-600 dark:text-blue-400 underline" target="_blank" rel="noopener">Local Dashboards</a>, register this host, then paste the <code class="font-mono text-xs">agent:…</code> client ID into Environment as <code class="font-mono text-xs">HERMES_DASHBOARD_OAUTH_CLIENT_ID</code> and apply.</li>
            </ul>
        </div>
    </div>
</section>

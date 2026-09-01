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
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Connect project Ollama</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Deploy Ollama into the same project and pull a model (Chat tab or <code class="font-mono text-xs">ollama pull mistral</code> in Terminal).</li>
            <li>On this Overview, choose that Ollama service and click <strong>Connect Ollama</strong>. Ollama may restart to raise context to 64K, then Hermes restarts.</li>
            <li>Same-host containers talk over the private Docker network (<code class="font-mono text-xs">http://&lt;ollama-container&gt;:11434</code>), not localhost — localhost inside Hermes is Hermes itself.</li>
            <li>If Chat shows <strong>connection interrupted (code 1006)</strong>, restart Hermes so the domain proxy can upgrade WebSockets. The first local-model reply can take a minute after connect.</li>
            <li>Hermes needs 64K runtime context. Connect Ollama again so we create a <code class="font-mono text-xs">mistral-hermes</code> alias with <code class="font-mono text-xs">num_ctx 65536</code>, reload it, and set <code class="font-mono text-xs">model.ollama_num_ctx</code>. A model already loaded at 32K (keep-alive) will still fail until that reload.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">LLM keys</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>Prefer connecting the project Ollama service on Overview. That sets a custom OpenAI-compatible provider automatically.</li>
                <li>Or add <code class="font-mono text-xs">OPENAI_API_KEY</code> / <code class="font-mono text-xs">ANTHROPIC_API_KEY</code> under Environment, then apply.</li>
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

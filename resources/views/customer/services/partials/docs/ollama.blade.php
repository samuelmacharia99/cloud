<section class="space-y-6">
    <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-900/40 p-6">
        <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Chat with Mistral</h4>
        <ol class="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-300 list-decimal list-inside">
            <li>Open the <strong>Chat</strong> tab and type a question. That is the conversation UI — the Terminal is a Linux shell.</li>
            <li>First replies can take a minute while the model loads into RAM. Later messages are faster.</li>
            <li>Use <strong>Visit service</strong> only for the Ollama HTTP API (port 11434). It is not a chat website.</li>
        </ol>
    </div>

    <div class="grid md:grid-cols-2 gap-6">
        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">Terminal</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li><code class="font-mono text-xs">ollama list</code> — installed models.</li>
                <li><code class="font-mono text-xs">ollama pull mistral:7b</code> — download a library model.</li>
                <li>Do not type chat messages in the shell. <code class="font-mono text-xs">hello</code> is a missing command, not a prompt.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
            <h4 class="font-semibold text-slate-900 dark:text-white">API</h4>
            <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
                <li>The service URL speaks the official Ollama API at <code class="font-mono text-xs">/api/chat</code> and <code class="font-mono text-xs">/api/generate</code>.</li>
                <li>Point your own app at that URL. Keep the API private unless you add your own auth in front of it.</li>
            </ul>
        </div>
    </div>
</section>

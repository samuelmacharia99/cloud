<section class="rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 p-6 space-y-4">
    <h4 class="text-lg font-semibold text-slate-900 dark:text-white">Talksasa platform tips</h4>
    <ul class="space-y-3 text-sm text-slate-600 dark:text-slate-300">
        <li class="flex gap-3">
            <span class="text-blue-600 shrink-0">1.</span>
            <span><strong class="text-slate-900 dark:text-white">Domains tab</strong> — Point your DNS A record to the node IP, then bind the domain here. HTTPS is provisioned automatically.</span>
        </li>
        <li class="flex gap-3">
            <span class="text-blue-600 shrink-0">2.</span>
            <span><strong class="text-slate-900 dark:text-white">Backups tab</strong> — Create a backup before major changes. Restore rolls back files and optionally the database.</span>
        </li>
        <li class="flex gap-3">
            <span class="text-blue-600 shrink-0">3.</span>
            <span><strong class="text-slate-900 dark:text-white">Logs tab</strong> — If the container keeps restarting, load logs first. Most deploy issues show up in the last 50 lines.</span>
        </li>
        <li class="flex gap-3">
            <span class="text-blue-600 shrink-0">4.</span>
            <span><strong class="text-slate-900 dark:text-white">Terminal tab</strong> — Run one-off commands inside the running container. Use this for debugging after a failed deploy.</span>
        </li>
    </ul>
</section>

<section class="rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-3">
    <h4 class="font-semibold text-slate-900 dark:text-white">Open Source POS</h4>
    <ul class="text-sm text-slate-600 dark:text-slate-300 space-y-2">
        <li>First sign-in is <code class="font-mono text-xs">admin</code> / <code class="font-mono text-xs">pointofsale</code>. <strong>Change that password before you add stock or staff.</strong> Employees &rarr; admin &rarr; Change password.</li>
        <li>The database schema is created on your first visit; the first page can take a moment.</li>
        <li>Your store runs on its own MariaDB inside this stack. Its state and a Restart action are on the project page, and its credentials are under <strong>Database</strong>.</li>
        <li>Receipts, item pictures and uploads live in a persistent volume and survive redeploys.</li>
        <li>Bind your shop's domain under <strong>Domains</strong>; the app's Host allow-list is updated for you.</li>
        <li>Pick a different release from the version selector when you redeploy; the platform builds it from the official release tag.</li>
    </ul>
</section>

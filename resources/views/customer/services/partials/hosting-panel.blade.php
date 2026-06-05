@php
    $hostingDomain = $service->service_meta['domain'] ?? ($service->getHostingCredentials()['domain'] ?? '');
    $hostingRoutes = [
        'dashboard' => route('customer.services.hosting.dashboard', $service),
        'dnsIndex' => route('customer.services.hosting.dns.index', $service),
        'dnsStore' => route('customer.services.hosting.dns.store', $service),
        'dnsDestroy' => route('customer.services.hosting.dns.destroy', $service),
        'emailsIndex' => route('customer.services.hosting.emails.index', $service),
        'emailsStore' => route('customer.services.hosting.emails.store', $service),
        'emailsDestroy' => route('customer.services.hosting.emails.destroy', $service),
        'databasesIndex' => route('customer.services.hosting.databases.index', $service),
        'databasesStore' => route('customer.services.hosting.databases.store', $service),
        'databasesDestroy' => route('customer.services.hosting.databases.destroy', $service),
        'subdomainsIndex' => route('customer.services.hosting.subdomains.index', $service),
        'subdomainsStore' => route('customer.services.hosting.subdomains.store', $service),
        'subdomainsDestroy' => route('customer.services.hosting.subdomains.destroy', $service),
        'ftpIndex' => route('customer.services.hosting.ftp.index', $service),
        'ftpStore' => route('customer.services.hosting.ftp.store', $service),
        'ftpDestroy' => route('customer.services.hosting.ftp.destroy', $service),
        'sslShow' => route('customer.services.hosting.ssl.show', $service),
        'sslLetsEncrypt' => route('customer.services.hosting.ssl.letsencrypt', $service),
        'cronIndex' => route('customer.services.hosting.cron.index', $service),
        'cronStore' => route('customer.services.hosting.cron.store', $service),
        'cronDestroy' => route('customer.services.hosting.cron.destroy', $service),
        'backupsIndex' => route('customer.services.hosting.backups.index', $service),
        'backupsStore' => route('customer.services.hosting.backups.store', $service),
        'passwordReset' => route('customer.services.hosting.password.reset', $service),
        'panelLogin' => route('customer.services.hosting.panel-login', $service),
    ];
@endphp

<div x-data="hostingPanel({{ Js::from(['domain' => $hostingDomain, 'routes' => $hostingRoutes]) }})" @hosting-console-open.window="initOnce()" class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div>
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Hosting Console</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                Manage DNS, email, databases, and more for <span class="font-mono text-slate-800 dark:text-slate-200">{{ $hostingDomain ?: 'your domain' }}</span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a :href="routes.panelLogin" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition">Open Panel (SSO)</a>
            <button type="button" @click="loadDashboard(true)" class="px-4 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 text-sm font-medium rounded-lg transition">Refresh</button>
            <button type="button" @click="resetPassword()" :disabled="loading" class="px-4 py-2 bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-200 hover:bg-amber-200 dark:hover:bg-amber-900 text-sm font-medium rounded-lg transition disabled:opacity-50">Reset Panel Password</button>
        </div>
    </div>

    <div x-show="message" x-transition class="rounded-lg px-4 py-3 text-sm" :class="messageType === 'error' ? 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200 border border-red-200 dark:border-red-800' : 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200 border border-emerald-200 dark:border-emerald-800'">
        <span x-text="message"></span>
    </div>

    <div x-show="!initialized && !loading" class="rounded-xl border border-dashed border-slate-300 dark:border-slate-600 px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
        Open this tab to load your hosting console.
    </div>

    <div x-show="loading && !dashboard" class="flex items-center justify-center py-16 text-slate-500 dark:text-slate-400">
        <svg class="animate-spin h-6 w-6 mr-3" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        Loading hosting data...
    </div>

    <template x-if="dashboard">
        <div class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Disk Usage</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2" x-text="formatUsage(dashboard.disk.used_mb, dashboard.disk.limit_mb)"></p>
                    <div class="mt-3 h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full bg-blue-600 rounded-full" :style="`width: ${usagePercent(dashboard.disk.used_mb, dashboard.disk.limit_mb)}%`"></div>
                    </div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Bandwidth</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2" x-text="formatUsage(dashboard.bandwidth.used_mb, dashboard.bandwidth.limit_mb, true)"></p>
                    <div class="mt-3 h-2 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                        <div class="h-full bg-emerald-600 rounded-full" :style="`width: ${usagePercent(dashboard.bandwidth.used_mb, dashboard.bandwidth.limit_mb)}%`"></div>
                    </div>
                </div>
                <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Package</p>
                    <p class="text-lg font-bold text-slate-900 dark:text-white mt-2" x-text="dashboard.package || '—'"></p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">User: <span class="font-mono" x-text="dashboard.username"></span></p>
                </div>
                <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <p class="text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">Resources</p>
                    <div class="mt-2 space-y-1 text-sm text-slate-700 dark:text-slate-300">
                        <p>Email: <span x-text="`${dashboard.counts.email} / ${dashboard.counts.email_limit || '∞'}`"></span></p>
                        <p>Databases: <span x-text="`${dashboard.counts.database} / ${dashboard.counts.database_limit || '∞'}`"></span></p>
                        <p>FTP: <span x-text="`${dashboard.counts.ftp} / ${dashboard.counts.ftp_limit || '∞'}`"></span></p>
                    </div>
                </div>
            </div>

            <div class="border-b border-slate-200 dark:border-slate-700">
                <div class="flex gap-1 overflow-x-auto pb-px">
                    <template x-for="tab in sections" :key="tab.id">
                        <button type="button" @click="activeSection = tab.id; loadSection(tab.id)"
                            :class="activeSection === tab.id ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                            class="px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition" x-text="tab.label"></button>
                    </template>
                </div>
            </div>

            <div x-show="sectionLoading" class="text-sm text-slate-500 dark:text-slate-400 py-4">Loading section...</div>

            <div x-show="activeSection === 'dns' && !sectionLoading" class="space-y-4">
                <form @submit.prevent="addDns" class="grid grid-cols-1 md:grid-cols-5 gap-3 bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <input x-model="dnsForm.name" placeholder="Name (@ or host)" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <select x-model="dnsForm.type" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                        <option value="A">A</option><option value="AAAA">AAAA</option><option value="CNAME">CNAME</option><option value="MX">MX</option><option value="TXT">TXT</option>
                    </select>
                    <input x-model="dnsForm.value" placeholder="Value" class="md:col-span-2 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Add Record</button>
                </form>
                <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-slate-800 text-left text-slate-500 dark:text-slate-400"><tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Value</th><th class="px-4 py-3"></th></tr></thead>
                        <tbody>
                            <template x-if="dnsRecords.length === 0">
                                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500 dark:text-slate-400">No DNS records found.</td></tr>
                            </template>
                            <template x-for="record in dnsRecords" :key="`${record.name}-${record.type}-${record.value}`">
                                <tr class="border-t border-slate-200 dark:border-slate-700">
                                    <td class="px-4 py-3 font-mono" x-text="record.fqdn || record.name"></td>
                                    <td class="px-4 py-3" x-text="record.type"></td>
                                    <td class="px-4 py-3 font-mono break-all" x-text="record.value"></td>
                                    <td class="px-4 py-3 text-right"><button type="button" @click="deleteDns(record)" class="text-red-600 hover:underline text-xs">Delete</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div x-show="activeSection === 'emails' && !sectionLoading" class="space-y-4">
                <form @submit.prevent="addEmail" class="grid grid-cols-1 md:grid-cols-4 gap-3 bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <input x-model="emailForm.local_part" placeholder="mailbox" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="emailForm.password" type="password" placeholder="Password" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="emailForm.quota_mb" type="number" placeholder="Quota MB" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Create Mailbox</button>
                </form>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
                    <template x-if="emailAccounts.length === 0">
                        <li class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No mailboxes yet.</li>
                    </template>
                    <template x-for="mailbox in emailAccounts" :key="mailbox.email">
                        <li class="flex items-center justify-between px-4 py-3 text-sm">
                            <span class="font-mono" x-text="mailbox.email"></span>
                            <button type="button" @click="deleteEmail(mailbox)" class="text-red-600 hover:underline text-xs">Delete</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div x-show="activeSection === 'databases' && !sectionLoading" class="space-y-4">
                <form @submit.prevent="addDatabase" class="grid grid-cols-1 md:grid-cols-3 gap-3 bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <input x-model="databaseForm.name" placeholder="database_name" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="databaseForm.password" type="password" placeholder="Password" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Create Database</button>
                </form>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
                    <template x-if="databases.length === 0">
                        <li class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No databases yet.</li>
                    </template>
                    <template x-for="db in databases" :key="db.name">
                        <li class="flex items-center justify-between px-4 py-3 text-sm">
                            <span class="font-mono" x-text="db.name"></span>
                            <button type="button" @click="deleteDatabase(db)" class="text-red-600 hover:underline text-xs">Delete</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div x-show="activeSection === 'subdomains' && !sectionLoading" class="space-y-4">
                <form @submit.prevent="addSubdomain" class="grid grid-cols-1 md:grid-cols-2 gap-3 bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <input x-model="subdomainForm.subdomain" placeholder="subdomain" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Add Subdomain</button>
                </form>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
                    <template x-if="subdomains.length === 0">
                        <li class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No subdomains yet.</li>
                    </template>
                    <template x-for="sub in subdomains" :key="sub.fqdn">
                        <li class="flex items-center justify-between px-4 py-3 text-sm">
                            <span class="font-mono" x-text="sub.fqdn"></span>
                            <button type="button" @click="deleteSubdomain(sub)" class="text-red-600 hover:underline text-xs">Delete</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div x-show="activeSection === 'ftp' && !sectionLoading" class="space-y-4">
                <form @submit.prevent="addFtp" class="grid grid-cols-1 md:grid-cols-4 gap-3 bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <input x-model="ftpForm.user" placeholder="ftp user" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="ftpForm.password" type="password" placeholder="Password" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="ftpForm.path" placeholder="/path" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Create FTP</button>
                </form>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
                    <template x-if="ftpAccounts.length === 0">
                        <li class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No FTP accounts yet.</li>
                    </template>
                    <template x-for="account in ftpAccounts" :key="account.account">
                        <li class="flex items-center justify-between px-4 py-3 text-sm">
                            <span class="font-mono" x-text="account.account"></span>
                            <button type="button" @click="deleteFtp(account)" class="text-red-600 hover:underline text-xs">Delete</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div x-show="activeSection === 'ssl' && !sectionLoading" class="space-y-4">
                <div class="bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <p class="text-sm text-slate-700 dark:text-slate-300">SSL active: <strong x-text="sslInfo?.ssl_on ? 'Yes' : 'No'"></strong></p>
                    <p class="text-sm text-slate-700 dark:text-slate-300 mt-1">Let&apos;s Encrypt: <strong x-text="sslInfo?.letsencrypt ? 'Yes' : 'No'"></strong></p>
                    <button type="button" @click="installSsl()" class="mt-4 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium rounded-lg">Request Let&apos;s Encrypt SSL</button>
                </div>
            </div>

            <div x-show="activeSection === 'cron' && !sectionLoading" class="space-y-4">
                <form @submit.prevent="addCron" class="grid grid-cols-2 md:grid-cols-6 gap-3 bg-slate-50 dark:bg-slate-800 rounded-xl p-4 border border-slate-200 dark:border-slate-700">
                    <input x-model="cronForm.minute" placeholder="min" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="cronForm.hour" placeholder="hour" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="cronForm.day" placeholder="day" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="cronForm.month" placeholder="month" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="cronForm.weekday" placeholder="weekday" class="px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <input x-model="cronForm.command" placeholder="command" class="col-span-2 md:col-span-6 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm">
                    <button type="submit" class="col-span-2 md:col-span-6 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Add Cron Job</button>
                </form>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
                    <template x-if="cronJobs.length === 0">
                        <li class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No cron jobs yet.</li>
                    </template>
                    <template x-for="job in cronJobs" :key="job.id">
                        <li class="flex items-center justify-between px-4 py-3 text-sm gap-4">
                            <div class="min-w-0">
                                <p class="font-mono break-all" x-text="job.command"></p>
                                <p x-show="job.schedule" class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-text="job.schedule"></p>
                            </div>
                            <button type="button" @click="deleteCron(job)" class="text-red-600 hover:underline text-xs shrink-0">Delete</button>
                        </li>
                    </template>
                </ul>
            </div>

            <div x-show="activeSection === 'backups' && !sectionLoading" class="space-y-4">
                <button type="button" @click="createBackup()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg">Create Backup</button>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
                    <template x-if="backups.length === 0">
                        <li class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">No backups yet.</li>
                    </template>
                    <template x-for="backup in backups" :key="backup.filename">
                        <li class="px-4 py-3 text-sm font-mono" x-text="backup.filename"></li>
                    </template>
                </ul>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
function hostingPanel(config) {
    return {
        domain: config.domain,
        routes: config.routes,
        initialized: false,
        loading: false,
        sectionLoading: false,
        message: '',
        messageType: 'success',
        dashboard: null,
        activeSection: 'dns',
        sections: [
            { id: 'dns', label: 'DNS' },
            { id: 'emails', label: 'Email' },
            { id: 'databases', label: 'Databases' },
            { id: 'subdomains', label: 'Subdomains' },
            { id: 'ftp', label: 'FTP' },
            { id: 'ssl', label: 'SSL' },
            { id: 'cron', label: 'Cron' },
            { id: 'backups', label: 'Backups' },
        ],
        dnsRecords: [],
        emailAccounts: [],
        databases: [],
        subdomains: [],
        ftpAccounts: [],
        sslInfo: null,
        cronJobs: [],
        backups: [],
        dnsForm: { name: '@', type: 'A', value: '' },
        emailForm: { local_part: '', password: '', quota_mb: 250 },
        databaseForm: { name: '', password: '' },
        subdomainForm: { subdomain: '' },
        ftpForm: { user: '', password: '', path: '/' },
        cronForm: { minute: '*', hour: '*', day: '*', month: '*', weekday: '*', command: '' },

        async initOnce() {
            if (this.initialized) {
                return;
            }

            this.initialized = true;
            await this.init();
        },

        async init() {
            await this.loadDashboard();
            await this.loadSection('dns');
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]')?.content || '';
        },

        async api(url, method = 'GET', body = null) {
            const options = {
                method,
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': this.csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            };
            if (body) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
            const response = await fetch(url, options);
            const data = await response.json().catch(() => ({}));
            if (!response.ok && !data.message) {
                data.message = 'Request failed.';
                data.success = false;
            }
            return data;
        },

        notify(message, type = 'success') {
            this.message = message;
            this.messageType = type;
            setTimeout(() => { this.message = ''; }, 6000);
        },

        formatUsage(used, limit, isBandwidth = false) {
            if (limit === null || limit === undefined) {
                return `${this.formatMb(used)} / Unlimited`;
            }
            return `${this.formatMb(used)} / ${this.formatMb(limit)}`;
        },

        formatMb(value) {
            if (value === null || value === undefined) return '0 MB';
            if (value >= 1024) return `${(value / 1024).toFixed(1)} GB`;
            return `${Number(value).toFixed(0)} MB`;
        },

        usagePercent(used, limit) {
            if (!limit || limit <= 0) return 0;
            return Math.min(100, Math.round((used / limit) * 100));
        },

        async loadDashboard(force = false) {
            this.loading = true;
            try {
                const url = force
                    ? `${this.routes.dashboard}${this.routes.dashboard.includes('?') ? '&' : '?'}refresh=1`
                    : this.routes.dashboard;
                const data = await this.api(url);
                if (data.success === false) throw new Error(data.message || 'Failed to load dashboard');
                this.dashboard = data.data || data;
                if (force) this.notify('Hosting dashboard refreshed.');
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.loading = false;
            }
        },

        async loadSection(section) {
            const map = {
                dns: ['dnsIndex', 'dnsRecords'],
                emails: ['emailsIndex', 'emailAccounts'],
                databases: ['databasesIndex', 'databases'],
                subdomains: ['subdomainsIndex', 'subdomains'],
                ftp: ['ftpIndex', 'ftpAccounts'],
                ssl: ['sslShow', 'sslInfo', true],
                cron: ['cronIndex', 'cronJobs'],
                backups: ['backupsIndex', 'backups'],
            };
            const entry = map[section];
            if (!entry) return;

            this.sectionLoading = true;
            try {
                const data = await this.api(this.routes[entry[0]]);
                if (!data.success) throw new Error(data.message || 'Failed to load section');
                if (entry[2]) {
                    this[entry[1]] = data.data || {};
                } else {
                    this[entry[1]] = data.data || [];
                }
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.sectionLoading = false;
            }
        },

        async addDns() {
            const data = await this.api(this.routes.dnsStore, 'POST', this.dnsForm);
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) { this.dnsForm.value = ''; await this.loadSection('dns'); }
        },

        async deleteDns(record) {
            if (!confirm('Delete this DNS record?')) return;
            const data = await this.api(this.routes.dnsDestroy, 'DELETE', { name: record.name, type: record.type, value: record.value });
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('dns');
        },

        async addEmail() {
            const data = await this.api(this.routes.emailsStore, 'POST', this.emailForm);
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) { this.emailForm.local_part = ''; this.emailForm.password = ''; await this.loadSection('emails'); }
        },

        async deleteEmail(mailbox) {
            if (!confirm('Delete this mailbox?')) return;
            const local = mailbox.email.includes('@') ? mailbox.email.split('@')[0] : mailbox.account;
            const data = await this.api(this.routes.emailsDestroy, 'DELETE', { local_part: local });
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('emails');
        },

        async addDatabase() {
            const data = await this.api(this.routes.databasesStore, 'POST', this.databaseForm);
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) { this.databaseForm = { name: '', password: '' }; await this.loadSection('databases'); }
        },

        async deleteDatabase(db) {
            if (!confirm('Delete this database?')) return;
            const data = await this.api(this.routes.databasesDestroy, 'DELETE', { name: db.name });
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('databases');
        },

        async addSubdomain() {
            const data = await this.api(this.routes.subdomainsStore, 'POST', this.subdomainForm);
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) { this.subdomainForm.subdomain = ''; await this.loadSection('subdomains'); }
        },

        async deleteSubdomain(sub) {
            if (!confirm('Delete this subdomain?')) return;
            const data = await this.api(this.routes.subdomainsDestroy, 'DELETE', { subdomain: sub.subdomain ?? sub.fqdn });
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('subdomains');
        },

        async addFtp() {
            const data = await this.api(this.routes.ftpStore, 'POST', this.ftpForm);
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) { this.ftpForm = { user: '', password: '', path: '/' }; await this.loadSection('ftp'); }
        },

        async deleteFtp(account) {
            if (!confirm('Delete this FTP account?')) return;
            const data = await this.api(this.routes.ftpDestroy, 'DELETE', { user: account.account });
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('ftp');
        },

        async installSsl() {
            const data = await this.api(this.routes.sslLetsEncrypt, 'POST');
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('ssl');
        },

        async addCron() {
            const data = await this.api(this.routes.cronStore, 'POST', this.cronForm);
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) { this.cronForm.command = ''; await this.loadSection('cron'); }
        },

        async deleteCron(job) {
            if (!confirm('Delete this cron job?')) return;
            const data = await this.api(this.routes.cronDestroy, 'DELETE', { cron_id: job.id });
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('cron');
        },

        async createBackup() {
            const data = await this.api(this.routes.backupsStore, 'POST');
            this.notify(data.message, data.success ? 'success' : 'error');
            if (data.success) await this.loadSection('backups');
        },

        async resetPassword() {
            if (!confirm('Generate a new hosting panel password?')) return;
            const data = await this.api(this.routes.passwordReset, 'POST');
            if (data.success && data.password) {
                this.notify(`New password: ${data.password}`, 'success');
            } else {
                this.notify(data.message || 'Password reset failed.', 'error');
            }
        },
    };
}
</script>
@endpush

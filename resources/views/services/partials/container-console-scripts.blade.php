<script>
function containerEnvironmentPanel(initialRows) {
    let rowSeq = 0;

    return {
        rows: (initialRows || []).map((row) => ({
            _id: 'env-' + (++rowSeq),
            key: row.key || '',
            value: row.value || '',
            sensitive: !!row.sensitive,
            platform_managed: !!row.platform_managed,
            suggested: !!row.suggested,
            state: row.state || 'set',
            reveal: false,
            isNew: false,
        })),
        saving: false,
        importing: false,
        importMessage: '',
        importError: false,
        addRow() {
            this.rows.push({
                _id: 'env-' + (++rowSeq),
                key: '',
                value: '',
                sensitive: false,
                platform_managed: false,
                suggested: false,
                state: 'set',
                reveal: true,
                isNew: true,
            });
        },
        // A row that looks like every other row is why somebody could not tell
        // which four settings had stopped their application.
        stateLabel(row) {
            if (row.isNew) return '';
            return ({
                needs_value: 'Needs a value',
                rejected: 'Value rejected by your app',
                suggested: 'Suggested by your repository',
                platform: 'Platform-managed',
            })[row.state] || '';
        },
        stateBadgeClass(row) {
            return ({
                needs_value: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
                rejected: 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
                suggested: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                platform: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
            })[row.state] || 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
        },
        rowClass(row) {
            if (row.state === 'needs_value' || row.state === 'rejected') {
                return 'bg-red-50/60 dark:bg-red-900/10';
            }
            return 'bg-white dark:bg-slate-900';
        },
        removeRow(index) {
            const row = this.rows[index];
            if (!row) return;
            if (row.platform_managed && !row.isNew) return;

            if (row.isNew || !row.key) {
                this.rows.splice(index, 1);
                return;
            }

            // Dismissing a suggestion changes nothing about the running
            // container, so promising a restart would be a second lie on top of
            // the one where the button did nothing at all.
            const prompt = row.suggested
                ? `Stop suggesting ${row.key}? It will not be offered again.`
                : `Remove ${row.key}? The app will restart to apply.`;

            if (!confirm(prompt)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = @js(container_route('environment.delete', $service));
            form.innerHTML = `
                <input type="hidden" name="_token" value="${document.querySelector('meta[name=csrf-token]')?.content || ''}">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="keys[]" value="${row.key}">
                <input type="hidden" name="restart" value="1">
            `;
            document.body.appendChild(form);
            form.submit();
        },
        async importDotEnv(event) {
            const input = event.target;
            const file = input.files?.[0];
            this.importMessage = '';
            this.importError = false;

            if (!file) return;

            if (file.size > 256 * 1024) {
                this.importMessage = 'The .env file is too large (maximum 256 KB).';
                this.importError = true;
                input.value = '';
                return;
            }

            this.importing = true;

            try {
                const parsed = this.parseDotEnv(await file.text());
                const entries = Object.entries(parsed);

                if (entries.length === 0) {
                    throw new Error('No valid environment variables were found in this file.');
                }

                const existing = new Map(
                    this.rows.map((row, index) => [(row.key || '').trim().toUpperCase(), index])
                );
                const newKeyCount = entries.filter(([key]) => !existing.has(key)).length;

                if (this.rows.length + newKeyCount > 100) {
                    throw new Error('Import would exceed the 100-variable limit.');
                }

                for (const [key, value] of entries) {
                    if (existing.has(key)) {
                        this.rows[existing.get(key)].value = value;
                        continue;
                    }

                    this.rows.push({
                        _id: 'env-' + (++rowSeq),
                        key,
                        value,
                        sensitive: this.isSensitiveEnvKey(key),
                        platform_managed: this.isPlatformManagedEnvKey(key),
                        reveal: false,
                        isNew: true,
                    });
                    existing.set(key, this.rows.length - 1);
                }

                this.importMessage = `${entries.length} variable${entries.length === 1 ? '' : 's'} loaded from ${file.name}. Review them, then click Save & apply.`;
            } catch (error) {
                this.importMessage = error?.message || 'The .env file could not be read.';
                this.importError = true;
            } finally {
                this.importing = false;
                input.value = '';
            }
        },
        parseDotEnv(content) {
            const result = {};
            const lines = String(content || '').replace(/^\uFEFF/, '').split(/\r\n|\n|\r/);

            for (let index = 0; index < lines.length; index++) {
                let line = lines[index].trim();
                if (!line || line.startsWith('#')) continue;

                if (line.startsWith('export ')) {
                    line = line.slice(7).trimStart();
                }

                const match = line.match(/^([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.*)$/);
                if (!match) {
                    throw new Error(`Invalid .env syntax on line ${index + 1}.`);
                }

                const key = match[1].toUpperCase();
                let value = match[2];

                if (value.startsWith('"') || value.startsWith("'")) {
                    const quote = value[0];
                    let quoted = value.slice(1);

                    while (!this.hasClosingEnvQuote(quoted, quote) && index + 1 < lines.length) {
                        quoted += '\n' + lines[++index];
                    }

                    const closeAt = this.closingEnvQuoteIndex(quoted, quote);
                    if (closeAt < 0) {
                        throw new Error(`Unclosed quoted value for ${key}.`);
                    }

                    const trailing = quoted.slice(closeAt + 1).trim();
                    if (trailing && !trailing.startsWith('#')) {
                        throw new Error(`Unexpected content after ${key}.`);
                    }

                    value = quoted.slice(0, closeAt);
                    if (quote === '"') {
                        value = value.replace(/\\(n|r|t|"|\\)/g, (_, token) => ({
                            n: '\n',
                            r: '\r',
                            t: '\t',
                            '"': '"',
                            '\\': '\\',
                        })[token]);
                    }
                } else {
                    // In dotenv syntax an inline comment starts at a whitespace-prefixed #.
                    value = value.replace(/\s+#.*$/, '').trim();
                }

                result[key] = value;
            }

            return result;
        },
        hasClosingEnvQuote(value, quote) {
            return this.closingEnvQuoteIndex(value, quote) >= 0;
        },
        closingEnvQuoteIndex(value, quote) {
            for (let index = 0; index < value.length; index++) {
                if (value[index] !== quote) continue;

                let slashes = 0;
                for (let cursor = index - 1; cursor >= 0 && value[cursor] === '\\'; cursor--) {
                    slashes++;
                }
                if (slashes % 2 === 0) return index;
            }

            return -1;
        },
        isSensitiveEnvKey(key) {
            return /(PASSWORD|SECRET|TOKEN|KEY|PRIVATE|CREDENTIAL|AUTH)/i.test(key);
        },
        isPlatformManagedEnvKey(key) {
            return @js(\App\Services\Provisioning\ContainerEnvironmentService::PLATFORM_MANAGED_KEYS).includes(key);
        },
        prepareSubmit(event) {
            this.rows.forEach((row) => {
                row.key = (row.key || '').trim().toUpperCase();
            });
            // Drop blank new rows so HTML5/server validation does not block real edits.
            this.rows = this.rows.filter((row) => row.key !== '');
            if (this.rows.length === 0) {
                event.preventDefault();
                alert('Add at least one environment variable before saving.');
                return;
            }
            this.saving = true;
        },
    };
}

function containerTabs(initialTab) {
    // Must match the tab strip in container-console exactly: setTab() refuses
    // anything missing here, so a short list makes the buttons dead.
    const allowedTabs = @js($containerTabs ?? (empty($deployment)
        ? \App\Support\ContainerConsoleTabs::NOT_DEPLOYED
        : \App\Support\ContainerConsoleTabs::resolve(
            ! empty($supportsOllamaChat),
            ! empty($supportsGitRepository),
            ! empty($supportsPhpExtensions),
        )));

    return {
        activeTab: allowedTabs.includes(initialTab) ? initialTab : 'overview',
        visitedTabs: [allowedTabs.includes(initialTab) ? initialTab : 'overview'],
        fullLogs: '',
        logsLoading: false,
        logsLive: false,
        logsFetchedAt: '',
        logsTimer: null,

        init() {
            if (this.activeTab === 'logs') {
                this.loadFullLogs();
            }
            this.$watch('activeTab', (tab) => {
                if (tab !== 'logs') {
                    this.stopLiveLogs();
                }
            });
        },

        setTab(tab) {
            if (! allowedTabs.includes(tab)) {
                return;
            }
            if (!this.visitedTabs.includes(tab)) {
                this.visitedTabs.push(tab);
            }
            this.activeTab = tab;
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            history.replaceState({}, '', url);
            this.$dispatch('container-tab-shown', tab);
            if (tab === 'logs') {
                this.$nextTick(() => this.loadFullLogs());
            } else {
                this.stopLiveLogs();
            }
        },

        hasVisited(tab) {
            return this.visitedTabs.includes(tab);
        },

        toggleLiveLogs() {
            if (this.logsLive) {
                this.startLiveLogs();
            } else {
                this.stopLiveLogs();
            }
        },

        startLiveLogs() {
            this.stopLiveLogs();
            this.logsTimer = setInterval(() => this.loadFullLogs(true), 2000);
        },

        stopLiveLogs() {
            if (this.logsTimer) {
                clearInterval(this.logsTimer);
                this.logsTimer = null;
            }
            this.logsLive = false;
        },

        async loadFullLogs(silent = false) {
            if (!silent) {
                this.logsLoading = true;
            }

            try {
                const response = await fetch('{{ container_route('logs', $service) }}?lines=200', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json();

                if (data.error) {
                    this.fullLogs = `Error: ${data.error}`;
                } else {
                    this.fullLogs = data.logs || 'No logs available';
                    this.logsFetchedAt = data.fetched_at
                        ? new Date(data.fetched_at).toLocaleTimeString()
                        : new Date().toLocaleTimeString();
                    this.$nextTick(() => {
                        const el = this.$refs.fullLogsEl;
                        if (el && this.logsLive) {
                            el.scrollTop = el.scrollHeight;
                        }
                    });
                }
            } catch (error) {
                if (!silent) {
                    this.fullLogs = 'Failed to fetch logs';
                }
                console.error('Error:', error);
            } finally {
                this.logsLoading = false;
            }
        },
    };
}

function containerDoctor(config = {}) {
    return {
        diagnoseUrl: config.diagnoseUrl,
        treatUrl: config.treatUrl,
        logLines: config.logLines || 2000,
        diagnosing: false,
        treating: false,
        treatingAction: null,
        hasResult: false,
        healthy: false,
        findings: [],
        linesScanned: 0,
        scannedAt: '',
        liveChecks: null,
        error: '',
        treatMessage: '',
        treatOk: false,

        applyDiagnosis(data) {
            this.findings = data.findings || [];
            this.healthy = !!data.healthy;
            this.linesScanned = data.lines_scanned || 0;
            this.liveChecks = data.live_checks || null;
            this.scannedAt = data.scanned_at
                ? new Date(data.scanned_at).toLocaleTimeString()
                : '';
            this.hasResult = true;
        },

        async runDiagnose({ keepTreatMessage = false } = {}) {
            this.diagnosing = true;
            this.error = '';
            if (! keepTreatMessage) {
                this.treatMessage = '';
            }
            try {
                const response = await fetch(this.diagnoseUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || data.error) {
                    this.error = data.error || data.message || `Doctor scan failed (HTTP ${response.status}).`;
                    this.hasResult = false;
                    return;
                }
                this.applyDiagnosis(data);
            } catch (e) {
                this.error = 'Network error while running doctor.';
                console.error(e);
            } finally {
                this.diagnosing = false;
            }
        },

        async runTreat(finding) {
            if (!finding?.treat_action || this.treating) {
                return;
            }
            this.treating = true;
            this.treatingAction = finding.treat_action;
            this.error = '';
            this.treatMessage = '';
            this.treatOk = false;
            try {
                const response = await fetch(this.treatUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ action: finding.treat_action }),
                });
                const data = await response.json().catch(() => ({}));
                this.treatOk = !!data.success;
                this.treatMessage = data.message
                    || (data.success
                        ? 'Treatment completed.'
                        : (data.error || `Treatment failed (HTTP ${response.status}).`));

                if (data.diagnosis) {
                    this.applyDiagnosis(data.diagnosis);
                } else if (data.success) {
                    await this.runDiagnose({ keepTreatMessage: true });
                }

                this.$nextTick(() => {
                    this.$refs.treatBanner?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                });
            } catch (e) {
                this.treatOk = false;
                this.treatMessage = 'Network error while treating. Check the browser console or try again.';
                console.error(e);
            } finally {
                this.treating = false;
                this.treatingAction = null;
            }
        },
    };
}

@include('services.partials.container-redeploy-scripts')

// One POST for the Database tab's buttons. Test Connection and Repair
// Credentials are rate limited per minute, and the raw throttle body ("Too Many
// Attempts.") reached the panel with no hint that waiting is the answer.
async function postDatabaseAction(url, body = null) {
    try {
        const headers = {
            'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        };
        if (body !== null) {
            headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(url, {
            method: 'POST',
            headers,
            body: body === null ? undefined : JSON.stringify(body),
        });

        if (response.status === 429) {
            return { success: false, message: 'Too many attempts in one minute. Wait a moment, then try again.' };
        }

        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            // Keep whatever else came back: a failed run still returns its
            // output, and rebuilding the object from scratch threw it away.
            return {
                ...data,
                success: false,
                message: data.message || data.error || ('Request failed (HTTP ' + response.status + ').'),
            };
        }

        return data;
    } catch (error) {
        return { success: false, message: 'Network error. Check your connection and try again.' };
    }
}

async function runDatabaseQuery(format = 'text') {
    const queryEl = document.getElementById('db-query');
    const outEl = document.getElementById('db-query-output');
    const statusEl = document.getElementById('db-query-status');
    if (!queryEl || !outEl || !statusEl) return;

    const query = queryEl.value.trim();
    if (!query) {
        statusEl.textContent = 'Enter a query first.';
        return;
    }

    statusEl.textContent = 'Running...';
    outEl.textContent = 'Executing query...';

    try {
        const response = await fetch('{{ container_route('database.query', $service) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ query, format })
        });

        const data = await response.json().catch(() => ({}));
        if (response.status === 429) {
            statusEl.textContent = 'Rate limited';
            outEl.textContent = 'Too many queries in one minute. Wait a moment, then run it again.';
            return;
        }
        if (!response.ok) {
            statusEl.textContent = 'Failed';
            outEl.textContent = data.error || data.message || 'Query failed';
            return;
        }

        statusEl.textContent = 'Done';
        outEl.textContent = data.output || '(empty result)';
        if (format === 'csv' && data.csv) {
            const blob = new Blob([data.csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'query-result.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }
        loadDatabaseHistory();
    } catch (error) {
        statusEl.textContent = 'Failed';
        outEl.textContent = 'Request failed';
    }
}

async function importDatabaseSql() {
    const fileInput = document.getElementById('db-import-file');
    const statusEl = document.getElementById('db-import-status');
    const outEl = document.getElementById('db-import-output');
    if (!fileInput || !statusEl) return;

    const file = fileInput.files?.[0];
    if (!file) {
        statusEl.textContent = 'Choose a .sql file first.';
        return;
    }

    const maxBytes = {{ $dbImportMaxMb }} * 1024 * 1024;
    if (file.size > maxBytes) {
        statusEl.textContent = `File exceeds {{ $dbImportMaxMb }} MB limit.`;
        return;
    }

    if (!(await window.appConfirm('Import this SQL file into your service database? This may change or overwrite existing data.', 'Import SQL', 'Import'))) {
        return;
    }

    statusEl.textContent = 'Importing...';
    if (outEl) {
        outEl.classList.add('hidden');
        outEl.textContent = '';
    }

    const chunkSize = 512 * 1024;
    const chunkTotal = Math.max(1, Math.ceil(file.size / chunkSize));
    const uploadId = Array.from(crypto.getRandomValues(new Uint8Array(16)))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
    const csrf = document.head.querySelector('meta[name="csrf-token"]').content;
    const importUrl = '{{ container_route('database.import', $service) }}';

    try {
        let data = {};
        for (let index = 0; index < chunkTotal; index++) {
            statusEl.textContent = chunkTotal === 1
                ? 'Importing...'
                : `Uploading dump ${index + 1}/${chunkTotal}…`;
            const chunk = file.slice(index * chunkSize, Math.min(file.size, (index + 1) * chunkSize));
            const formData = new FormData();
            formData.append('file', chunk, file.name);
            formData.append('filename', file.name);
            formData.append('upload_id', uploadId);
            formData.append('chunk_index', String(index));
            formData.append('chunk_total', String(chunkTotal));

            const response = await fetch(importUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: formData,
            });

            try {
                data = await response.json();
            } catch {
                statusEl.textContent = 'Import failed';
                if (outEl) {
                    outEl.classList.remove('hidden');
                    outEl.textContent = 'The server did not return JSON. The upload may have exceeded PHP/nginx size limits.';
                }
                return;
            }

            if (!response.ok) {
                const detail = data.error
                    || data.message
                    || Object.values(data.errors || {}).flat().join(' ')
                    || 'Import failed';
                statusEl.textContent = 'Import failed';
                if (outEl) {
                    outEl.classList.remove('hidden');
                    outEl.textContent = detail;
                }
                return;
            }

            if (data.pending && index < chunkTotal - 1) {
                continue;
            }
        }

        statusEl.textContent = 'Import complete';
        if (outEl) {
            outEl.classList.remove('hidden');
            outEl.textContent = data.output || data.message || 'Done';
        }
        fileInput.value = '';
        loadDatabaseHistory();
    } catch (error) {
        statusEl.textContent = 'Import failed';
        if (outEl) {
            outEl.classList.remove('hidden');
            outEl.textContent = error?.message || 'Network or browser error while uploading the dump.';
        }
    }
}

async function loadDatabaseHistory() {
    const historyEl = document.getElementById('db-query-history');
    if (!historyEl) return;

    historyEl.innerHTML = '<div class="p-3 text-sm text-slate-500 dark:text-slate-400">Loading history...</div>';
    try {
        const response = await fetch('{{ container_route('database.history', $service) }}', {
            headers: { 'Accept': 'application/json' }
        });
        const data = await response.json();

        if (!response.ok) {
            historyEl.innerHTML = `<div class="p-3 text-sm text-red-600">${data.error || 'Failed to load history'}</div>`;
            return;
        }

        const rows = data.history || [];
        if (!rows.length) {
            historyEl.innerHTML = '<div class="p-3 text-sm text-slate-500 dark:text-slate-400">No query history yet.</div>';
            return;
        }

        historyEl.innerHTML = rows.map((row) => {
            const stateClass = row.success ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
            const stateText = row.success ? 'OK' : 'Failed';
            const label = row.action === 'db_import' ? 'Import' : 'Query';
            return `<div class="p-3 text-xs">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-slate-500 dark:text-slate-400">${row.at || ''} · ${label}</span>
                    <span class="${stateClass} font-semibold">${stateText}</span>
                </div>
                <div class="text-slate-700 dark:text-slate-300 font-mono break-all">${row.query || ''}</div>
            </div>`;
        }).join('');
    } catch (error) {
        historyEl.innerHTML = '<div class="p-3 text-sm text-red-600">Failed to load history</div>';
    }
}

// The Database tab's migration step. Detection runs once when the tab opens,
// the same way the table browser lists tables, so the command is on screen
// before anyone reaches for it.
function dbMigrationRunner(planUrl, runUrl, dbUsername) {
    return {
        planUrl,
        runUrl,
        dbUsername,
        detecting: true,
        running: false,
        plan: null,
        error: '',
        confirmation: '',
        result: null,
        init() {
            this.detect();
        },
        get canRun() {
            return !!this.plan && !this.running && this.confirmation.trim() === this.dbUsername;
        },
        async detect() {
            this.detecting = true;
            this.error = '';

            try {
                const response = await fetch(this.planUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    this.error = data.error || data.message || 'Could not check this application for migrations.';
                    return;
                }

                this.plan = data.available ? data.plan : null;
            } catch (error) {
                this.error = 'Network error while checking for migrations.';
            } finally {
                this.detecting = false;
            }
        },
        async run() {
            if (!this.canRun) return;

            this.running = true;
            this.result = null;
            this.result = await postDatabaseAction(this.runUrl, { confirm_username: this.confirmation.trim() });
            this.running = false;

            if (this.result?.success) {
                this.confirmation = '';
            }
        },
    };
}

// Swapping the database image restarts Postgres, so it is confirmed the same
// way a migration is: the database username, typed.
function dbPostgisEnabler(runUrl, dbUsername) {
    return {
        runUrl,
        dbUsername,
        running: false,
        confirmation: '',
        result: null,
        get canRun() {
            return !this.running && this.confirmation.trim() === this.dbUsername;
        },
        async run() {
            if (!this.canRun) return;

            this.running = true;
            this.result = null;
            this.result = await postDatabaseAction(this.runUrl, { confirm_username: this.confirmation.trim() });
            this.running = false;

            if (this.result?.success) {
                this.confirmation = '';
            }
        },
    };
}

function dbTableBrowser(dbType) {
    return {
        dbType: (dbType || 'mysql').toLowerCase(),
        tables: [],
        selected: null,
        preview: '',
        loading: false,
        error: '',
        init() {
            this.loadTables();
        },
        isPostgres() {
            return this.dbType === 'postgresql' || this.dbType === 'postgres';
        },
        listTablesSql() {
            if (this.isPostgres()) {
                return "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public' ORDER BY tablename";
            }

            return 'SHOW TABLES';
        },
        previewSql(table) {
            if (this.isPostgres()) {
                return `SELECT * FROM "${table}" LIMIT 25`;
            }

            return `SELECT * FROM \`${table}\` LIMIT 25`;
        },
        parseTableNames(output) {
            const lines = String(output || '')
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter(Boolean);

            return lines.filter((line) => {
                if (/^Tables_in_/i.test(line)) return false;
                if (/^tablename$/i.test(line)) return false;
                if (/^table_name$/i.test(line)) return false;
                if (/^-{3,}$/.test(line)) return false;
                if (/^\(\d+\s+rows?\)$/i.test(line)) return false;
                if (line.toLowerCase() === 'null') return false;

                return true;
            }).map((line) => line.split(/\s+/)[0]).filter(Boolean);
        },
        async runQuery(sql) {
            const response = await fetch('{{ container_route('database.query', $service) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ query: sql, format: 'text' }),
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.error || 'Query failed');
            }
            return data.output || data.result || '';
        },
        async loadTables() {
            this.loading = true;
            this.error = '';
            try {
                const output = await this.runQuery(this.listTablesSql());
                this.tables = this.parseTableNames(output);
            } catch (e) {
                this.error = e.message || 'Failed to list tables';
                this.tables = [];
            } finally {
                this.loading = false;
            }
        },
        async previewTable(table) {
            this.selected = table;
            this.preview = 'Loading…';
            try {
                const safe = String(table).replace(/[^a-zA-Z0-9_]/g, '');
                if (!safe) {
                    this.preview = 'Invalid table name';
                    return;
                }
                this.preview = await this.runQuery(this.previewSql(safe));
            } catch (e) {
                this.preview = e.message || 'Preview failed';
            }
        },
    };
}
</script>

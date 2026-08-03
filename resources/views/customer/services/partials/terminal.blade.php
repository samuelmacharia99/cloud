@php
    $terminalTemplateSlug = $service->product?->containerTemplate?->slug ?? '';
    $terminalContainerName = $deployment->container_name ?? 'container';
    $maxTerminalTabs = max(1, (int) config('terminal.session.max_per_user_service', 3));
@endphp
<div
    x-data="containerTerminal()"
    x-init="init()"
    class="space-y-4"
    :class="fullscreen ? 'fixed inset-0 z-[90] p-4 bg-slate-950/95' : ''"
>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white" :class="fullscreen ? 'text-white' : ''">Terminal</h3>
        <div class="flex flex-wrap items-center gap-2">
            <template x-if="terminalVisible">
                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" @click="copySelection()" class="px-2.5 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700" title="Copy selection (Ctrl/Cmd+Shift+C)">Copy</button>
                    <button type="button" @click="pasteFromClipboard()" class="px-2.5 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700" title="Paste (Ctrl/Cmd+Shift+V)">Paste</button>
                    <button type="button" @click="fontSize = Math.max(10, fontSize - 1); applyFontSize()" class="px-2 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300" title="Decrease font">A−</button>
                    <button type="button" @click="fontSize = Math.min(24, fontSize + 1); applyFontSize()" class="px-2 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300" title="Increase font">A+</button>
                    <button type="button" @click="searchOpen = !searchOpen; $nextTick(() => { if (searchOpen) $refs.searchInput?.focus(); })" class="px-2.5 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300" title="Search (Ctrl/Cmd+Shift+F)">Find</button>
                    <select x-model="themeName" @change="applyTheme()" class="px-2 py-1.5 rounded text-xs bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 border-0">
                        <option value="slate">Slate</option>
                        <option value="classic">Classic</option>
                        <option value="light">Light</option>
                    </select>
                    <button type="button" @click="showShortcuts = !showShortcuts" class="px-2.5 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300" title="Keyboard shortcuts">?</button>
                    <button type="button" @click="extendSession()" class="px-2.5 py-1.5 rounded text-xs font-medium bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300" title="Extend session">Extend</button>
                    <button type="button" @click="toggleFullscreen()" class="px-2.5 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300" x-text="fullscreen ? 'Exit full screen' : 'Full screen'"></button>
                    <button type="button" @click="addTab()" :disabled="tabs.length >= maxTabs || sessionStarting" class="px-2.5 py-1.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 disabled:opacity-40" title="New tab">+ Tab</button>
                </div>
            </template>
            <button @click="toggleTerminal()" :class="terminalVisible ? 'bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300' : 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'" class="px-3 py-1.5 rounded text-sm font-medium transition">
                <span x-text="terminalVisible ? 'Close Terminal' : 'Open Terminal'"></span>
            </button>
        </div>
    </div>

    <div x-show="terminalVisible" class="bg-slate-900 rounded-lg overflow-hidden border border-slate-700 flex flex-col" :class="fullscreen ? 'h-full rounded-xl' : ''">
        <div class="flex items-center gap-1 px-2 pt-2 bg-slate-950 border-b border-slate-800 overflow-x-auto" x-show="tabs.length > 0">
            <template x-for="(tab, index) in tabs" :key="tab.id">
                <button
                    type="button"
                    @click="switchTab(index)"
                    class="group inline-flex items-center gap-2 px-3 py-1.5 rounded-t text-xs font-medium whitespace-nowrap"
                    :class="activeTabIndex === index ? 'bg-slate-900 text-slate-100' : 'bg-slate-800/60 text-slate-400 hover:text-slate-200'"
                >
                    <span x-text="tab.label"></span>
                    <span
                        class="w-1.5 h-1.5 rounded-full"
                        :class="{
                            'bg-emerald-400': tab.connectionState === 'live',
                            'bg-amber-400': ['connecting', 'reconnecting', 'http'].includes(tab.connectionState),
                            'bg-red-400': ['disconnected', 'expired', 'error'].includes(tab.connectionState),
                        }"
                    ></span>
                    <span @click.stop="closeTab(index)" class="opacity-60 hover:opacity-100 text-slate-400 hover:text-red-300" title="Close tab">×</span>
                </button>
            </template>
        </div>

        <div x-show="searchOpen" class="flex items-center gap-2 px-3 py-2 bg-slate-800 border-b border-slate-700">
            <input
                x-ref="searchInput"
                type="text"
                x-model="searchQuery"
                @keydown.enter.prevent="findNext()"
                @keydown.escape.prevent="searchOpen = false"
                placeholder="Find in terminal…"
                class="flex-1 rounded border-0 bg-slate-900 text-slate-100 text-xs px-2 py-1.5 focus:ring-1 focus:ring-blue-500"
            >
            <button type="button" @click="findPrevious()" class="text-xs text-slate-300 px-2 py-1 hover:text-white">Prev</button>
            <button type="button" @click="findNext()" class="text-xs text-slate-300 px-2 py-1 hover:text-white">Next</button>
            <button type="button" @click="searchOpen = false; clearSearch()" class="text-xs text-slate-400 px-2 py-1">Esc</button>
        </div>

        <div x-show="showShortcuts" class="px-3 py-2 bg-slate-800/80 border-b border-slate-700 text-xs text-slate-300 space-y-1">
            <p class="font-semibold text-slate-100">Shortcuts</p>
            <p>Ctrl/Cmd+Shift+C — copy selection · Ctrl/Cmd+Shift+V — paste · Ctrl/Cmd+Shift+F — find</p>
            <p>Ctrl+L — clear (HTTP mode) · Ctrl+C — interrupt · Esc — exit full screen / close find</p>
            <p>Dangerous system commands are blocked when you press Enter; a hint explains safer alternatives.</p>
        </div>

        <div
            id="terminal"
            class="text-sm font-mono text-slate-100 flex-1"
            :style="fullscreen ? 'min-height: 0; height: 100%;' : 'height: 480px;'"
            @contextmenu.prevent="onContextMenu($event)"
        ></div>

        <div class="bg-slate-800 border-t border-slate-700 px-3 py-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-400">
            <div class="flex flex-wrap items-center gap-2 min-w-0">
                <span class="inline-block w-2 h-2 rounded-full"
                      :class="{
                          'bg-emerald-500 animate-pulse': connectionState === 'live',
                          'bg-amber-500 animate-pulse': ['connecting', 'reconnecting', 'http'].includes(connectionState),
                          'bg-red-500': ['disconnected', 'expired', 'error', 'idle'].includes(connectionState),
                      }"></span>
                <span x-text="statusLabel()"></span>
                <span x-show="shellIdentity" class="font-mono text-slate-300 truncate" x-text="shellIdentity"></span>
                <span x-show="mode === 'http' && commandBusy" class="text-amber-400">Running command…</span>
            </div>
            <div class="flex items-center gap-3 text-right">
                <span x-show="mode === 'http'" x-text="`Commands: ${commandCount}`"></span>
                <span x-show="sessionExpires" x-text="sessionExpires"></span>
            </div>
        </div>
    </div>

    <div x-show="!terminalVisible && !sessionStarting" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-2">
        <p class="text-sm text-blue-800 dark:text-blue-200">
            @if (in_array($terminalTemplateSlug, ['laravel', 'php'], true))
                Interactive shell for your app. Supports <code class="font-mono text-xs">composer</code>, <code class="font-mono text-xs">php artisan</code>, and file cleanup in <code class="font-mono text-xs">/app</code>.
            @elseif ($terminalTemplateSlug === 'nodejs')
                Interactive shell for your Node.js app. Run <code class="font-mono text-xs">npm</code>, <code class="font-mono text-xs">node</code>, and inspect files in <code class="font-mono text-xs">/app</code>.
            @else
                Interactive shell for your app. Run stack commands and inspect files in <code class="font-mono text-xs">/app</code>.
            @endif
        </p>
        <p class="text-xs text-blue-700 dark:text-blue-400">
            Opens as a full interactive PTY when the terminal service is available. If not, falls back to one command at a time with clear status.
        </p>
        <p class="text-xs text-blue-700 dark:text-blue-400">
            Supports tabs, full screen, search, themes, and session extend. Dangerous host commands stay blocked.
        </p>
    </div>

    <div x-show="sessionStarting" class="flex items-center justify-center py-8">
        <div class="inline-flex items-center gap-2">
            <div class="w-4 h-4 bg-blue-500 rounded-full animate-bounce"></div>
            <span class="text-slate-600 dark:text-slate-400">Starting terminal session...</span>
        </div>
    </div>
</div>

<script src="{{ asset('js/xterm/xterm.js') }}"></script>
<script src="{{ asset('js/xterm/xterm-addon-fit.js') }}"></script>
<script src="{{ asset('js/xterm/xterm-addon-search.js') }}"></script>
<script src="{{ asset('js/xterm/xterm-addon-web-links.js') }}"></script>
<link rel="stylesheet" href="{{ asset('css/xterm.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/xterm-addon-fit.min.css') }}">

@push('scripts')
<script>
function containerTerminal() {
    const SERVICE_ID = {{ (int) $service->id }};
    const CONTAINER_NAME = @json($terminalContainerName);
    const TEMPLATE_SLUG = @json($terminalTemplateSlug);
    const MAX_TABS = {{ (int) $maxTerminalTabs }};
    const THEMES = {
        slate: { background: '#0f172a', foreground: '#e2e8f0', cursor: '#94a3b8', selectionBackground: '#334155' },
        classic: { background: '#001100', foreground: '#33ff66', cursor: '#33ff66', selectionBackground: '#003300' },
        light: { background: '#f8fafc', foreground: '#0f172a', cursor: '#2563eb', selectionBackground: '#cbd5e1' },
    };

    return {
        terminal: null,
        fitAddon: null,
        searchAddon: null,
        terminalVisible: false,
        sessionStarting: false,
        connected: false,
        connectionState: 'idle',
        mode: null,
        sessionToken: null,
        websocketUrl: null,
        websocketPath: '/container-terminal',
        websocketEnabled: true,
        ws: null,
        cwd: '/app',
        shellUser: 'app',
        containerName: CONTAINER_NAME,
        inputBuffer: '',
        history: [],
        historyIndex: 0,
        commandCount: 0,
        commandBusy: false,
        commandProgressTimer: null,
        sessionExpires: null,
        expiresAtIso: null,
        expiryUpdateInterval: null,
        keepaliveInterval: null,
        reconnectAttempts: 0,
        reconnectTimer: null,
        intentionalClose: false,
        fullscreen: false,
        fontSize: 14,
        themeName: 'slate',
        searchOpen: false,
        searchQuery: '',
        showShortcuts: false,
        maxTabs: MAX_TABS,
        tabs: [],
        activeTabIndex: 0,
        tabSeq: 0,

        get shellIdentity() {
            if (!this.terminalVisible || !this.connected) {
                return '';
            }
            return `${this.shellUser}@${this.containerName}:${this.cwd}`;
        },

        init() {
            document.addEventListener('keydown', (event) => this.handleGlobalKeys(event));
            window.addEventListener('resize', () => this.fitAndResize());
        },

        statusLabel() {
            switch (this.connectionState) {
                case 'connecting': return 'Connecting…';
                case 'live': return 'Live PTY';
                case 'http': return 'HTTP fallback (one command at a time)';
                case 'reconnecting': return `Reconnecting… (${this.reconnectAttempts})`;
                case 'expired': return 'Session expired';
                case 'error': return 'Connection error';
                case 'disconnected': return 'Disconnected';
                default: return this.connected ? 'Connected' : 'Ready';
            }
        },

        csrfHeaders() {
            return {
                'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            };
        },

        async toggleTerminal() {
            if (this.terminalVisible) {
                await this.closeAllTabs();
            } else {
                await this.openTerminal();
            }
        },

        async openTerminal() {
            this.terminalVisible = true;
            this.intentionalClose = false;
            await this.$nextTick();
            if (!this.terminal) {
                this.initializeTerminal();
            }
            await this.createTabSession(true);
        },

        async addTab() {
            if (this.tabs.length >= this.maxTabs) {
                this.terminal.write('\r\n\x1b[33mMaximum number of terminal tabs reached.\x1b[0m\r\n');
                return;
            }
            await this.createTabSession(false);
        },

        persistActiveTab() {
            if (!this.tabs[this.activeTabIndex]) {
                return;
            }
            const tab = this.tabs[this.activeTabIndex];
            tab.sessionToken = this.sessionToken;
            tab.websocketUrl = this.websocketUrl;
            tab.websocketPath = this.websocketPath;
            tab.mode = this.mode;
            tab.cwd = this.cwd;
            tab.shellUser = this.shellUser;
            tab.containerName = this.containerName;
            tab.commandCount = this.commandCount;
            tab.connectionState = this.connectionState;
            tab.connected = this.connected;
            tab.expiresAtIso = this.expiresAtIso;
            tab.history = [...this.history];
            tab.inputBuffer = this.inputBuffer;
        },

        loadTab(tab) {
            this.sessionToken = tab.sessionToken;
            this.websocketUrl = tab.websocketUrl;
            this.websocketPath = tab.websocketPath || '/container-terminal';
            this.mode = tab.mode;
            this.cwd = tab.cwd || '/app';
            this.shellUser = tab.shellUser || 'app';
            this.containerName = tab.containerName || CONTAINER_NAME;
            this.commandCount = tab.commandCount || 0;
            this.connectionState = tab.connectionState || 'disconnected';
            this.connected = !!tab.connected;
            this.expiresAtIso = tab.expiresAtIso;
            this.history = tab.history || [];
            this.historyIndex = this.history.length;
            this.inputBuffer = tab.inputBuffer || '';
            this.trackSessionExpiry(this.expiresAtIso);
        },

        async switchTab(index) {
            if (index === this.activeTabIndex || !this.tabs[index]) {
                return;
            }
            this.persistActiveTab();
            this.stopKeepalive();
            if (this.ws) {
                this.intentionalClose = true;
                this.ws.close();
                this.ws = null;
                this.intentionalClose = false;
            }
            this.activeTabIndex = index;
            this.loadTab(this.tabs[index]);
            this.terminal.clear();
            this.terminal.write(`\x1b[90m— ${this.tabs[index].label} —\x1b[0m\r\n`);
            if (this.mode === 'pty' && this.sessionToken) {
                try {
                    this.connectionState = 'reconnecting';
                    await this.connectWebSocket({ reconnecting: true });
                    this.mode = 'pty';
                    this.connected = true;
                    this.connectionState = 'live';
                    this.startKeepalive();
                    this.terminal.write('\x1b[32m✓ Tab attached (PTY)\x1b[0m\r\n');
                } catch (e) {
                    this.enableHttpFallback({ welcome_message: 'Using HTTP mode for this tab.' });
                }
            } else if (this.mode === 'http') {
                this.connectionState = 'http';
                this.connected = true;
                this.writePrompt();
            }
            this.persistActiveTab();
            this.terminal.focus();
        },

        async closeTab(index) {
            const tab = this.tabs[index];
            if (!tab) {
                return;
            }
            if (tab.sessionToken) {
                try {
                    await fetch(`/my/services/${SERVICE_ID}/terminal`, {
                        method: 'DELETE',
                        headers: this.csrfHeaders(),
                        body: JSON.stringify({ session_token: tab.sessionToken }),
                    });
                } catch (e) {}
            }
            this.tabs.splice(index, 1);
            if (this.tabs.length === 0) {
                await this.closeTerminalUi();
                return;
            }
            const next = Math.min(index, this.tabs.length - 1);
            this.activeTabIndex = next;
            this.loadTab(this.tabs[next]);
            if (this.mode === 'pty' && this.sessionToken) {
                try {
                    await this.connectWebSocket({ reconnecting: true });
                    this.connectionState = 'live';
                    this.connected = true;
                    this.startKeepalive();
                } catch (e) {
                    this.enableHttpFallback({ welcome_message: '' });
                }
            }
        },

        async createTabSession(isFirst) {
            this.sessionStarting = true;
            this.connectionState = 'connecting';
            this.connected = false;
            try {
                const response = await fetch(`/my/services/${SERVICE_ID}/terminal`, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                if (parseError || !response.ok) {
                    this.connectionState = 'error';
                    this.terminal.write('\r\n❌ ' + ((data && data.error) || 'Failed to create terminal session') + '\r\n');
                    return;
                }

                this.tabSeq += 1;
                const tab = {
                    id: `t${this.tabSeq}`,
                    label: `Terminal ${this.tabSeq}`,
                    sessionToken: data.session_token,
                    websocketUrl: data.websocket_url,
                    websocketPath: data.websocket_path || '/container-terminal',
                    mode: null,
                    cwd: data.cwd || '/app',
                    shellUser: data.shell_user || 'app',
                    containerName: data.container_name || CONTAINER_NAME,
                    commandCount: 0,
                    connectionState: 'connecting',
                    connected: false,
                    expiresAtIso: data.expires_at,
                    history: [],
                    inputBuffer: '',
                };

                if (!isFirst) {
                    this.persistActiveTab();
                    if (this.ws) {
                        this.intentionalClose = true;
                        this.ws.close();
                        this.ws = null;
                        this.intentionalClose = false;
                    }
                    this.stopKeepalive();
                }

                this.tabs.push(tab);
                this.activeTabIndex = this.tabs.length - 1;
                this.loadTab(tab);
                this.websocketEnabled = data.websocket_enabled !== false;
                this.terminal.write(isFirst ? '\r\n' : `\r\n\x1b[90m— ${tab.label} —\x1b[0m\r\n`);

                try {
                    if (!this.websocketEnabled) {
                        throw new Error('WebSocket disabled');
                    }
                    await this.connectWebSocket();
                    this.mode = 'pty';
                    this.connected = true;
                    this.connectionState = 'live';
                    this.reconnectAttempts = 0;
                    this.terminal.write('✓ ' + (data.welcome_message || 'Connected.') + '\r\n');
                    this.startKeepalive();
                } catch (error) {
                    this.enableHttpFallback(data);
                }

                this.persistActiveTab();
                this.terminal.focus();
            } catch (error) {
                this.connectionState = 'error';
                this.terminal.write('\r\n❌ Error: ' + error.message + '\r\n');
            } finally {
                this.sessionStarting = false;
            }
        },

        buildWebSocketUrl() {
            const token = encodeURIComponent(this.sessionToken);
            if (this.websocketUrl) {
                return `${this.websocketUrl}?token=${token}`;
            }
            const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const path = this.websocketPath.startsWith('/') ? this.websocketPath : `/${this.websocketPath}`;
            return `${scheme}//${window.location.host}${path}?token=${token}`;
        },

        connectWebSocket(options = {}) {
            return new Promise((resolve, reject) => {
                if (this.ws) {
                    this.intentionalClose = true;
                    this.ws.close();
                    this.ws = null;
                    this.intentionalClose = false;
                }

                this.ws = new WebSocket(this.buildWebSocketUrl());
                let settled = false;

                this.ws.onopen = () => {
                    settled = true;
                    this.sendResize();
                    resolve();
                };

                this.ws.onmessage = (event) => {
                    this.terminal.write(event.data);
                };

                this.ws.onerror = () => {
                    if (!settled) {
                        settled = true;
                        reject(new Error('WebSocket connection failed'));
                    }
                };

                this.ws.onclose = () => {
                    if (this.intentionalClose) {
                        return;
                    }
                    if (this.mode === 'pty') {
                        this.connected = false;
                        this.connectionState = 'disconnected';
                        this.schedulePtyReconnect();
                    }
                };
            });
        },

        schedulePtyReconnect() {
            if (this.intentionalClose || !this.terminalVisible || !this.sessionToken) {
                return;
            }
            if (this.reconnectAttempts >= 8) {
                this.connectionState = 'error';
                this.terminal.write('\r\n\x1b[31m✗ Could not reconnect PTY. Switching to HTTP fallback.\x1b[0m\r\n');
                this.enableHttpFallback({ welcome_message: 'HTTP fallback after reconnect failure.' });
                return;
            }
            this.reconnectAttempts += 1;
            this.connectionState = 'reconnecting';
            const delay = Math.min(10000, 500 * Math.pow(2, this.reconnectAttempts - 1));
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = setTimeout(async () => {
                try {
                    await this.connectWebSocket({ reconnecting: true });
                    this.mode = 'pty';
                    this.connected = true;
                    this.connectionState = 'live';
                    this.reconnectAttempts = 0;
                    this.startKeepalive();
                    this.terminal.write('\r\n\x1b[32m✓ Reconnected to PTY\x1b[0m\r\n');
                    this.persistActiveTab();
                } catch (e) {
                    this.schedulePtyReconnect();
                }
            }, delay);
        },

        startKeepalive() {
            this.stopKeepalive();
            this.keepaliveInterval = setInterval(() => {
                if (this.mode === 'pty' && this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(JSON.stringify({ type: 'ping' }));
                }
            }, 120000);
        },

        stopKeepalive() {
            if (this.keepaliveInterval) {
                clearInterval(this.keepaliveInterval);
                this.keepaliveInterval = null;
            }
        },

        enableHttpFallback(data) {
            if (this.ws) {
                this.intentionalClose = true;
                this.ws.close();
                this.ws = null;
                this.intentionalClose = false;
            }
            this.stopKeepalive();
            this.mode = 'http';
            this.connected = true;
            this.connectionState = 'http';
            this.terminal.write('\x1b[33m⚠ Interactive WebSocket unavailable. Using HTTP command mode (one line at a time).\x1b[0m\r\n');
            this.terminal.write('\x1b[90m  Long commands (artisan, composer, npm) show progress while running.\x1b[0m\r\n');
            if (TEMPLATE_SLUG === 'laravel') {
                this.terminal.write('  Tip: use Overview → Clear /app if Initialize Laravel is blocked by leftover files.\r\n');
            } else if (TEMPLATE_SLUG === 'nodejs') {
                this.terminal.write('  Tip: for Node apps, prefer Git → Pull with Force clean rebuild instead of manual npm run build.\r\n');
            }
            if (data?.welcome_message) {
                this.terminal.write('✓ ' + data.welcome_message + '\r\n');
            }
            this.writePrompt();
            this.persistActiveTab();
        },

        themes() {
            return THEMES;
        },

        initializeTerminal() {
            const TerminalClass = window.Terminal;
            this.terminal = new TerminalClass({
                theme: THEMES[this.themeName] || THEMES.slate,
                fontFamily: 'Menlo, Monaco, "Cascadia Code", "Ubuntu Mono", Consolas, monospace',
                fontSize: this.fontSize,
                cursorBlink: true,
                convertEol: true,
                scrollback: 5000,
                rightClickSelectsWord: true,
                allowProposedApi: true,
            });

            const FitAddonClass = (window.FitAddon && window.FitAddon.FitAddon) ? window.FitAddon.FitAddon : window.FitAddon;
            const SearchAddonClass = (window.SearchAddon && window.SearchAddon.SearchAddon) ? window.SearchAddon.SearchAddon : window.SearchAddon;
            const WebLinksAddonClass = (window.WebLinksAddon && window.WebLinksAddon.WebLinksAddon) ? window.WebLinksAddon.WebLinksAddon : window.WebLinksAddon;

            this.fitAddon = new FitAddonClass();
            this.searchAddon = new SearchAddonClass();
            this.terminal.loadAddon(this.fitAddon);
            this.terminal.loadAddon(this.searchAddon);
            if (WebLinksAddonClass) {
                this.terminal.loadAddon(new WebLinksAddonClass());
            }

            this.terminal.open(document.getElementById('terminal'));
            try { this.fitAddon.fit(); } catch (e) {}

            this.terminal.attachCustomKeyEventHandler((event) => {
                const mod = event.metaKey || event.ctrlKey;
                if (mod && event.shiftKey && event.key.toLowerCase() === 'c') {
                    event.preventDefault();
                    this.copySelection();
                    return false;
                }
                if (mod && event.shiftKey && event.key.toLowerCase() === 'v') {
                    event.preventDefault();
                    this.pasteFromClipboard();
                    return false;
                }
                if (mod && event.shiftKey && event.key.toLowerCase() === 'f') {
                    event.preventDefault();
                    this.searchOpen = true;
                    this.$nextTick(() => this.$refs.searchInput?.focus());
                    return false;
                }
                return true;
            });

            this.terminal.onData((data) => {
                if (this.mode === 'pty' && this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(data);
                    return;
                }
                if (this.mode === 'http') {
                    this.handleHttpInput(data);
                }
            });

            if (typeof this.terminal.onResize === 'function') {
                this.terminal.onResize(({ cols, rows }) => this.sendResize(cols, rows));
            }

            const terminalElement = document.getElementById('terminal');
            if (terminalElement) {
                terminalElement.addEventListener('click', () => this.terminal.focus());
            }
        },

        applyTheme() {
            if (!this.terminal) return;
            this.terminal.options.theme = THEMES[this.themeName] || THEMES.slate;
        },

        applyFontSize() {
            if (!this.terminal) return;
            this.terminal.options.fontSize = this.fontSize;
            this.fitAndResize();
        },

        fitAndResize() {
            try {
                this.fitAddon?.fit();
                this.sendResize();
            } catch (e) {}
        },

        toggleFullscreen() {
            this.fullscreen = !this.fullscreen;
            this.$nextTick(() => {
                this.fitAndResize();
                this.terminal?.focus();
            });
        },

        handleGlobalKeys(event) {
            if (!this.terminalVisible) return;
            if (event.key === 'Escape') {
                if (this.searchOpen) {
                    this.searchOpen = false;
                    this.clearSearch();
                    return;
                }
                if (this.fullscreen) {
                    this.fullscreen = false;
                    this.$nextTick(() => this.fitAndResize());
                }
            }
        },

        findNext() {
            if (!this.searchAddon || !this.searchQuery) return;
            this.searchAddon.findNext(this.searchQuery, { caseSensitive: false });
        },

        findPrevious() {
            if (!this.searchAddon || !this.searchQuery) return;
            this.searchAddon.findPrevious(this.searchQuery, { caseSensitive: false });
        },

        clearSearch() {
            try { this.searchAddon?.clearDecorations(); } catch (e) {}
        },

        async copySelection() {
            if (!this.terminal) return;
            const selection = this.terminal.getSelection();
            if (!selection) {
                this.terminal.write('\r\n\x1b[90m(no selection to copy)\x1b[0m\r\n');
                if (this.mode === 'http') this.writePrompt();
                return;
            }
            try {
                await navigator.clipboard.writeText(selection);
            } catch (e) {
                this.terminal.write('\r\n⚠ Could not write clipboard.\r\n');
            }
            this.terminal.focus();
        },

        async pasteFromClipboard() {
            if (!this.connected || !this.terminal) return;
            try {
                const text = await navigator.clipboard.readText();
                if (!text) return;
                if (this.mode === 'pty' && this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(text);
                } else if (this.mode === 'http') {
                    this.insertText(text);
                }
                this.terminal.focus();
            } catch (error) {
                this.terminal.write('\r\n⚠ Could not read clipboard. Use Ctrl+Shift+V.\r\n');
                if (this.mode === 'http') this.writePrompt();
            }
        },

        onContextMenu(event) {
            // Prefer browser paste via Ctrl/Cmd+Shift+V; provide quick paste action.
            this.pasteFromClipboard();
        },

        handleHttpInput(data) {
            if (!this.connected || this.mode !== 'http') return;

            if (data.startsWith('\x1b[200~')) {
                this.insertText(data.replace(/^\x1b\[200~/, '').replace(/\x1b\[201~$/, ''));
                return;
            }
            if (data.length > 1) {
                this.insertText(data);
                return;
            }

            const key = data;
            if (key === '\x03') {
                this.inputBuffer = '';
                this.terminal.write('^C\r\n');
                this.writePrompt();
                return;
            }
            if (key === '\x0c') {
                this.terminal.clear();
                this.writePrompt();
                return;
            }
            if (key === '\r' || key === '\n') {
                if (this.inputBuffer.trim()) {
                    this.sendCommand(this.inputBuffer);
                    this.history.push(this.inputBuffer);
                    this.historyIndex = this.history.length;
                    this.inputBuffer = '';
                } else {
                    this.terminal.write('\r\n');
                    this.writePrompt();
                }
                return;
            }
            if (key === '\x7f') {
                if (this.inputBuffer.length > 0) {
                    this.inputBuffer = this.inputBuffer.slice(0, -1);
                    this.terminal.write('\b \b');
                }
                return;
            }
            if (key === '\x1b[A' && this.historyIndex > 0) {
                this.historyIndex--;
                this.restoreHistory();
                return;
            }
            if (key === '\x1b[B') {
                if (this.historyIndex < this.history.length - 1) {
                    this.historyIndex++;
                    this.restoreHistory();
                } else if (this.historyIndex === this.history.length - 1) {
                    this.historyIndex++;
                    this.clearInput();
                }
                return;
            }
            if (key.length === 1 && key.charCodeAt(0) >= 32 && key.charCodeAt(0) < 127) {
                this.inputBuffer += key;
                this.terminal.write(key);
            }
        },

        insertText(text) {
            const normalized = String(text).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            for (const char of normalized) {
                if (char === '\n') continue;
                const code = char.charCodeAt(0);
                if (code >= 32 && code < 127) {
                    this.inputBuffer += char;
                    this.terminal.write(char);
                }
            }
        },

        restoreHistory() {
            this.clearInput();
            if (this.historyIndex < this.history.length) {
                this.inputBuffer = this.history[this.historyIndex];
                this.terminal.write(this.inputBuffer);
            }
        },

        clearInput() {
            for (let i = 0; i < this.inputBuffer.length; i++) {
                this.terminal.write('\b \b');
            }
            this.inputBuffer = '';
        },

        normalizeCommand(command) {
            return String(command)
                .trim()
                .replace(/\s*\\\s*$/g, '')
                .replace(/\s*(&&|\|\||;|\|)\s*$/g, '');
        },

        isLongRunningCommand(command) {
            if (/\b(node|npm|npx|yarn|pnpm|composer|php|artisan)\b[^\n]*\b(-v|--version|version)\b/i.test(command)) {
                return false;
            }
            return /\b(artisan\s+\S+|composer\s+(install|update|require|create-project)|npm\s+(install|ci|run|build|start)|yarn\s+(install|build|start)|pnpm\s+(install|run|build)|pecl\s+install|migrate(:\w+)?|db:seed|db:wipe)\b/i.test(command);
        },

        startCommandProgress(command) {
            this.stopCommandProgress();
            this.commandBusy = true;
            if (!this.isLongRunningCommand(command)) return;
            this.terminal.write(`\x1b[33m▶ Running:\x1b[0m ${command}\r\n`);
            this.terminal.write('\x1b[90m   Please wait — output appears when the command finishes.\x1b[0m\r\n');
            let elapsedSeconds = 0;
            this.commandProgressTimer = setInterval(() => {
                elapsedSeconds += 5;
                this.terminal.write(`\x1b[90m   … still running (${elapsedSeconds}s)\x1b[0m\r\n`);
            }, 5000);
        },

        stopCommandProgress() {
            if (this.commandProgressTimer) {
                clearInterval(this.commandProgressTimer);
                this.commandProgressTimer = null;
            }
            this.commandBusy = false;
        },

        trackSessionExpiry(expiresAt) {
            if (!expiresAt) return;
            this.expiresAtIso = expiresAt;
            if (this.expiryUpdateInterval) clearInterval(this.expiryUpdateInterval);
            this.updateExpiryDisplay(expiresAt);
            this.expiryUpdateInterval = setInterval(() => this.updateExpiryDisplay(expiresAt), 30000);
        },

        async extendSession() {
            if (!this.sessionToken) return;
            try {
                const response = await fetch(`/my/services/${SERVICE_ID}/terminal/extend`, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                    body: JSON.stringify({ session_token: this.sessionToken }),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                if (parseError || !response.ok) {
                    this.terminal.write('\r\n❌ ' + ((data && data.error) || 'Could not extend session') + '\r\n');
                    if (this.mode === 'http') this.writePrompt();
                    return;
                }
                this.trackSessionExpiry(data.expires_at);
                this.persistActiveTab();
                this.terminal.write('\r\n\x1b[32m✓ Session extended\x1b[0m\r\n');
                if (this.mode === 'http') this.writePrompt();
            } catch (e) {
                this.terminal.write('\r\n❌ ' + e.message + '\r\n');
            }
        },

        async recreateHttpSession() {
            const response = await fetch(`/my/services/${SERVICE_ID}/terminal`, {
                method: 'POST',
                headers: this.csrfHeaders(),
            });
            const { data, parseError } = await this.safeJsonResponse(response);
            if (parseError || !response.ok || !data?.session_token) {
                throw new Error((data && data.error) || `Failed to refresh terminal session (HTTP ${response.status})`);
            }
            this.sessionToken = data.session_token;
            this.cwd = data.cwd || this.cwd || '/app';
            this.shellUser = data.shell_user || this.shellUser;
            this.containerName = data.container_name || this.containerName;
            this.mode = 'http';
            this.connected = true;
            this.connectionState = 'http';
            this.trackSessionExpiry(data.expires_at);
            this.persistActiveTab();
            return data;
        },

        async sendCommand(command, options = {}) {
            const allowRetry = options.allowRetry !== false;
            command = this.normalizeCommand(command);
            this.terminal.write('\r\n');

            if (!command) {
                this.writePrompt();
                return;
            }
            if (this.commandBusy) {
                this.terminal.write('\x1b[33m⚠ Another command is still running. Wait for it to finish.\x1b[0m\r\n');
                this.writePrompt();
                return;
            }
            if (!this.sessionToken) {
                this.terminal.write('❌ No active session\r\n');
                this.writePrompt();
                return;
            }

            this.startCommandProgress(command);
            let skipFinalPrompt = false;

            try {
                const response = await fetch(`/my/services/${SERVICE_ID}/terminal/execute`, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                    body: JSON.stringify({ session_token: this.sessionToken, command }),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                const formatOutput = (text) => (text || '').replace(/\r?\n/g, '\r\n');
                const sessionExpired = response.status === 401
                    || (data && data.code === 'session_expired')
                    || (data && typeof data.error === 'string' && /session expired/i.test(data.error));

                if (sessionExpired && allowRetry) {
                    this.stopCommandProgress();
                    this.connectionState = 'reconnecting';
                    this.terminal.write('\x1b[33mSession expired — reconnecting…\x1b[0m\r\n');
                    try {
                        await this.recreateHttpSession();
                        this.terminal.write('\x1b[32m✓ Reconnected. Retrying command…\x1b[0m\r\n');
                        skipFinalPrompt = true;
                        await this.sendCommand(command, { allowRetry: false });
                        return;
                    } catch (reconnectError) {
                        this.terminal.write('❌ ' + reconnectError.message + '\r\n');
                        this.connected = false;
                        this.connectionState = 'expired';
                    }
                } else if (parseError || !response.ok) {
                    this.terminal.write('❌ ' + ((data && data.error) || `Command failed (HTTP ${response.status})`) + '\r\n');
                    if (data?.block_hint) {
                        this.terminal.write('\x1b[90m  ' + data.block_hint + '\x1b[0m\r\n');
                    }
                    if (response.status === 404 || response.status === 401) {
                        this.connected = false;
                        this.connectionState = 'expired';
                    }
                } else if (data.blocked) {
                    this.terminal.write('\x1b[31m' + formatOutput(data.output) + '\x1b[0m\r\n');
                    if (data.block_hint) {
                        this.terminal.write('\x1b[90m  Tip: ' + data.block_hint + '\x1b[0m\r\n');
                    }
                } else {
                    if (data.output) {
                        this.terminal.write(formatOutput(data.output) + '\r\n');
                    } else if (this.isLongRunningCommand(command)) {
                        this.terminal.write('\x1b[90m(command completed with no output)\x1b[0m\r\n');
                    }
                    this.cwd = data.cwd || this.cwd;
                    this.commandCount++;
                    if (data.expires_at) this.trackSessionExpiry(data.expires_at);
                    this.persistActiveTab();
                }
            } catch (error) {
                this.terminal.write('❌ Error: ' + error.message + '\r\n');
            } finally {
                this.stopCommandProgress();
            }

            if (!skipFinalPrompt) this.writePrompt();
        },

        writePrompt() {
            this.terminal.write(`\x1b[32m${this.shellUser}@${this.containerName}\x1b[0m:\x1b[34m${this.cwd}\x1b[0m$ `);
        },

        sendResize(cols, rows) {
            if (!this.ws || this.ws.readyState !== WebSocket.OPEN || this.mode !== 'pty') return;
            this.ws.send(JSON.stringify({
                type: 'resize',
                cols: cols || this.terminal.cols,
                rows: rows || this.terminal.rows,
            }));
        },

        async closeAllTabs() {
            this.intentionalClose = true;
            clearTimeout(this.reconnectTimer);
            this.stopKeepalive();
            if (this.ws) {
                this.ws.close();
                this.ws = null;
            }
            for (const tab of this.tabs) {
                if (!tab.sessionToken) continue;
                try {
                    await fetch(`/my/services/${SERVICE_ID}/terminal`, {
                        method: 'DELETE',
                        headers: this.csrfHeaders(),
                        body: JSON.stringify({ session_token: tab.sessionToken }),
                    });
                } catch (e) {}
            }
            await this.closeTerminalUi();
        },

        async closeTerminalUi() {
            this.connected = false;
            this.mode = null;
            this.sessionToken = null;
            this.inputBuffer = '';
            this.tabs = [];
            this.activeTabIndex = 0;
            this.connectionState = 'idle';
            this.stopCommandProgress();
            this.stopKeepalive();
            this.terminalVisible = false;
            this.fullscreen = false;
            this.searchOpen = false;
            this.showShortcuts = false;
            if (this.expiryUpdateInterval) clearInterval(this.expiryUpdateInterval);
            if (this.terminal) {
                this.terminal.write('\r\n\r\n✓ Terminal closed\r\n');
            }
        },

        updateExpiryDisplay(expiresAt) {
            const expiryDate = new Date(expiresAt);
            const diffMins = Math.floor((expiryDate - new Date()) / 60000);
            if (diffMins < 0) {
                this.sessionExpires = 'Expired';
                this.connectionState = 'expired';
            } else if (diffMins < 60) {
                this.sessionExpires = `Expires in ${diffMins}m`;
            } else {
                this.sessionExpires = `Expires in ${Math.floor(diffMins / 60)}h ${diffMins % 60}m`;
            }
        },

        async safeJsonResponse(response) {
            const text = await response.text();
            if (!text) return { data: null, parseError: null };
            try {
                return { data: JSON.parse(text), parseError: null };
            } catch (error) {
                return { data: null, parseError: error };
            }
        },
    };
}
</script>
@endpush

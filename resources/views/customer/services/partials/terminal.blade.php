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
            x-ref="panesHost"
            class="relative text-sm font-mono text-slate-100 flex-1"
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
        terminalVisible: false,
        sessionStarting: false,
        connected: false,
        connectionState: 'idle',
        mode: null,
        cwd: '/app',
        shellUser: 'app',
        containerName: CONTAINER_NAME,
        commandCount: 0,
        commandBusy: false,
        sessionExpires: null,
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
        websocketEnabled: true,
        expiryUpdateInterval: null,

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

        activeTab() {
            return this.tabs[this.activeTabIndex] || null;
        },

        statusLabel() {
            switch (this.connectionState) {
                case 'connecting': return 'Connecting…';
                case 'live': return 'Live PTY';
                case 'http': return 'HTTP fallback (one command at a time)';
                case 'reconnecting': return `Reconnecting… (${this.activeTab()?.reconnectAttempts || 0})`;
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

        syncUiFromTab(tab) {
            if (!tab) {
                this.connected = false;
                this.connectionState = 'idle';
                this.mode = null;
                this.cwd = '/app';
                this.commandCount = 0;
                this.commandBusy = false;
                this.sessionExpires = null;
                return;
            }

            this.connected = !!tab.connected;
            this.connectionState = tab.connectionState || 'disconnected';
            this.mode = tab.mode;
            this.cwd = tab.cwd || '/app';
            this.shellUser = tab.shellUser || 'app';
            this.containerName = tab.containerName || CONTAINER_NAME;
            this.commandCount = tab.commandCount || 0;
            this.commandBusy = !!tab.commandBusy;
            this.trackSessionExpiry(tab.expiresAtIso);
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
            await this.$nextTick();
            await this.createTabSession();
        },

        async addTab() {
            if (this.tabs.length >= this.maxTabs) {
                const tab = this.activeTab();
                tab?.terminal?.write('\r\n\x1b[33mMaximum number of terminal tabs reached.\x1b[0m\r\n');
                return;
            }
            await this.createTabSession();
        },

        showActivePane() {
            this.tabs.forEach((tab, index) => {
                if (!tab.paneEl) {
                    return;
                }
                const active = index === this.activeTabIndex;
                tab.paneEl.style.visibility = active ? 'visible' : 'hidden';
                tab.paneEl.style.pointerEvents = active ? 'auto' : 'none';
                tab.paneEl.style.zIndex = active ? '2' : '1';
            });
        },

        switchTab(index) {
            if (index === this.activeTabIndex || !this.tabs[index]) {
                return;
            }

            this.activeTabIndex = index;
            this.showActivePane();
            this.syncUiFromTab(this.tabs[index]);
            this.$nextTick(() => {
                this.fitAndResize();
                this.tabs[index].terminal?.focus();
            });
        },

        createPaneTerminal(tab) {
            const host = this.$refs.panesHost;
            if (!host) {
                throw new Error('Terminal host is not ready');
            }

            const paneEl = document.createElement('div');
            paneEl.className = 'absolute inset-0';
            paneEl.dataset.tabId = tab.id;
            paneEl.style.visibility = 'hidden';
            host.appendChild(paneEl);
            tab.paneEl = paneEl;

            const TerminalClass = window.Terminal;
            const FitAddonClass = (window.FitAddon && window.FitAddon.FitAddon) ? window.FitAddon.FitAddon : window.FitAddon;
            const SearchAddonClass = (window.SearchAddon && window.SearchAddon.SearchAddon) ? window.SearchAddon.SearchAddon : window.SearchAddon;
            const WebLinksAddonClass = (window.WebLinksAddon && window.WebLinksAddon.WebLinksAddon) ? window.WebLinksAddon.WebLinksAddon : window.WebLinksAddon;

            const terminal = new TerminalClass({
                theme: THEMES[this.themeName] || THEMES.slate,
                fontFamily: 'Menlo, Monaco, "Cascadia Code", "Ubuntu Mono", Consolas, monospace',
                fontSize: this.fontSize,
                cursorBlink: true,
                convertEol: true,
                scrollback: 5000,
                rightClickSelectsWord: true,
                allowProposedApi: true,
            });

            const fitAddon = new FitAddonClass();
            const searchAddon = new SearchAddonClass();
            terminal.loadAddon(fitAddon);
            terminal.loadAddon(searchAddon);
            if (WebLinksAddonClass) {
                terminal.loadAddon(new WebLinksAddonClass());
            }

            terminal.open(paneEl);
            try { fitAddon.fit(); } catch (e) {}

            terminal.attachCustomKeyEventHandler((event) => {
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

            terminal.onData((data) => {
                // Only the active tab accepts keyboard input into its session.
                if (this.activeTab()?.id !== tab.id) {
                    return;
                }
                if (tab.mode === 'pty' && tab.ws && tab.ws.readyState === WebSocket.OPEN) {
                    tab.ws.send(data);
                    return;
                }
                if (tab.mode === 'http') {
                    this.handleHttpInput(tab, data);
                }
            });

            if (typeof terminal.onResize === 'function') {
                terminal.onResize(({ cols, rows }) => {
                    if (this.activeTab()?.id === tab.id) {
                        this.sendResize(tab, cols, rows);
                    }
                });
            }

            paneEl.addEventListener('click', () => {
                if (this.activeTab()?.id === tab.id) {
                    terminal.focus();
                }
            });

            tab.terminal = terminal;
            tab.fitAddon = fitAddon;
            tab.searchAddon = searchAddon;
        },

        destroyTabResources(tab) {
            if (!tab) {
                return;
            }

            tab.intentionalClose = true;
            clearTimeout(tab.reconnectTimer);
            if (tab.keepaliveInterval) {
                clearInterval(tab.keepaliveInterval);
                tab.keepaliveInterval = null;
            }
            if (tab.commandProgressTimer) {
                clearInterval(tab.commandProgressTimer);
                tab.commandProgressTimer = null;
            }
            if (tab.ws) {
                try { tab.ws.close(); } catch (e) {}
                tab.ws = null;
            }
            try { tab.terminal?.dispose(); } catch (e) {}
            tab.terminal = null;
            tab.fitAddon = null;
            tab.searchAddon = null;
            if (tab.paneEl?.parentNode) {
                tab.paneEl.parentNode.removeChild(tab.paneEl);
            }
            tab.paneEl = null;
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

            this.destroyTabResources(tab);
            this.tabs.splice(index, 1);

            if (this.tabs.length === 0) {
                await this.closeTerminalUi();
                return;
            }

            this.activeTabIndex = Math.min(index, this.tabs.length - 1);
            this.showActivePane();
            this.syncUiFromTab(this.tabs[this.activeTabIndex]);
            this.$nextTick(() => {
                this.fitAndResize();
                this.tabs[this.activeTabIndex].terminal?.focus();
            });
        },

        async createTabSession() {
            this.sessionStarting = true;
            try {
                const response = await fetch(`/my/services/${SERVICE_ID}/terminal`, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                if (parseError || !response.ok) {
                    const active = this.activeTab();
                    if (active?.terminal) {
                        active.connectionState = 'error';
                        this.syncUiFromTab(active);
                        active.terminal.write('\r\n❌ ' + ((data && data.error) || 'Failed to create terminal session') + '\r\n');
                    }
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
                    historyIndex: 0,
                    inputBuffer: '',
                    commandBusy: false,
                    commandProgressTimer: null,
                    ws: null,
                    terminal: null,
                    fitAddon: null,
                    searchAddon: null,
                    paneEl: null,
                    keepaliveInterval: null,
                    reconnectAttempts: 0,
                    reconnectTimer: null,
                    intentionalClose: false,
                };

                this.createPaneTerminal(tab);
                this.tabs.push(tab);
                this.activeTabIndex = this.tabs.length - 1;
                this.websocketEnabled = data.websocket_enabled !== false;
                this.showActivePane();
                this.syncUiFromTab(tab);
                this.$nextTick(() => this.fitAndResize());

                try {
                    if (!this.websocketEnabled) {
                        throw new Error('WebSocket disabled');
                    }
                    await this.connectWebSocket(tab);
                    tab.mode = 'pty';
                    tab.connected = true;
                    tab.connectionState = 'live';
                    tab.reconnectAttempts = 0;
                    tab.terminal.write('✓ ' + (data.welcome_message || 'Connected.') + '\r\n');
                    this.startKeepalive(tab);
                } catch (error) {
                    this.enableHttpFallback(tab, data);
                }

                this.syncUiFromTab(tab);
                tab.terminal.focus();
            } catch (error) {
                const active = this.activeTab();
                if (active) {
                    active.connectionState = 'error';
                    this.syncUiFromTab(active);
                    active.terminal?.write('\r\n❌ Error: ' + error.message + '\r\n');
                }
            } finally {
                this.sessionStarting = false;
            }
        },

        buildWebSocketUrl(tab) {
            const token = encodeURIComponent(tab.sessionToken);
            if (tab.websocketUrl) {
                return `${tab.websocketUrl}?token=${token}`;
            }
            const scheme = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const path = (tab.websocketPath || '/container-terminal').startsWith('/')
                ? (tab.websocketPath || '/container-terminal')
                : `/${tab.websocketPath}`;
            return `${scheme}//${window.location.host}${path}?token=${token}`;
        },

        connectWebSocket(tab) {
            return new Promise((resolve, reject) => {
                if (tab.ws) {
                    tab.intentionalClose = true;
                    tab.ws.close();
                    tab.ws = null;
                    tab.intentionalClose = false;
                }

                tab.ws = new WebSocket(this.buildWebSocketUrl(tab));
                let settled = false;

                tab.ws.onopen = () => {
                    settled = true;
                    this.sendResize(tab);
                    resolve();
                };

                tab.ws.onmessage = (event) => {
                    // Always write into this tab's buffer, even when inactive.
                    tab.terminal?.write(event.data);
                };

                tab.ws.onerror = () => {
                    if (!settled) {
                        settled = true;
                        reject(new Error('WebSocket connection failed'));
                    }
                };

                tab.ws.onclose = () => {
                    if (tab.intentionalClose) {
                        return;
                    }
                    if (tab.mode === 'pty') {
                        tab.connected = false;
                        tab.connectionState = 'disconnected';
                        if (this.activeTab()?.id === tab.id) {
                            this.syncUiFromTab(tab);
                        }
                        this.schedulePtyReconnect(tab);
                    }
                };
            });
        },

        schedulePtyReconnect(tab) {
            if (tab.intentionalClose || !this.terminalVisible || !tab.sessionToken) {
                return;
            }
            if (tab.reconnectAttempts >= 8) {
                tab.connectionState = 'error';
                tab.terminal?.write('\r\n\x1b[31m✗ Could not reconnect PTY. Switching to HTTP fallback.\x1b[0m\r\n');
                this.enableHttpFallback(tab, { welcome_message: 'HTTP fallback after reconnect failure.' });
                if (this.activeTab()?.id === tab.id) {
                    this.syncUiFromTab(tab);
                }
                return;
            }

            tab.reconnectAttempts += 1;
            tab.connectionState = 'reconnecting';
            if (this.activeTab()?.id === tab.id) {
                this.syncUiFromTab(tab);
            }

            const delay = Math.min(10000, 500 * Math.pow(2, tab.reconnectAttempts - 1));
            clearTimeout(tab.reconnectTimer);
            tab.reconnectTimer = setTimeout(async () => {
                try {
                    await this.connectWebSocket(tab);
                    tab.mode = 'pty';
                    tab.connected = true;
                    tab.connectionState = 'live';
                    tab.reconnectAttempts = 0;
                    this.startKeepalive(tab);
                    tab.terminal?.write('\r\n\x1b[32m✓ Reconnected to PTY\x1b[0m\r\n');
                    if (this.activeTab()?.id === tab.id) {
                        this.syncUiFromTab(tab);
                    }
                } catch (e) {
                    this.schedulePtyReconnect(tab);
                }
            }, delay);
        },

        startKeepalive(tab) {
            if (tab.keepaliveInterval) {
                clearInterval(tab.keepaliveInterval);
            }
            tab.keepaliveInterval = setInterval(() => {
                if (tab.mode === 'pty' && tab.ws && tab.ws.readyState === WebSocket.OPEN) {
                    tab.ws.send(JSON.stringify({ type: 'ping' }));
                }
            }, 120000);
        },

        enableHttpFallback(tab, data) {
            if (tab.ws) {
                tab.intentionalClose = true;
                tab.ws.close();
                tab.ws = null;
                tab.intentionalClose = false;
            }
            if (tab.keepaliveInterval) {
                clearInterval(tab.keepaliveInterval);
                tab.keepaliveInterval = null;
            }

            tab.mode = 'http';
            tab.connected = true;
            tab.connectionState = 'http';
            tab.terminal.write('\x1b[33m⚠ Interactive WebSocket unavailable. Using HTTP command mode (one line at a time).\x1b[0m\r\n');
            tab.terminal.write('\x1b[90m  Long commands (artisan, composer, npm) show progress while running.\x1b[0m\r\n');
            if (TEMPLATE_SLUG === 'laravel') {
                tab.terminal.write('  Tip: use Overview → Clear /app if Initialize Laravel is blocked by leftover files.\r\n');
            } else if (TEMPLATE_SLUG === 'nodejs') {
                tab.terminal.write('  Tip: for Node apps, prefer Git → Pull with Force clean rebuild instead of manual npm run build.\r\n');
            }
            if (data?.welcome_message) {
                tab.terminal.write('✓ ' + data.welcome_message + '\r\n');
            }
            this.writePrompt(tab);
            if (this.activeTab()?.id === tab.id) {
                this.syncUiFromTab(tab);
            }
        },

        applyTheme() {
            const theme = THEMES[this.themeName] || THEMES.slate;
            this.tabs.forEach((tab) => {
                if (tab.terminal) {
                    tab.terminal.options.theme = theme;
                }
            });
        },

        applyFontSize() {
            this.tabs.forEach((tab) => {
                if (tab.terminal) {
                    tab.terminal.options.fontSize = this.fontSize;
                }
            });
            this.fitAndResize();
        },

        fitAndResize() {
            const tab = this.activeTab();
            if (!tab?.fitAddon || !tab.terminal) {
                return;
            }
            try {
                tab.fitAddon.fit();
                this.sendResize(tab);
            } catch (e) {}
        },

        toggleFullscreen() {
            this.fullscreen = !this.fullscreen;
            this.$nextTick(() => {
                this.fitAndResize();
                this.activeTab()?.terminal?.focus();
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
            const tab = this.activeTab();
            if (!tab?.searchAddon || !this.searchQuery) return;
            tab.searchAddon.findNext(this.searchQuery, { caseSensitive: false });
        },

        findPrevious() {
            const tab = this.activeTab();
            if (!tab?.searchAddon || !this.searchQuery) return;
            tab.searchAddon.findPrevious(this.searchQuery, { caseSensitive: false });
        },

        clearSearch() {
            try { this.activeTab()?.searchAddon?.clearDecorations(); } catch (e) {}
        },

        async copySelection() {
            const tab = this.activeTab();
            if (!tab?.terminal) return;
            const selection = tab.terminal.getSelection();
            if (!selection) {
                tab.terminal.write('\r\n\x1b[90m(no selection to copy)\x1b[0m\r\n');
                if (tab.mode === 'http') this.writePrompt(tab);
                return;
            }
            try {
                await navigator.clipboard.writeText(selection);
            } catch (e) {
                tab.terminal.write('\r\n⚠ Could not write clipboard.\r\n');
            }
            tab.terminal.focus();
        },

        async pasteFromClipboard() {
            const tab = this.activeTab();
            if (!tab?.connected || !tab.terminal) return;
            try {
                const text = await navigator.clipboard.readText();
                if (!text) return;
                if (tab.mode === 'pty' && tab.ws && tab.ws.readyState === WebSocket.OPEN) {
                    tab.ws.send(text);
                } else if (tab.mode === 'http') {
                    this.insertText(tab, text);
                }
                tab.terminal.focus();
            } catch (error) {
                tab.terminal.write('\r\n⚠ Could not read clipboard. Use Ctrl+Shift+V.\r\n');
                if (tab.mode === 'http') this.writePrompt(tab);
            }
        },

        onContextMenu() {
            this.pasteFromClipboard();
        },

        handleHttpInput(tab, data) {
            if (!tab.connected || tab.mode !== 'http') return;

            if (data.startsWith('\x1b[200~')) {
                this.insertText(tab, data.replace(/^\x1b\[200~/, '').replace(/\x1b\[201~$/, ''));
                return;
            }
            if (data.length > 1) {
                this.insertText(tab, data);
                return;
            }

            const key = data;
            if (key === '\x03') {
                tab.inputBuffer = '';
                tab.terminal.write('^C\r\n');
                this.writePrompt(tab);
                return;
            }
            if (key === '\x0c') {
                tab.terminal.clear();
                this.writePrompt(tab);
                return;
            }
            if (key === '\r' || key === '\n') {
                if (tab.inputBuffer.trim()) {
                    this.sendCommand(tab, tab.inputBuffer);
                    tab.history.push(tab.inputBuffer);
                    tab.historyIndex = tab.history.length;
                    tab.inputBuffer = '';
                } else {
                    tab.terminal.write('\r\n');
                    this.writePrompt(tab);
                }
                return;
            }
            if (key === '\x7f') {
                if (tab.inputBuffer.length > 0) {
                    tab.inputBuffer = tab.inputBuffer.slice(0, -1);
                    tab.terminal.write('\b \b');
                }
                return;
            }
            if (key === '\x1b[A' && tab.historyIndex > 0) {
                tab.historyIndex--;
                this.restoreHistory(tab);
                return;
            }
            if (key === '\x1b[B') {
                if (tab.historyIndex < tab.history.length - 1) {
                    tab.historyIndex++;
                    this.restoreHistory(tab);
                } else if (tab.historyIndex === tab.history.length - 1) {
                    tab.historyIndex++;
                    this.clearInput(tab);
                }
                return;
            }
            if (key.length === 1 && key.charCodeAt(0) >= 32 && key.charCodeAt(0) < 127) {
                tab.inputBuffer += key;
                tab.terminal.write(key);
            }
        },

        insertText(tab, text) {
            const normalized = String(text).replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            for (const char of normalized) {
                if (char === '\n') continue;
                const code = char.charCodeAt(0);
                if (code >= 32 && code < 127) {
                    tab.inputBuffer += char;
                    tab.terminal.write(char);
                }
            }
        },

        restoreHistory(tab) {
            this.clearInput(tab);
            if (tab.historyIndex < tab.history.length) {
                tab.inputBuffer = tab.history[tab.historyIndex];
                tab.terminal.write(tab.inputBuffer);
            }
        },

        clearInput(tab) {
            for (let i = 0; i < tab.inputBuffer.length; i++) {
                tab.terminal.write('\b \b');
            }
            tab.inputBuffer = '';
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

        startCommandProgress(tab, command) {
            this.stopCommandProgress(tab);
            tab.commandBusy = true;
            if (this.activeTab()?.id === tab.id) {
                this.commandBusy = true;
            }
            if (!this.isLongRunningCommand(command)) return;
            tab.terminal.write(`\x1b[33m▶ Running:\x1b[0m ${command}\r\n`);
            tab.terminal.write('\x1b[90m   Please wait — output appears when the command finishes.\x1b[0m\r\n');
            let elapsedSeconds = 0;
            tab.commandProgressTimer = setInterval(() => {
                elapsedSeconds += 5;
                tab.terminal.write(`\x1b[90m   … still running (${elapsedSeconds}s)\x1b[0m\r\n`);
            }, 5000);
        },

        stopCommandProgress(tab) {
            if (tab.commandProgressTimer) {
                clearInterval(tab.commandProgressTimer);
                tab.commandProgressTimer = null;
            }
            tab.commandBusy = false;
            if (this.activeTab()?.id === tab.id) {
                this.commandBusy = false;
            }
        },

        trackSessionExpiry(expiresAt) {
            if (!expiresAt) {
                this.sessionExpires = null;
                return;
            }
            if (this.expiryUpdateInterval) clearInterval(this.expiryUpdateInterval);
            this.updateExpiryDisplay(expiresAt);
            this.expiryUpdateInterval = setInterval(() => {
                const tab = this.activeTab();
                if (tab?.expiresAtIso) {
                    this.updateExpiryDisplay(tab.expiresAtIso);
                }
            }, 30000);
        },

        async extendSession() {
            const tab = this.activeTab();
            if (!tab?.sessionToken) return;
            try {
                const response = await fetch(`/my/services/${SERVICE_ID}/terminal/extend`, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                    body: JSON.stringify({ session_token: tab.sessionToken }),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                if (parseError || !response.ok) {
                    tab.terminal.write('\r\n❌ ' + ((data && data.error) || 'Could not extend session') + '\r\n');
                    if (tab.mode === 'http') this.writePrompt(tab);
                    return;
                }
                tab.expiresAtIso = data.expires_at;
                this.trackSessionExpiry(data.expires_at);
                tab.terminal.write('\r\n\x1b[32m✓ Session extended\x1b[0m\r\n');
                if (tab.mode === 'http') this.writePrompt(tab);
            } catch (e) {
                tab.terminal.write('\r\n❌ ' + e.message + '\r\n');
            }
        },

        async recreateHttpSession(tab) {
            const response = await fetch(`/my/services/${SERVICE_ID}/terminal`, {
                method: 'POST',
                headers: this.csrfHeaders(),
            });
            const { data, parseError } = await this.safeJsonResponse(response);
            if (parseError || !response.ok || !data?.session_token) {
                throw new Error((data && data.error) || `Failed to refresh terminal session (HTTP ${response.status})`);
            }
            tab.sessionToken = data.session_token;
            tab.cwd = data.cwd || tab.cwd || '/app';
            tab.shellUser = data.shell_user || tab.shellUser;
            tab.containerName = data.container_name || tab.containerName;
            tab.mode = 'http';
            tab.connected = true;
            tab.connectionState = 'http';
            tab.expiresAtIso = data.expires_at;
            if (this.activeTab()?.id === tab.id) {
                this.syncUiFromTab(tab);
            }
            return data;
        },

        async sendCommand(tab, command, options = {}) {
            const allowRetry = options.allowRetry !== false;
            command = this.normalizeCommand(command);
            tab.terminal.write('\r\n');

            if (!command) {
                this.writePrompt(tab);
                return;
            }
            if (tab.commandBusy) {
                tab.terminal.write('\x1b[33m⚠ Another command is still running. Wait for it to finish.\x1b[0m\r\n');
                this.writePrompt(tab);
                return;
            }
            if (!tab.sessionToken) {
                tab.terminal.write('❌ No active session\r\n');
                this.writePrompt(tab);
                return;
            }

            this.startCommandProgress(tab, command);
            let skipFinalPrompt = false;

            try {
                const response = await fetch(`/my/services/${SERVICE_ID}/terminal/execute`, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                    body: JSON.stringify({ session_token: tab.sessionToken, command }),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                const formatOutput = (text) => (text || '').replace(/\r?\n/g, '\r\n');
                const sessionExpired = response.status === 401
                    || (data && data.code === 'session_expired')
                    || (data && typeof data.error === 'string' && /session expired/i.test(data.error));

                if (sessionExpired && allowRetry) {
                    this.stopCommandProgress(tab);
                    tab.connectionState = 'reconnecting';
                    if (this.activeTab()?.id === tab.id) this.syncUiFromTab(tab);
                    tab.terminal.write('\x1b[33mSession expired — reconnecting…\x1b[0m\r\n');
                    try {
                        await this.recreateHttpSession(tab);
                        tab.terminal.write('\x1b[32m✓ Reconnected. Retrying command…\x1b[0m\r\n');
                        skipFinalPrompt = true;
                        await this.sendCommand(tab, command, { allowRetry: false });
                        return;
                    } catch (reconnectError) {
                        tab.terminal.write('❌ ' + reconnectError.message + '\r\n');
                        tab.connected = false;
                        tab.connectionState = 'expired';
                        if (this.activeTab()?.id === tab.id) this.syncUiFromTab(tab);
                    }
                } else if (parseError || !response.ok) {
                    tab.terminal.write('❌ ' + ((data && data.error) || `Command failed (HTTP ${response.status})`) + '\r\n');
                    if (data?.block_hint) {
                        tab.terminal.write('\x1b[90m  ' + data.block_hint + '\x1b[0m\r\n');
                    }
                    if (response.status === 404 || response.status === 401) {
                        tab.connected = false;
                        tab.connectionState = 'expired';
                        if (this.activeTab()?.id === tab.id) this.syncUiFromTab(tab);
                    }
                } else if (data.blocked) {
                    tab.terminal.write('\x1b[31m' + formatOutput(data.output) + '\x1b[0m\r\n');
                    if (data.block_hint) {
                        tab.terminal.write('\x1b[90m  Tip: ' + data.block_hint + '\x1b[0m\r\n');
                    }
                } else {
                    if (data.output) {
                        tab.terminal.write(formatOutput(data.output) + '\r\n');
                    } else if (this.isLongRunningCommand(command)) {
                        tab.terminal.write('\x1b[90m(command completed with no output)\x1b[0m\r\n');
                    }
                    tab.cwd = data.cwd || tab.cwd;
                    tab.commandCount++;
                    if (data.expires_at) {
                        tab.expiresAtIso = data.expires_at;
                    }
                    if (this.activeTab()?.id === tab.id) {
                        this.syncUiFromTab(tab);
                    }
                }
            } catch (error) {
                tab.terminal.write('❌ Error: ' + error.message + '\r\n');
            } finally {
                this.stopCommandProgress(tab);
            }

            if (!skipFinalPrompt) this.writePrompt(tab);
        },

        writePrompt(tab) {
            tab.terminal.write(`\x1b[32m${tab.shellUser}@${tab.containerName}\x1b[0m:\x1b[34m${tab.cwd}\x1b[0m$ `);
        },

        sendResize(tab, cols, rows) {
            if (!tab?.ws || tab.ws.readyState !== WebSocket.OPEN || tab.mode !== 'pty' || !tab.terminal) return;
            tab.ws.send(JSON.stringify({
                type: 'resize',
                cols: cols || tab.terminal.cols,
                rows: rows || tab.terminal.rows,
            }));
        },

        async closeAllTabs() {
            const tabs = [...this.tabs];
            for (const tab of tabs) {
                tab.intentionalClose = true;
                if (tab.sessionToken) {
                    try {
                        await fetch(`/my/services/${SERVICE_ID}/terminal`, {
                            method: 'DELETE',
                            headers: this.csrfHeaders(),
                            body: JSON.stringify({ session_token: tab.sessionToken }),
                        });
                    } catch (e) {}
                }
                this.destroyTabResources(tab);
            }
            await this.closeTerminalUi();
        },

        async closeTerminalUi() {
            this.tabs = [];
            this.activeTabIndex = 0;
            this.connected = false;
            this.mode = null;
            this.connectionState = 'idle';
            this.commandBusy = false;
            this.terminalVisible = false;
            this.fullscreen = false;
            this.searchOpen = false;
            this.showShortcuts = false;
            this.sessionExpires = null;
            if (this.expiryUpdateInterval) clearInterval(this.expiryUpdateInterval);
            if (this.$refs.panesHost) {
                this.$refs.panesHost.innerHTML = '';
            }
        },

        updateExpiryDisplay(expiresAt) {
            const expiryDate = new Date(expiresAt);
            const diffMins = Math.floor((expiryDate - new Date()) / 60000);
            if (diffMins < 0) {
                this.sessionExpires = 'Expired';
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

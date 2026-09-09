@php
    $terminalTemplate = $service->effectiveContainerTemplate() ?? $service->product?->containerTemplate;
    $terminalTemplateSlug = $terminalTemplate?->slug ?? '';
    $terminalContainerName = $deployment->container_name ?? 'container';
    $maxTerminalTabs = max(1, (int) config('terminal.session.max_per_user_service', 3));
    $terminalDefaultCwd = app(\App\Services\Terminal\ContainerTerminalService::class)
        ->resolveAppRootFromTemplate($terminalTemplate);
    $containerRunning = $deployment?->isRunning() ?? false;
    $terminalHasSplitWorkloads = data_get($service->service_meta, 'node_workloads.topology') === 'split_web_api';
@endphp
<div
    x-data="containerTerminal()"
    x-init="init()"
    @container-tab-shown.window="onConsoleTabShown($event.detail)"
    class="container-classic-terminal"
    :class="fullscreen ? 'fixed inset-0 z-[90] p-3 sm:p-6 bg-black/70' : ''"
>
    <div
        class="flex flex-col min-h-0 overflow-hidden bg-[#0b1209] text-[#41ff6b] shadow-[inset_0_0_0_1px_rgba(65,255,107,0.16)]"
        :class="fullscreen ? 'h-full rounded-xl' : 'rounded-none md:rounded-b-2xl'"
    >
        <div class="flex items-center gap-3 px-3 py-2 bg-[#071109] border-b border-[#1d3a22]">
            <div class="flex items-center gap-1.5 shrink-0" aria-hidden="true">
                <span class="w-2.5 h-2.5 rounded-full bg-[#ff5f56]"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-[#ffbd2e]"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-[#27c93f]"></span>
            </div>
            <p class="min-w-0 flex-1 font-mono text-xs text-[#7cff9a]/85 truncate" x-text="titleBarLabel()"></p>
            <div class="flex items-center gap-1 shrink-0">
                @if ($terminalHasSplitWorkloads)
                    <select x-model="selectedWorkload" class="rounded border border-[#1d3a22] bg-[#08140b] text-[#7cff9a] text-[11px] py-1 pl-2 pr-6">
                        <option value="backend">Backend</option>
                        <option value="frontend">Frontend</option>
                        <option value="edge">Edge</option>
                    </select>
                @endif
                <button type="button" @click="searchOpen = !searchOpen; $nextTick(() => { if (searchOpen) $refs.searchInput?.focus(); })" class="px-2 py-1 rounded text-[11px] font-medium text-[#7cff9a]/80 hover:bg-[#14301a]" title="Find (Ctrl/Cmd+Shift+F)">Find</button>
                <button type="button" @click="addTab()" :disabled="!containerRunning || tabs.length >= maxTabs || sessionStarting" class="px-2 py-1 rounded text-[11px] font-medium text-[#7cff9a]/80 hover:bg-[#14301a] disabled:opacity-40" title="New tab">+</button>
                <button type="button" @click="toggleFullscreen()" class="px-2 py-1 rounded text-[11px] font-medium text-[#7cff9a]/80 hover:bg-[#14301a]" x-text="fullscreen ? 'Exit' : 'Full screen'"></button>
            </div>
        </div>

        <div class="flex items-center gap-1 px-2 bg-[#08140b] border-b border-[#1d3a22] overflow-x-auto" x-show="tabs.length > 1">
            <template x-for="(tab, index) in tabs" :key="tab.id">
                <button
                    type="button"
                    @click="switchTab(index)"
                    class="group inline-flex items-center gap-2 px-3 py-1.5 text-xs font-mono whitespace-nowrap"
                    :class="activeTabIndex === index ? 'text-[#7cff9a] border-b-2 border-[#41ff6b]' : 'text-[#4a8f5a] hover:text-[#7cff9a] border-b-2 border-transparent'"
                >
                    <span x-text="tab.label"></span>
                    <span
                        class="w-1.5 h-1.5 rounded-full"
                        :class="{
                            'bg-[#41ff6b]': tab.connectionState === 'live',
                            'bg-amber-400': ['connecting', 'reconnecting', 'http'].includes(tab.connectionState),
                            'bg-red-400': ['disconnected', 'expired', 'error'].includes(tab.connectionState),
                        }"
                    ></span>
                    <span @click.stop="closeTab(index)" class="opacity-60 hover:opacity-100 hover:text-red-300" title="Close tab">×</span>
                </button>
            </template>
        </div>

        <div x-show="searchOpen" class="flex items-center gap-2 px-3 py-2 bg-[#08140b] border-b border-[#1d3a22]">
            <input
                x-ref="searchInput"
                type="text"
                x-model="searchQuery"
                @keydown.enter.prevent="findNext()"
                @keydown.escape.prevent="searchOpen = false"
                placeholder="Find in terminal…"
                class="flex-1 rounded border-0 bg-[#0b1209] text-[#41ff6b] font-mono text-xs px-2 py-1.5 focus:ring-1 focus:ring-[#41ff6b]/50"
            >
            <button type="button" @click="findPrevious()" class="text-xs text-[#7cff9a]/80 px-2 py-1">Prev</button>
            <button type="button" @click="findNext()" class="text-xs text-[#7cff9a]/80 px-2 py-1">Next</button>
            <button type="button" @click="searchOpen = false; clearSearch()" class="text-xs text-[#4a8f5a] px-2 py-1">Esc</button>
        </div>

        <div
            class="relative font-mono overflow-hidden bg-[#0b1209]"
            style="height: 520px;"
            :style="fullscreen ? 'min-height: 0; height: 100%;' : 'height: 520px;'"
        >
            <div
                x-ref="panesHost"
                class="absolute inset-0 overflow-hidden"
                @contextmenu.prevent="onContextMenu($event)"
            ></div>
            <div
                x-show="!containerRunning"
                class="absolute inset-0 z-20 flex items-center justify-center p-6 bg-[#0b1209]"
            >
                <p class="text-sm text-[#7cff9a]/90 text-center max-w-md">
                    Start the app from Overview, then come back to Terminal.
                </p>
            </div>
            <div
                x-show="containerRunning && sessionStarting && tabs.length === 0"
                class="absolute inset-0 z-10 flex items-center justify-center bg-[#0b1209]/90"
            >
                <div class="inline-flex items-center gap-2 text-[#7cff9a] text-sm font-mono">
                    <span class="w-2 h-2 rounded-full bg-[#41ff6b] animate-pulse"></span>
                    <span>Connecting…</span>
                </div>
            </div>
            <div
                x-show="containerRunning && !sessionStarting && tabs.length === 0 && hasAttemptedConnect"
                class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-3 p-6 bg-[#0b1209]"
            >
                <p class="text-sm text-[#7cff9a]/90 font-mono text-center max-w-md" x-text="lastError || (connectionState === 'error' ? 'Could not open a session.' : 'Session closed.')"></p>
                <button type="button" @click="openTerminal()" class="px-3 py-1.5 rounded border border-[#41ff6b]/40 text-xs font-mono text-[#41ff6b] hover:bg-[#14301a]">
                    Reconnect
                </button>
            </div>
        </div>

        <div class="bg-[#071109] border-t border-[#1d3a22] px-3 py-1.5 flex flex-wrap items-center justify-between gap-2 text-[11px] font-mono text-[#4a8f5a]">
            <div class="flex flex-wrap items-center gap-2 min-w-0">
                <span class="inline-block w-1.5 h-1.5 rounded-full"
                      :class="{
                          'bg-[#41ff6b] animate-pulse': connectionState === 'live',
                          'bg-amber-400 animate-pulse': ['connecting', 'reconnecting', 'http'].includes(connectionState),
                          'bg-red-500': ['disconnected', 'expired', 'error', 'idle'].includes(connectionState),
                      }"></span>
                <span x-text="statusLabel()"></span>
                <span x-show="mode === 'http' && commandBusy" class="text-amber-400">Running command…</span>
            </div>
            <div class="flex items-center gap-3 text-right">
                <span x-show="sessionExpires" x-text="sessionExpires"></span>
            </div>
        </div>
    </div>
</div>

@push('scripts')
{{-- Load outside Alpine x-if so browsers actually execute these scripts. --}}
<style>
    .container-classic-terminal .xterm {
        padding: 10px 14px;
        height: 100%;
    }
    .container-classic-terminal .xterm-viewport,
    .container-classic-terminal .xterm-screen {
        background-color: #0b1209 !important;
    }
</style>
<link rel="stylesheet" href="{{ asset('css/xterm.min.css') }}">
<script src="{{ asset('js/xterm/xterm.js') }}"></script>
<script src="{{ asset('js/xterm/xterm-addon-fit.js') }}"></script>
<script src="{{ asset('js/xterm/xterm-addon-search.js') }}"></script>
<script src="{{ asset('js/xterm/xterm-addon-web-links.js') }}"></script>
<script>
function containerTerminal() {
    const SERVICE_ID = {{ (int) $service->id }};
    const TERMINAL_URL = @js(container_route('terminal.create', $service));
    const TERMINAL_EXTEND_URL = @js(container_route('terminal.extend', $service));
    const TERMINAL_EXECUTE_URL = @js(container_route('terminal.execute', $service));
    const CONTAINER_NAME = @json($terminalContainerName);
    const TEMPLATE_SLUG = @json($terminalTemplateSlug);
    const MAX_TABS = {{ (int) $maxTerminalTabs }};
    const DEFAULT_CWD = @json($terminalDefaultCwd);
    const CONTAINER_RUNNING = @json($containerRunning);
    const HAS_SPLIT_WORKLOADS = @json($terminalHasSplitWorkloads);
    const CLASSIC_THEME = {
        background: '#0b1209',
        foreground: '#41ff6b',
        cursor: '#41ff6b',
        cursorAccent: '#0b1209',
        selectionBackground: '#1d6b32',
        selectionForeground: '#d7ffe0',
        black: '#0b1209',
        red: '#ff5f5f',
        green: '#41ff6b',
        yellow: '#d7ef4a',
        blue: '#6ab0ff',
        magenta: '#d46bff',
        cyan: '#4adede',
        white: '#e8f5e9',
        brightBlack: '#3d5c40',
        brightRed: '#ff8080',
        brightGreen: '#7cff9a',
        brightYellow: '#f0ff7a',
        brightBlue: '#8cc4ff',
        brightMagenta: '#e090ff',
        brightCyan: '#7aeeee',
        brightWhite: '#ffffff',
    };

    return {
        terminalVisible: true,
        containerRunning: CONTAINER_RUNNING,
        selectedWorkload: 'backend',
        sessionStarting: false,
        hasAttemptedConnect: false,
        connected: false,
        connectionState: 'idle',
        mode: null,
        cwd: DEFAULT_CWD,
        shellUser: 'app',
        containerName: CONTAINER_NAME,
        commandCount: 0,
        commandBusy: false,
        sessionExpires: null,
        fullscreen: false,
        fontSize: 14,
        searchOpen: false,
        searchQuery: '',
        showShortcuts: false,
        maxTabs: MAX_TABS,
        tabs: [],
        activeTabIndex: 0,
        tabSeq: 0,
        websocketEnabled: true,
        expiryUpdateInterval: null,
        lastError: '',

        get shellIdentity() {
            if (!this.connected) {
                return '';
            }
            return `${this.shellUser}@${this.containerName}:${this.cwd}`;
        },

        titleBarLabel() {
            if (!this.containerRunning) {
                return `${CONTAINER_NAME} — stopped`;
            }
            if (this.shellIdentity) {
                return this.shellIdentity;
            }
            return `${CONTAINER_NAME} — terminal`;
        },

        init() {
            document.addEventListener('keydown', (event) => this.handleGlobalKeys(event));
            window.addEventListener('resize', () => this.fitAndResize());
            if (this.containerRunning) {
                this.openTerminal();
            }
        },

        onConsoleTabShown(tab) {
            if (tab !== 'terminal') {
                return;
            }
            this.$nextTick(() => {
                this.fitAndResize();
                this.activeTab()?.terminal?.focus();
                if (this.containerRunning && this.tabs.length === 0 && !this.sessionStarting) {
                    this.openTerminal();
                }
            });
        },

        activeTab() {
            return this.tabs[this.activeTabIndex] || null;
        },

        statusLabel() {
            if (!this.containerRunning) {
                return 'App is not running';
            }
            switch (this.connectionState) {
                case 'connecting': return 'Connecting…';
                case 'live': return 'Connected';
                case 'http': return 'Command mode (one line at a time)';
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
                this.cwd = DEFAULT_CWD;
                this.commandCount = 0;
                this.commandBusy = false;
                this.sessionExpires = null;
                return;
            }

            this.connected = !!tab.connected;
            this.connectionState = tab.connectionState || 'disconnected';
            this.mode = tab.mode;
            this.cwd = tab.cwd || DEFAULT_CWD;
            this.shellUser = tab.shellUser || 'app';
            this.containerName = tab.containerName || CONTAINER_NAME;
            this.commandCount = tab.commandCount || 0;
            this.commandBusy = !!tab.commandBusy;
            this.trackSessionExpiry(tab.expiresAtIso);
        },

        async openTerminal() {
            if (!this.containerRunning) {
                return;
            }
            if (this.tabs.length > 0) {
                this.activeTab()?.terminal?.focus();
                return;
            }
            if (this.sessionStarting) {
                return;
            }

            this.hasAttemptedConnect = true;
            this.sessionStarting = true;
            this.terminalVisible = true;
            this.lastError = '';

            try {
                await this.$nextTick();
                await this.waitForPaint();
                await this.ensureXtermReady();

                // panesHost is inside x-show; wait until it has real dimensions.
                await this.waitForPanesHost();
                await this.createTabSession();
            } catch (error) {
                this.sessionStarting = false;
                this.connectionState = 'error';
                this.lastError = 'Failed to start terminal: ' + (error?.message || 'unknown error');
                console.error('Failed to open terminal:', error);
            }
        },

        waitForPaint() {
            return new Promise((resolve) => {
                requestAnimationFrame(() => requestAnimationFrame(resolve));
            });
        },

        async waitForPanesHost(timeoutMs = 2000) {
            const started = Date.now();
            while (Date.now() - started < timeoutMs) {
                const host = this.$refs.panesHost;
                if (host && host.offsetWidth > 0 && host.offsetHeight > 0) {
                    return host;
                }
                await this.waitForPaint();
            }
            if (!this.$refs.panesHost) {
                throw new Error('Terminal panel did not mount');
            }
            return this.$refs.panesHost;
        },

        loadScriptOnce(src) {
            return new Promise((resolve, reject) => {
                const existing = document.querySelector(`script[src="${src}"]`);
                if (existing) {
                    if (existing.dataset.loaded === '1') {
                        resolve();
                        return;
                    }
                    existing.addEventListener('load', () => resolve(), { once: true });
                    existing.addEventListener('error', () => reject(new Error('Failed to load ' + src)), { once: true });
                    return;
                }
                const script = document.createElement('script');
                script.src = src;
                script.async = false;
                script.onload = () => {
                    script.dataset.loaded = '1';
                    resolve();
                };
                script.onerror = () => reject(new Error('Failed to load ' + src));
                document.head.appendChild(script);
            });
        },

        async ensureXtermReady() {
            if (window.Terminal && window.FitAddon) {
                return;
            }

            const base = @json(asset('js/xterm'));
            await this.loadScriptOnce(`${base}/xterm.js`);
            await this.loadScriptOnce(`${base}/xterm-addon-fit.js`);
            await this.loadScriptOnce(`${base}/xterm-addon-search.js`);
            await this.loadScriptOnce(`${base}/xterm-addon-web-links.js`);

            if (!window.Terminal) {
                throw new Error('xterm failed to load');
            }
        },

        async addTab() {
            if (this.tabs.length >= this.maxTabs) {
                const tab = this.activeTab();
                tab?.terminal?.write('\r\n\x1b[33mMaximum number of terminal tabs reached.\x1b[0m\r\n');
                return;
            }
            await this.ensureXtermReady();
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

            const TerminalClass = window.Terminal;
            if (!TerminalClass) {
                throw new Error('xterm is not available');
            }

            const paneEl = document.createElement('div');
            paneEl.className = 'absolute inset-0';
            paneEl.dataset.tabId = tab.id;
            paneEl.style.visibility = 'hidden';
            paneEl.style.overflow = 'hidden';
            host.appendChild(paneEl);
            tab.paneEl = paneEl;

            const FitAddonClass = (window.FitAddon && window.FitAddon.FitAddon) ? window.FitAddon.FitAddon : window.FitAddon;
            const SearchAddonClass = (window.SearchAddon && window.SearchAddon.SearchAddon) ? window.SearchAddon.SearchAddon : window.SearchAddon;
            const WebLinksAddonClass = (window.WebLinksAddon && window.WebLinksAddon.WebLinksAddon) ? window.WebLinksAddon.WebLinksAddon : window.WebLinksAddon;

            if (!FitAddonClass) {
                throw new Error('xterm FitAddon is not available');
            }

            const terminal = new TerminalClass({
                theme: CLASSIC_THEME,
                fontFamily: 'Menlo, Monaco, "Cascadia Mono", "Ubuntu Mono", Consolas, monospace',
                fontSize: this.fontSize,
                cursorBlink: true,
                cursorStyle: 'block',
                convertEol: false,
                scrollback: 5000,
                rightClickSelectsWord: true,
                allowProposedApi: true,
            });

            const fitAddon = new FitAddonClass();
            const searchAddon = SearchAddonClass ? new SearchAddonClass() : null;
            terminal.loadAddon(fitAddon);
            if (searchAddon) {
                terminal.loadAddon(searchAddon);
            }
            if (WebLinksAddonClass) {
                terminal.loadAddon(new WebLinksAddonClass());
            }

            terminal.open(paneEl);

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

            // Fit after the pane is visible and laid out.
            requestAnimationFrame(() => {
                try { fitAddon.fit(); } catch (e) {}
                this.sendResize(tab);
            });
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
                    await fetch(TERMINAL_URL, {
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
                const response = await fetch(TERMINAL_URL, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                    body: JSON.stringify({ workload: this.selectedWorkload }),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                if (parseError || !response.ok) {
                    const message = (data && data.error) || 'Failed to create terminal session';
                    this.connectionState = 'error';
                    this.lastError = message;
                    const active = this.activeTab();
                    if (active?.terminal) {
                        active.connectionState = 'error';
                        this.syncUiFromTab(active);
                        active.terminal.write('\r\n❌ ' + message + '\r\n');
                    }
                    return;
                }

                this.tabSeq += 1;
                const tab = {
                    id: `t${this.tabSeq}`,
                    label: HAS_SPLIT_WORKLOADS ? `${this.selectedWorkload} ${this.tabSeq}` : `Terminal ${this.tabSeq}`,
                    workload: this.selectedWorkload,
                    sessionToken: data.session_token,
                    websocketUrl: data.websocket_url,
                    websocketPath: data.websocket_path || '/container-terminal',
                    mode: null,
                    cwd: data.cwd || DEFAULT_CWD,
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
                await this.$nextTick();
                await this.waitForPaint();
                this.fitAndResize();

                try {
                    if (!this.websocketEnabled) {
                        throw new Error('WebSocket disabled');
                    }
                    await this.connectWebSocket(tab);
                    tab.mode = 'pty';
                    tab.connected = true;
                    tab.connectionState = 'live';
                    tab.reconnectAttempts = 0;
                    if (tab.terminal) {
                        tab.terminal.options.convertEol = false;
                    }
                    this.startKeepalive(tab);
                } catch (error) {
                    this.enableHttpFallback(tab, data);
                }

                this.syncUiFromTab(tab);
                this.fitAndResize();
                tab.terminal.focus();
            } catch (error) {
                const active = this.activeTab();
                if (active) {
                    active.connectionState = 'error';
                    this.syncUiFromTab(active);
                    active.terminal?.write('\r\n❌ Error: ' + error.message + '\r\n');
                } else {
                    throw error;
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
                    if (tab.terminal) {
                        tab.terminal.options.convertEol = false;
                    }
                    this.startKeepalive(tab);
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
                    return;
                }
                if (tab.mode === 'http' && tab.sessionToken) {
                    this.extendSession({ silent: true, tab });
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

            tab.mode = 'http';
            tab.connected = true;
            tab.connectionState = 'http';
            if (tab.terminal) {
                tab.terminal.options.convertEol = true;
            }
            tab.terminal.write('\x1b[33mInteractive session unavailable. Command mode: type a line and press Enter.\x1b[0m\r\n');
            if (TEMPLATE_SLUG === 'laravel') {
                tab.terminal.write('\x1b[90m  php artisan migrate is rewritten with --force automatically.\x1b[0m\r\n');
            }
            this.writePrompt(tab);
            this.startKeepalive(tab);
            if (this.activeTab()?.id === tab.id) {
                this.syncUiFromTab(tab);
            }
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

        async extendSession(options = {}) {
            const silent = !!options.silent;
            const tab = options.tab || this.activeTab();
            if (!tab?.sessionToken) return;
            try {
                const response = await fetch(TERMINAL_EXTEND_URL, {
                    method: 'POST',
                    headers: this.csrfHeaders(),
                    body: JSON.stringify({ session_token: tab.sessionToken }),
                });
                const { data, parseError } = await this.safeJsonResponse(response);
                if (parseError || !response.ok) {
                    if (!silent) {
                        tab.terminal.write('\r\n❌ ' + ((data && data.error) || 'Could not extend session') + '\r\n');
                        if (tab.mode === 'http') this.writePrompt(tab);
                    }
                    return;
                }
                tab.expiresAtIso = data.expires_at;
                this.trackSessionExpiry(data.expires_at);
                if (!silent) {
                    tab.terminal.write('\r\n\x1b[32m✓ Session extended\x1b[0m\r\n');
                    if (tab.mode === 'http') this.writePrompt(tab);
                }
            } catch (e) {
                if (!silent) {
                    tab.terminal.write('\r\n❌ ' + e.message + '\r\n');
                }
            }
        },

        async recreateHttpSession(tab) {
            const response = await fetch(TERMINAL_URL, {
                method: 'POST',
                headers: this.csrfHeaders(),
                    body: JSON.stringify({ workload: tab.workload || 'backend' }),
            });
            const { data, parseError } = await this.safeJsonResponse(response);
            if (parseError || !response.ok || !data?.session_token) {
                throw new Error((data && data.error) || `Failed to refresh terminal session (HTTP ${response.status})`);
            }
            tab.sessionToken = data.session_token;
            tab.cwd = data.cwd || tab.cwd || DEFAULT_CWD;
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
                const response = await fetch(TERMINAL_EXECUTE_URL, {
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
                        await fetch(TERMINAL_URL, {
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
            this.connectionState = this.connectionState === 'error' ? 'error' : 'idle';
            this.commandBusy = false;
            this.terminalVisible = true;
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

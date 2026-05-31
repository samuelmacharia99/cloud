<div x-data="containerTerminal()" class="space-y-4">
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Terminal</h3>
        <div class="flex items-center gap-2">
            <button x-show="terminalVisible" @click="pasteFromClipboard()" type="button" class="px-3 py-1.5 rounded text-sm font-medium transition bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700">
                Paste
            </button>
            <button @click="toggleTerminal()" :class="terminalVisible ? 'bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300' : 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'" class="px-3 py-1.5 rounded text-sm font-medium transition">
                <span x-text="terminalVisible ? 'Close Terminal' : 'Open Terminal'"></span>
            </button>
        </div>
    </div>

    <div x-show="terminalVisible" class="bg-slate-900 rounded-lg overflow-hidden border border-slate-700">
        <div id="terminal" class="text-sm font-mono text-slate-100" style="height: 400px;"></div>

        <div class="bg-slate-800 border-t border-slate-700 px-3 py-2 flex items-center justify-between text-xs text-slate-400">
            <div class="flex items-center gap-2">
                <span x-show="connected" class="inline-block w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                <span x-show="!connected" class="inline-block w-2 h-2 bg-red-500 rounded-full"></span>
                <span x-text="statusLabel()"></span>
                <span x-show="mode === 'http'" x-text="`CWD: ${cwd}`" class="ml-2"></span>
            </div>
            <div class="text-right">
                <span x-show="mode === 'http'" x-text="`Commands: ${commandCount}`"></span>
                <span x-show="sessionExpires" x-text="sessionExpires" class="ml-2"></span>
            </div>
        </div>
    </div>

    <div x-show="!terminalVisible && !sessionStarting" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-2">
        <p class="text-sm text-blue-800 dark:text-blue-200">
            Interactive shell inside your container. Supports <code class="font-mono text-xs">composer</code>, <code class="font-mono text-xs">php artisan</code>, and file cleanup in <code class="font-mono text-xs">/app</code>.
        </p>
        <p class="text-xs text-blue-700 dark:text-blue-400">
            Full PTY mode uses the WebSocket service (<code class="font-mono">php artisan container:terminal-ws</code>). If that service is unavailable, the terminal falls back to one command at a time over HTTP.
        </p>
        <p class="text-xs text-blue-700 dark:text-blue-400">
            Dangerous commands (sudo, docker, etc.) are blocked when you press Enter.
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
<link rel="stylesheet" href="{{ asset('css/xterm.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/xterm-addon-fit.min.css') }}">

@push('scripts')
<script>
function containerTerminal() {
    return {
        terminal: null,
        fitAddon: null,
        terminalVisible: false,
        sessionStarting: false,
        connected: false,
        mode: null,
        sessionToken: null,
        websocketUrl: null,
        websocketPath: '/container-terminal',
        ws: null,
        cwd: '/app',
        inputBuffer: '',
        history: [],
        historyIndex: 0,
        commandCount: 0,
        sessionExpires: null,
        expiryUpdateInterval: null,

        init() {},

        statusLabel() {
            if (!this.connected) {
                return 'Disconnected';
            }

            return this.mode === 'pty' ? 'Connected (PTY)' : 'Connected (HTTP fallback)';
        },

        async toggleTerminal() {
            if (this.terminalVisible) {
                await this.closeTerminal();
            } else {
                await this.openTerminal();
            }
        },

        async openTerminal() {
            this.terminalVisible = true;
            this.sessionStarting = true;
            this.mode = null;
            this.connected = false;

            await this.$nextTick();

            if (!this.terminal) {
                this.initializeTerminal();
            }

            try {
                const response = await fetch(`/my/services/{{ $service->id }}/terminal`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                });

                const { data, parseError } = await this.safeJsonResponse(response);
                if (parseError || !response.ok) {
                    this.terminal.write('\r\n❌ ' + ((data && data.error) || 'Failed to create terminal session') + '\r\n');
                    return;
                }

                this.sessionToken = data.session_token;
                this.websocketUrl = data.websocket_url;
                this.websocketPath = data.websocket_path || '/container-terminal';
                this.cwd = data.cwd || '/app';
                this.commandCount = 0;
                this.inputBuffer = '';
                this.history = [];
                this.historyIndex = 0;

                this.terminal.write('\r\n');

                try {
                    await this.connectWebSocket();
                    this.mode = 'pty';
                    this.connected = true;
                    this.terminal.write('✓ ' + (data.welcome_message || 'Connected.') + '\r\n');
                } catch (error) {
                    this.enableHttpFallback(data);
                }

                if (data.expires_at) {
                    this.updateExpiryDisplay(data.expires_at);
                    this.expiryUpdateInterval = setInterval(() => {
                        this.updateExpiryDisplay(data.expires_at);
                    }, 60000);
                }

                this.terminal.focus();
            } catch (error) {
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

        connectWebSocket() {
            return new Promise((resolve, reject) => {
                if (this.ws) {
                    this.ws.close();
                    this.ws = null;
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
                    if (this.mode === 'pty' && this.connected) {
                        this.connected = false;
                        this.terminal.write('\r\n\r\n✓ Terminal disconnected\r\n');
                    }
                };
            });
        },

        enableHttpFallback(data) {
            if (this.ws) {
                this.ws.close();
                this.ws = null;
            }

            this.mode = 'http';
            this.connected = true;
            this.terminal.write('⚠ Interactive WebSocket unavailable. Using HTTP command mode (one line at a time).\r\n');
            this.terminal.write('  Tip: use Overview → Clear /app if Initialize Laravel is blocked by leftover files.\r\n');
            this.terminal.write('✓ ' + (data.welcome_message || 'Connected.') + '\r\n');
            this.writePrompt();
        },

        initializeTerminal() {
            this.terminal = new Terminal({
                theme: {
                    background: '#0f172a',
                    foreground: '#e2e8f0',
                    cursor: '#64748b',
                },
                fontFamily: 'Monaco, Menlo, Ubuntu Mono, Courier New, monospace',
                fontSize: 14,
                cursorBlink: true,
                convertEol: true,
                rightClickSelectsWord: true,
            });

            const FitAddonClass = (window.FitAddon && window.FitAddon.FitAddon) ? window.FitAddon.FitAddon : window.FitAddon;
            this.fitAddon = new FitAddonClass();
            this.terminal.loadAddon(this.fitAddon);
            this.terminal.open(document.getElementById('terminal'));

            try {
                this.fitAddon.fit();
            } catch (e) {
                console.error('Failed to fit terminal:', e);
            }

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
                this.terminal.onResize(({ cols, rows }) => {
                    this.sendResize(cols, rows);
                });
            }

            const terminalElement = document.getElementById('terminal');
            if (terminalElement) {
                terminalElement.addEventListener('click', () => this.terminal.focus());
            }

            window.addEventListener('resize', () => {
                try {
                    this.fitAddon.fit();
                    this.sendResize();
                } catch (e) {
                    console.error('Failed to fit terminal on resize:', e);
                }
            });
        },

        handleHttpInput(data) {
            if (!this.connected || this.mode !== 'http') {
                return;
            }

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
                if (char === '\n') {
                    continue;
                }

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

        async sendCommand(command) {
            command = this.normalizeCommand(command);
            this.terminal.write('\r\n');

            if (!command) {
                this.writePrompt();
                return;
            }

            if (!this.sessionToken) {
                this.terminal.write('❌ No active session\r\n');
                this.writePrompt();
                return;
            }

            try {
                const response = await fetch(`/my/services/{{ $service->id }}/terminal/execute`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        session_token: this.sessionToken,
                        command: command,
                    }),
                });

                const { data, parseError } = await this.safeJsonResponse(response);
                const formatOutput = (text) => (text || '').replace(/\r?\n/g, '\r\n');

                if (parseError || !response.ok) {
                    this.terminal.write('❌ ' + ((data && data.error) || `Command failed (HTTP ${response.status})`) + '\r\n');
                    if (response.status === 404) {
                        this.connected = false;
                    }
                } else if (data.blocked) {
                    this.terminal.write('\x1b[31m' + formatOutput(data.output) + '\x1b[0m\r\n');
                } else {
                    if (data.output) {
                        this.terminal.write(formatOutput(data.output) + '\r\n');
                    }
                    this.cwd = data.cwd || this.cwd;
                    this.commandCount++;
                }
            } catch (error) {
                this.terminal.write('❌ Error: ' + error.message + '\r\n');
            }

            this.writePrompt();
        },

        writePrompt() {
            const user = 'user';
            const container = '{{ $deployment->container_name ?? "container" }}';
            this.terminal.write(`\x1b[32m${user}@${container}\x1b[0m:\x1b[34m${this.cwd}\x1b[0m$ `);
        },

        sendResize(cols, rows) {
            if (!this.ws || this.ws.readyState !== WebSocket.OPEN || this.mode !== 'pty') {
                return;
            }

            this.ws.send(JSON.stringify({
                type: 'resize',
                cols: cols || this.terminal.cols,
                rows: rows || this.terminal.rows,
            }));
        },

        async pasteFromClipboard() {
            if (!this.connected || !this.terminal) {
                return;
            }

            try {
                const text = await navigator.clipboard.readText();
                if (!text) {
                    return;
                }

                if (this.mode === 'pty' && this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(text);
                } else if (this.mode === 'http') {
                    this.insertText(text);
                }

                this.terminal.focus();
            } catch (error) {
                this.terminal.write('\r\n⚠ Could not read clipboard. Use Ctrl+Shift+V.\r\n');
                if (this.mode === 'http') {
                    this.writePrompt();
                }
            }
        },

        async closeTerminal() {
            if (this.ws) {
                this.ws.close();
                this.ws = null;
            }

            if (this.sessionToken) {
                try {
                    await fetch(`/my/services/{{ $service->id }}/terminal`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ session_token: this.sessionToken }),
                    });
                } catch (error) {
                    console.error('Failed to close terminal session:', error);
                }
            }

            this.connected = false;
            this.mode = null;
            this.sessionToken = null;
            this.inputBuffer = '';
            this.terminalVisible = false;

            if (this.expiryUpdateInterval) {
                clearInterval(this.expiryUpdateInterval);
            }

            if (this.terminal) {
                this.terminal.write('\r\n\r\n✓ Terminal closed\r\n');
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
            if (!text) {
                return { data: null, parseError: null };
            }

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

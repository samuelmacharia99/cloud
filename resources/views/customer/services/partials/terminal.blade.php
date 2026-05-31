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
                <span x-text="connected ? 'Connected (PTY)' : 'Disconnected'"></span>
            </div>
            <div class="text-right">
                <span x-show="sessionExpires" x-text="sessionExpires" class="ml-2"></span>
            </div>
        </div>
    </div>

    <div x-show="!terminalVisible && !sessionStarting" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 space-y-2">
        <p class="text-sm text-blue-800 dark:text-blue-200">
            Interactive shell inside your container. Supports full-screen programs, <code class="font-mono text-xs">composer</code>, <code class="font-mono text-xs">php artisan</code>, and pagers.
        </p>
        <p class="text-xs text-blue-700 dark:text-blue-400">
            Requires the terminal WebSocket service: <code class="font-mono">php artisan container:terminal-ws</code> (Supervisor: <code class="font-mono">deploy/supervisor/container-terminal-ws.conf</code>).
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
        sessionToken: null,
        websocketUrl: null,
        ws: null,
        sessionExpires: null,
        expiryUpdateInterval: null,

        init() {},

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

                if (!this.websocketUrl) {
                    this.terminal.write('\r\n❌ Terminal WebSocket URL is not configured. Set CONTAINER_TERMINAL_WS_PUBLIC_URL and run container:terminal-ws.\r\n');
                    return;
                }

                await this.connectWebSocket();

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

        connectWebSocket() {
            return new Promise((resolve, reject) => {
                const url = `${this.websocketUrl}?token=${encodeURIComponent(this.sessionToken)}`;
                this.ws = new WebSocket(url);

                this.ws.onopen = () => {
                    this.connected = true;
                    this.sendResize();
                    resolve();
                };

                this.ws.onmessage = (event) => {
                    this.terminal.write(event.data);
                };

                this.ws.onerror = () => {
                    this.connected = false;
                    this.terminal.write('\r\n❌ WebSocket connection failed. Is `php artisan container:terminal-ws` running?\r\n');
                    reject(new Error('WebSocket connection failed'));
                };

                this.ws.onclose = () => {
                    this.connected = false;
                    this.terminal.write('\r\n\r\n✓ Terminal disconnected\r\n');
                };
            });
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
                if (this.ws && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(data);
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

        sendResize(cols, rows) {
            if (!this.ws || this.ws.readyState !== WebSocket.OPEN) {
                return;
            }

            const payload = {
                type: 'resize',
                cols: cols || this.terminal.cols,
                rows: rows || this.terminal.rows,
            };

            this.ws.send(JSON.stringify(payload));
        },

        async pasteFromClipboard() {
            if (!this.connected || !this.terminal || !this.ws) {
                return;
            }

            try {
                const text = await navigator.clipboard.readText();
                if (text && this.ws.readyState === WebSocket.OPEN) {
                    this.ws.send(text);
                    this.terminal.focus();
                }
            } catch (error) {
                this.terminal.write('\r\n⚠ Could not read clipboard. Use Ctrl+Shift+V.\r\n');
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
            this.sessionToken = null;
            this.terminalVisible = false;

            if (this.expiryUpdateInterval) {
                clearInterval(this.expiryUpdateInterval);
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

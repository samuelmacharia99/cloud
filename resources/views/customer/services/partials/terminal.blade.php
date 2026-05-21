<div x-data="containerTerminal()" class="space-y-4">
    <!-- Terminal header -->
    <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Terminal</h3>
        <button @click="toggleTerminal()" :class="terminalVisible ? 'bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300' : 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300'" class="px-3 py-1.5 rounded text-sm font-medium transition">
            <span x-text="terminalVisible ? 'Close Terminal' : 'Open Terminal'"></span>
        </button>
    </div>

    <!-- Terminal container -->
    <div x-show="terminalVisible" class="bg-slate-900 rounded-lg overflow-hidden border border-slate-700">
        <!-- Terminal output area -->
        <div id="terminal" class="text-sm font-mono text-slate-100" style="height: 400px;"></div>

        <!-- Status bar -->
        <div class="bg-slate-800 border-t border-slate-700 px-3 py-2 flex items-center justify-between text-xs text-slate-400">
            <div class="flex items-center gap-2">
                <span x-show="connected" class="inline-block w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                <span x-show="!connected" class="inline-block w-2 h-2 bg-red-500 rounded-full"></span>
                <span x-text="connected ? 'Connected' : 'Disconnected'"></span>
                <span x-text="`CWD: ${cwd}`" class="ml-4"></span>
            </div>
            <div class="text-right">
                <span x-text="`Commands: ${commandCount}`"></span>
                <span x-show="sessionExpires" x-text="`Expires: ${sessionExpires}`" class="ml-2"></span>
            </div>
        </div>
    </div>

    <!-- Info messages -->
    <div x-show="!terminalVisible && !sessionStarting" class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
        <p class="text-sm text-blue-800 dark:text-blue-200">
            Click "Open Terminal" to start an interactive terminal session inside your container. You can run commands, navigate directories, and manage your application.
        </p>
        <p class="text-xs text-blue-700 dark:text-blue-400 mt-2">
            <strong>Blocked commands:</strong> sudo, su, docker, chroot, and other privileged/escape commands are blocked for security.
        </p>
    </div>

    <!-- Loading spinner -->
    <div x-show="sessionStarting" class="flex items-center justify-center py-8">
        <div class="inline-flex items-center gap-2">
            <div class="w-4 h-4 bg-blue-500 rounded-full animate-bounce"></div>
            <span class="text-slate-600 dark:text-slate-400">Starting terminal session...</span>
        </div>
    </div>
</div>

<!-- xterm.js scripts from CDN -->
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>

<!-- xterm.css from local server (allowed by CSP 'self') -->
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
        cwd: '/',
        inputBuffer: '',
        history: [],
        historyIndex: 0,
        commandCount: 0,
        sessionExpires: null,
        expiryUpdateInterval: null,
        serviceId: {{ $service->id }},

        init() {
            // Initialize xterm only when terminal is opened
        },

        async toggleTerminal() {
            if (this.terminalVisible) {
                this.closeTerminal();
            } else {
                this.openTerminal();
            }
        },

        async openTerminal() {
            this.terminalVisible = true;
            this.sessionStarting = true;

            // Wait for DOM to update before initializing terminal
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
                    },
                });

                if (!response.ok) {
                    const error = await response.json();
                    this.terminal.write('\r\n❌ ' + (error.error || 'Failed to create terminal session') + '\r\n');
                    this.sessionStarting = false;
                    return;
                }

                const data = await response.json();
                this.sessionToken = data.session_token;
                this.cwd = data.cwd;
                this.connected = true;
                this.commandCount = 0;

                this.terminal.write('\r\n');
                this.terminal.write('✓ ' + data.welcome_message + '\r\n');
                this.writePrompt();

                // Re-fit terminal to ensure proper dimensions after becoming visible
                try {
                    this.fitAddon.fit();
                } catch (e) {
                    console.error('Failed to fit terminal after session creation:', e);
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
                cols: 120,
                rows: 24,
            });

            this.fitAddon = new FitAddon();
            this.terminal.loadAddon(this.fitAddon);
            this.terminal.open(document.getElementById('terminal'));

            try {
                this.fitAddon.fit();
            } catch (e) {
                console.error('Failed to fit terminal:', e);
            }

            // Handle input
            this.terminal.onData((data) => {
                console.log('Terminal input received:', data);
                this.handleInput(data);
            });

            // Auto-focus terminal when clicked
            const terminalElement = document.getElementById('terminal');
            if (terminalElement) {
                terminalElement.addEventListener('click', () => {
                    this.terminal.focus();
                });
            }

            // Fit on window resize
            window.addEventListener('resize', () => {
                try {
                    this.fitAddon.fit();
                } catch (e) {
                    console.error('Failed to fit terminal on resize:', e);
                }
            });
        },

        handleInput(key) {
            if (!this.connected) return;

            // Ctrl+C - clear input buffer
            if (key === '\x03') {
                this.inputBuffer = '';
                this.terminal.write('^C\r\n');
                this.writePrompt();
                return;
            }

            // Ctrl+L - clear screen
            if (key === '\x0c') {
                this.terminal.clear();
                this.writePrompt();
                return;
            }

            // Enter - execute command
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

            // Backspace
            if (key === '\x7f') {
                if (this.inputBuffer.length > 0) {
                    this.inputBuffer = this.inputBuffer.slice(0, -1);
                    this.terminal.write('\b \b');
                }
                return;
            }

            // Arrow up - history back
            if (key === '\x1b[A') {
                if (this.historyIndex > 0) {
                    this.historyIndex--;
                    this.restoreHistory();
                }
                return;
            }

            // Arrow down - history forward
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

            // Regular character input
            if (key.length === 1 && key.charCodeAt(0) >= 32 && key.charCodeAt(0) < 127) {
                this.inputBuffer += key;
                this.terminal.write(key);
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

        async sendCommand(command) {
            this.terminal.write('\r\n');

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
                    },
                    body: JSON.stringify({
                        session_token: this.sessionToken,
                        command: command,
                    }),
                });

                if (!response.ok) {
                    const error = await response.json();
                    this.terminal.write('❌ ' + (error.error || 'Command failed') + '\r\n');
                    if (response.status === 404) {
                        this.connected = false;
                    }
                } else {
                    const data = await response.json();

                    if (data.blocked) {
                        this.terminal.write('\x1b[31m' + data.output + '\x1b[0m\r\n');
                    } else {
                        if (data.output) {
                            this.terminal.write(data.output + '\r\n');
                        }
                    }

                    this.cwd = data.cwd;
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

        async closeTerminal() {
            if (!this.sessionToken) {
                this.terminalVisible = false;
                return;
            }

            try {
                await fetch(`/my/services/{{ $service->id }}/terminal`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        session_token: this.sessionToken,
                    }),
                });
            } catch (error) {
                console.error('Failed to close terminal session:', error);
            }

            this.connected = false;
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
            const now = new Date();
            const diffMs = expiryDate - now;
            const diffMins = Math.floor(diffMs / 60000);

            if (diffMins < 0) {
                this.sessionExpires = 'Expired';
            } else if (diffMins < 1) {
                this.sessionExpires = 'Expires in seconds';
            } else if (diffMins < 60) {
                this.sessionExpires = `Expires in ${diffMins}m`;
            } else {
                const hours = Math.floor(diffMins / 60);
                const mins = diffMins % 60;
                this.sessionExpires = `Expires in ${hours}h ${mins}m`;
            }
        },
    };
}
</script>
@endpush

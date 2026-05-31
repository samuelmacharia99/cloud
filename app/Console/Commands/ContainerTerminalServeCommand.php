<?php

namespace App\Console\Commands;

use App\Services\Terminal\WebSocket\TerminalWebSocketServer;
use Illuminate\Console\Command;

class ContainerTerminalServeCommand extends Command
{
    protected $signature = 'container:terminal-ws
                            {--host= : Host interface to bind}
                            {--port= : TCP port to bind}';

    protected $description = 'Start the interactive container terminal WebSocket server (PTY)';

    public function handle(TerminalWebSocketServer $server): int
    {
        if ($host = $this->option('host')) {
            config(['terminal.websocket.host' => $host]);
        }

        if ($port = $this->option('port')) {
            config(['terminal.websocket.port' => (int) $port]);
        }

        if (! config('terminal.websocket.enabled', true)) {
            $this->error('Container terminal WebSocket server is disabled (CONTAINER_TERMINAL_WS_ENABLED=false).');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Starting container terminal WebSocket server on %s:%d%s',
            config('terminal.websocket.host'),
            config('terminal.websocket.port'),
            config('terminal.websocket.path')
        ));

        $server->start();

        return self::SUCCESS;
    }
}

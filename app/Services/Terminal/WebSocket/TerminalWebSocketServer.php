<?php

namespace App\Services\Terminal\WebSocket;

use App\Models\ContainerTerminalSession;
use App\Services\Terminal\ContainerTerminalPtyBridge;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;
use Laravel\Reverb\Servers\Reverb\Connection as WebSocketConnection;
use Laravel\Reverb\Servers\Reverb\Http\Connection as HttpConnection;
use Laravel\Reverb\Servers\Reverb\Http\Request as HttpRequest;
use Psr\Http\Message\RequestInterface;
use Ratchet\RFC6455\Handshake\RequestVerifier;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class TerminalWebSocketServer
{
    private LoopInterface $loop;

    private ServerNegotiator $negotiator;

    /** @var array<int, ContainerTerminalPtyBridge> */
    private array $bridges = [];

    public function __construct()
    {
        $this->loop = Loop::get();
        $this->negotiator = new ServerNegotiator(new RequestVerifier, new HttpFactory);
    }

    public function start(): void
    {
        $host = (string) config('terminal.websocket.host', '0.0.0.0');
        $port = (int) config('terminal.websocket.port', 8088);
        $path = $this->normalizedPath();

        $socket = new SocketServer("{$host}:{$port}", [], $this->loop);

        $socket->on('connection', function (ConnectionInterface $connection) use ($path) {
            $this->handleTcpConnection($connection, $path);
        });

        \Log::info('Container terminal WebSocket server listening', [
            'host' => $host,
            'port' => $port,
            'path' => $path,
        ]);

        $this->loop->run();
    }

    private function handleTcpConnection(ConnectionInterface $tcpConnection, string $expectedPath): void
    {
        $httpConnection = new HttpConnection($tcpConnection);
        $upgraded = false;

        $tcpConnection->on('data', function (string $chunk) use (
            $httpConnection,
            $expectedPath,
            &$upgraded

        ) {
            if ($upgraded) {
                return;
            }

            $request = HttpRequest::from(
                $chunk,
                $httpConnection,
                (int) config('terminal.websocket.max_request_size', 8192)
            );

            if ($request === null) {
                return;
            }

            $upgraded = true;

            if ($request->getMethod() === 'GET' && $request->getUri()->getPath() === '/health') {
                $this->respondAndClose($httpConnection, new Response(HttpResponse::HTTP_OK, [], 'ok'));

                return;
            }

            if (! $this->isWebSocketRequest($request)) {
                $this->respondAndClose($httpConnection, new Response(HttpResponse::HTTP_BAD_REQUEST, [], 'WebSocket upgrade required'));

                return;
            }

            if ($request->getUri()->getPath() !== $expectedPath) {
                $this->respondAndClose($httpConnection, new Response(HttpResponse::HTTP_NOT_FOUND, [], 'Not found'));

                return;
            }

            parse_str($request->getUri()->getQuery(), $query);
            $token = (string) ($query['token'] ?? '');

            $session = ContainerTerminalSession::query()
                ->with('deployment.node')
                ->where('token', $token)
                ->where('status', 'active')
                ->first();

            if (! $session || $session->isExpired()) {
                $this->respondAndClose($httpConnection, new Response(HttpResponse::HTTP_UNAUTHORIZED, [], 'Invalid or expired terminal session'));

                return;
            }

            $upgradeResponse = $this->negotiator
                ->handshake($request)
                ->withHeader('X-Powered-By', 'Talksasa Container Terminal');

            $httpConnection->write(Message::toString($upgradeResponse));

            $wsConnection = new WebSocketConnection($httpConnection);
            $wsConnection->withMaxMessageSize((int) config('terminal.websocket.max_message_size', 65536));
            $wsConnection->openBuffer();

            try {
                $bridge = new ContainerTerminalPtyBridge($session, $wsConnection);
                $this->bridges[$wsConnection->id()] = $bridge;

                $cols = (int) config('terminal.pty.default_cols', 120);
                $rows = (int) config('terminal.pty.default_rows', 30);
                $bridge->start($cols, $rows);
            } catch (\Throwable $e) {
                \Log::error('Failed to start container terminal PTY bridge', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
                $wsConnection->send("\r\n\x1b[31mFailed to connect to container: {$e->getMessage()}\x1b[0m\r\n");
                $wsConnection->close();

                return;
            }

            $wsConnection->onMessage(function (string $message) use ($bridge, $session) {
                try {
                    $bridge->handleInput($message);
                } catch (\Throwable $e) {
                    \Log::warning('Terminal PTY input error', [
                        'session_id' => $session->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

            $wsConnection->onClose(function () use ($wsConnection, $bridge, $session) {
                unset($this->bridges[$wsConnection->id()]);
                $bridge->close();
                $session->close();
            });
        });
    }

    private function respondAndClose(HttpConnection $connection, Response $response): void
    {
        $connection->write(Message::toString($response));
        $connection->close();
    }

    private function isWebSocketRequest(RequestInterface $request): bool
    {
        return Str::lower($request->getHeaderLine('Upgrade')) === 'websocket';
    }

    private function normalizedPath(): string
    {
        $path = (string) config('terminal.websocket.path', '/container-terminal');
        $path = '/'.trim($path, '/');

        return $path === '/' ? '/container-terminal' : $path;
    }
}

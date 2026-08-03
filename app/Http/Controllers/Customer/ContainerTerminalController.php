<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ContainerTerminalSession;
use App\Models\Service;
use App\Services\Terminal\ContainerTerminalService;
use App\Services\Terminal\TerminalSecurityGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContainerTerminalController extends Controller
{
    private ContainerTerminalService $terminalService;

    public function __construct(ContainerTerminalService $terminalService)
    {
        $this->terminalService = $terminalService;
    }

    /**
     * Create a new terminal session
     * POST /my/services/{service}/terminal
     */
    public function create(Service $service): JsonResponse
    {
        $this->authorize('accessTerminal', $service);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json([
                    'error' => 'Service is not an application hosting service',
                ], 400);
            }

            $deployment = $service->containerDeployment;
            if (! $deployment) {
                return response()->json([
                    'error' => 'Application not deployed yet',
                ], 400);
            }

            if ($deployment->status !== 'running') {
                return response()->json([
                    'error' => 'Application is not running. Start the app first.',
                ], 400);
            }

            $session = $this->terminalService->createSession($service, auth()->user(), request());
            $meta = $this->terminalService->sessionMeta($session);

            return response()->json([
                'session_token' => $session->token,
                'cwd' => $meta['cwd'],
                'shell_user' => $meta['shell_user'],
                'container_name' => $meta['container_name'],
                'expires_at' => $session->expires_at->toIso8601String(),
                'hard_expires_at' => $session->hard_expires_at?->toIso8601String(),
                'websocket_url' => $this->terminalService->resolveWebSocketUrl(),
                'websocket_path' => $this->terminalService->resolveWebSocketPath(),
                'websocket_enabled' => $meta['websocket_enabled'],
                'max_command_length' => $meta['max_command_length'],
                'mode' => 'pty',
                'welcome_message' => "Connected to application: {$meta['container_name']}\nInteractive shell. Type 'exit' to close.",
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to create terminal session for service {$service->id}: ".$e->getMessage());

            return response()->json([
                'error' => 'Failed to create terminal session: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extend an active terminal session (keep-alive).
     * POST /my/services/{service}/terminal/extend
     */
    public function extend(Service $service, Request $request): JsonResponse
    {
        $this->authorize('accessTerminal', $service);

        try {
            $validated = $request->validate([
                'session_token' => 'required|string|max:64',
            ]);

            $session = $this->findOwnedSession($service, $validated['session_token']);
            $session = $this->terminalService->extendSession($session);

            return response()->json([
                'expires_at' => $session->expires_at?->toIso8601String(),
                'hard_expires_at' => $session->hard_expires_at?->toIso8601String(),
                'message' => 'Session extended',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Terminal session not found or expired',
                'code' => 'session_expired',
            ], 401);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'session expired')) {
                return response()->json([
                    'error' => 'Terminal session expired',
                    'code' => 'session_expired',
                ], 401);
            }

            return response()->json([
                'error' => 'Failed to extend session: '.$message,
            ], 500);
        }
    }

    /**
     * Execute a command in the terminal
     * POST /my/services/{service}/terminal/execute
     */
    public function execute(Service $service, Request $request): JsonResponse
    {
        $this->authorize('accessTerminal', $service);

        try {
            $maxLength = (new TerminalSecurityGuard)->maxCommandLength();
            $validated = $request->validate([
                'session_token' => 'required|string|max:64',
                'command' => 'required|string|max:'.$maxLength,
            ]);

            if ($service->product?->type !== 'container_hosting') {
                return response()->json([
                    'error' => 'Service is not an application hosting service',
                ], 400);
            }

            $session = $this->findOwnedSession($service, $validated['session_token']);

            $result = $this->terminalService->executeCommand(
                $session,
                $validated['command'],
                $request->ip()
            );

            return response()->json($result);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Terminal session not found or expired',
                'code' => 'session_expired',
            ], 401);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (str_contains(strtolower($message), 'session expired')
                || str_contains(strtolower($message), 'session is not active')) {
                return response()->json([
                    'error' => 'Terminal session expired. Reconnecting…',
                    'code' => 'session_expired',
                ], 401);
            }

            \Log::error("Failed to execute terminal command for service {$service->id}: ".$message);

            return response()->json([
                'error' => 'Failed to execute command: '.$message,
            ], 500);
        }
    }

    /**
     * Close a terminal session
     * DELETE /my/services/{service}/terminal
     */
    public function close(Service $service, Request $request): JsonResponse
    {
        $this->authorize('accessTerminal', $service);

        try {
            $validated = $request->validate([
                'session_token' => 'required|string|max:64',
            ]);

            $session = $this->findOwnedSession($service, $validated['session_token']);

            $this->terminalService->closeSession($session);

            return response()->json([
                'message' => 'Terminal session closed',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Terminal session not found',
            ], 404);
        } catch (\Exception $e) {
            \Log::error("Failed to close terminal session for service {$service->id}: ".$e->getMessage());

            return response()->json([
                'error' => 'Failed to close session: '.$e->getMessage(),
            ], 500);
        }
    }

    private function findOwnedSession(Service $service, string $token): ContainerTerminalSession
    {
        return ContainerTerminalSession::where('token', $token)
            ->where('user_id', auth()->id())
            ->where('service_id', $service->id)
            ->firstOrFail();
    }
}

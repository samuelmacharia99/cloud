<?php

namespace App\Http\Controllers\Customer;

use App\Models\ContainerTerminalSession;
use App\Models\Service;
use App\Services\Terminal\ContainerTerminalService;
use App\Http\Controllers\Controller;
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
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            if ($service->product?->type !== 'container_hosting') {
                return response()->json([
                    'error' => 'Service is not a container hosting service',
                ], 400);
            }

            $deployment = $service->containerDeployment;
            if (!$deployment) {
                return response()->json([
                    'error' => 'Container not deployed yet',
                ], 400);
            }

            if ($deployment->status !== 'running') {
                return response()->json([
                    'error' => 'Container is not running. Start the container first.',
                ], 400);
            }

            $session = $this->terminalService->createSession($service, auth()->user(), request());

            return response()->json([
                'session_token' => $session->token,
                'cwd' => $session->cwd,
                'expires_at' => $session->expires_at->toIso8601String(),
                'welcome_message' => "Connected to container: {$deployment->container_name}\nType 'exit' to close terminal",
            ]);
        } catch (\Exception $e) {
            \Log::error("Failed to create terminal session for service {$service->id}: " . $e->getMessage());

            return response()->json([
                'error' => 'Failed to create terminal session: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute a command in the terminal
     * POST /my/services/{service}/terminal/execute
     */
    public function execute(Service $service, Request $request): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            $validated = $request->validate([
                'session_token' => 'required|string|max:64',
                'command' => 'required|string|max:1024',
            ]);

            if ($service->product?->type !== 'container_hosting') {
                return response()->json([
                    'error' => 'Service is not a container hosting service',
                ], 400);
            }

            // Load session by token and verify ownership
            $session = ContainerTerminalSession::where('token', $validated['session_token'])
                ->where('user_id', auth()->id())
                ->where('service_id', $service->id)
                ->firstOrFail();

            // Execute command
            $result = $this->terminalService->executeCommand(
                $session,
                $validated['command'],
                $request->ip()
            );

            return response()->json($result);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Terminal session not found or expired',
            ], 404);
        } catch (\Exception $e) {
            \Log::error("Failed to execute terminal command for service {$service->id}: " . $e->getMessage());

            return response()->json([
                'error' => 'Failed to execute command: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Close a terminal session
     * DELETE /my/services/{service}/terminal
     */
    public function close(Service $service, Request $request): JsonResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        try {
            $validated = $request->validate([
                'session_token' => 'required|string|max:64',
            ]);

            $session = ContainerTerminalSession::where('token', $validated['session_token'])
                ->where('user_id', auth()->id())
                ->where('service_id', $service->id)
                ->firstOrFail();

            $this->terminalService->closeSession($session);

            return response()->json([
                'message' => 'Terminal session closed',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Terminal session not found',
            ], 404);
        } catch (\Exception $e) {
            \Log::error("Failed to close terminal session for service {$service->id}: " . $e->getMessage());

            return response()->json([
                'error' => 'Failed to close session: ' . $e->getMessage(),
            ], 500);
        }
    }
}

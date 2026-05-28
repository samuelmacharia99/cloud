<?php

namespace App\Services\Terminal;

use App\Models\ContainerTerminalLog;
use App\Models\ContainerTerminalSession;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Illuminate\Http\Request;
use Exception;

class ContainerTerminalService
{
    private TerminalSecurityGuard $guard;

    public function __construct()
    {
        $this->guard = new TerminalSecurityGuard();
    }

    public function createSession(Service $service, User $user, Request $request): ContainerTerminalSession
    {
        if ($service->user_id !== $user->id) {
            throw new Exception('Unauthorized access to service');
        }

        if ($service->product?->type !== 'container_hosting') {
            throw new Exception('Service is not a container hosting service');
        }

        $deployment = $service->containerDeployment;
        if (!$deployment || $deployment->status !== 'running') {
            throw new Exception('Container is not running');
        }

        // Close any existing active sessions for this user+service
        ContainerTerminalSession::where('service_id', $service->id)
            ->where('user_id', $user->id)
            ->active()
            ->update([
                'status' => 'closed',
                'expires_at' => now(),
            ]);

        // Create new session
        $token = bin2hex(random_bytes(32));
        $now = now();
        $session = ContainerTerminalSession::create([
            'token' => $token,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'deployment_id' => $deployment->id,
            'container_name' => $deployment->container_name,
            // Enforce app-root landing for customer terminal sessions.
            'cwd' => '/app',
            'status' => 'active',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'command_count' => 0,
            'last_activity_at' => $now,
            'expires_at' => $now->clone()->addMinutes(30),
            'hard_expires_at' => $now->clone()->addHours(2),
        ]);

        \Log::info("Terminal session created for service {$service->id}, user {$user->id}");

        return $session;
    }

    public function executeCommand(ContainerTerminalSession $session, string $rawCommand, string $ip): array
    {
        // Validate session
        if ($session->isExpired()) {
            $session->update(['status' => 'expired']);
            throw new Exception('Session expired');
        }

        if ($session->status !== 'active') {
            throw new Exception('Session is not active');
        }

        // Validate and sanitize command
        $validation = $this->guard->validate($rawCommand);
        $sanitized = $validation['sanitized'];

        if (!$validation['allowed']) {
            // Log blocked command
            ContainerTerminalLog::create([
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'service_id' => $session->service_id,
                'command' => $rawCommand,
                'sanitized_command' => $sanitized,
                'output' => "Error: {$validation['reason']}",
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'ip_address' => $ip,
                'is_blocked' => true,
                'block_reason' => $validation['reason'],
            ]);

            $session->addToHistory($rawCommand);
            $session->extendExpiry();

            return [
                'output' => "❌ Command blocked: {$validation['reason']}",
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'blocked' => true,
            ];
        }

        // Build docker exec command
        $dockerCmd = $this->buildDockerExecCommand($session, $sanitized);

        try {
            // Execute via SSH
            $deployment = $session->deployment;
            $node = $deployment->node;

            $ssh = SSHService::forNode($node);

            try {
                $startTime = microtime(true);
                $output = $ssh->exec($dockerCmd, 30);
                $executionMs = (int)((microtime(true) - $startTime) * 1000);

                // Parse output: extract exit code and new cwd
                $lines = explode("\n", trim($output));
                $exitCode = 0;
                $newCwd = $session->cwd;
                $outputLines = [];

                foreach ($lines as $line) {
                    if (preg_match('/^__EXIT:(\d+)$/', $line, $matches)) {
                        $exitCode = (int)$matches[1];
                    } elseif (!empty($line) && !preg_match('/^__EXIT:/', $line)) {
                        $outputLines[] = $line;
                    }
                }

                // Last non-empty line that's not exit code is the new cwd (pwd output)
                if (count($outputLines) > 0) {
                    $lastLine = $outputLines[count($outputLines) - 1];
                    // Check if it looks like a path (starts with /)
                    if (preg_match('#^/[^\s]*$#', $lastLine)) {
                        $newCwd = $lastLine;
                        array_pop($outputLines);
                    }
                }

                $cleanOutput = implode("\n", $outputLines);

                // Update session
                $session->update([
                    'cwd' => $newCwd,
                    'last_activity_at' => now(),
                    'expires_at' => now()->addMinutes(30),
                ]);
                $session->increment('command_count');

                // Log command
                ContainerTerminalLog::create([
                    'session_id' => $session->id,
                    'user_id' => $session->user_id,
                    'service_id' => $session->service_id,
                    'command' => $rawCommand,
                    'sanitized_command' => $sanitized,
                    'output' => $cleanOutput,
                    'exit_code' => $exitCode,
                    'execution_ms' => $executionMs,
                    'cwd' => $session->cwd,
                    'ip_address' => $ip,
                    'is_blocked' => false,
                ]);

                $session->addToHistory($rawCommand);

                return [
                    'output' => $cleanOutput,
                    'exit_code' => $exitCode,
                    'cwd' => $newCwd,
                    'blocked' => false,
                ];
            } finally {
                $ssh->disconnect();
            }
        } catch (Exception $e) {
            \Log::error("Terminal command execution failed for session {$session->id}: " . $e->getMessage());

            ContainerTerminalLog::create([
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'service_id' => $session->service_id,
                'command' => $rawCommand,
                'sanitized_command' => $sanitized,
                'output' => "Error executing command: " . $e->getMessage(),
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'ip_address' => $ip,
                'is_blocked' => false,
            ]);

            $session->addToHistory($rawCommand);
            $session->extendExpiry();

            return [
                'output' => "❌ Error: " . $e->getMessage(),
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'blocked' => false,
            ];
        }
    }

    public function closeSession(ContainerTerminalSession $session): void
    {
        $session->close();
        \Log::info("Terminal session closed: {$session->id}");
    }

    public function cleanupExpiredSessions(): void
    {
        $now = now();

        // Mark sessions as expired if they exceed hard limit or idle timeout
        ContainerTerminalSession::active()
            ->where(function ($query) use ($now) {
                $query->where('hard_expires_at', '<', $now)
                    ->orWhere('expires_at', '<', $now);
            })
            ->update(['status' => 'expired']);

        \Log::debug('Terminal session cleanup completed');
    }

    private function buildDockerExecCommand(ContainerTerminalSession $session, string $command): string
    {
        // Prefer the live deployment container name over the session's denormalized
        // copy, which can drift if the container was renamed/redeployed.
        $containerName = $session->deployment?->container_name ?: $session->container_name;

        // The user's command is a full shell line (with args, pipes, etc.), so it
        // must NOT be quoted as a single token. Base64-encode it to embed safely,
        // then eval it inside the container so builtins like `cd` affect the same
        // shell whose pwd we capture afterwards for CWD tracking.
        $encodedCmd = base64_encode($command);

        $targetCwd = trim((string) $session->cwd);
        if ($targetCwd === '' || $targetCwd === '/') {
            $targetCwd = '/app';
        }

        $script = 'cd ' . escapeshellarg($targetCwd) . ' 2>/dev/null || cd /app 2>/dev/null; '
            . 'eval "$(printf %s ' . escapeshellarg($encodedCmd) . ' | base64 -d)"; '
            . 'printf "\n__EXIT:%d\n" "$?"; pwd';

        return 'docker exec -u www-data ' . escapeshellarg($containerName)
            . ' sh -c ' . escapeshellarg($script);
    }

}

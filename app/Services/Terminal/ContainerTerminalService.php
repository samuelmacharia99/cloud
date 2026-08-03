<?php

namespace App\Services\Terminal;

use App\Exceptions\SSH\SSHCommandException;
use App\Models\ContainerTerminalLog;
use App\Models\ContainerTerminalSession;
use App\Models\Service;
use App\Models\User;
use App\Services\SSH\SSHService;
use Exception;
use Illuminate\Http\Request;

class ContainerTerminalService
{
    private const COMPOSER_BIN = '/usr/local/bin/composer';

    private TerminalSecurityGuard $guard;

    public function __construct()
    {
        $this->guard = new TerminalSecurityGuard;
    }

    public function createSession(Service $service, User $user, Request $request): ContainerTerminalSession
    {
        if ($service->user_id !== $user->id && ! $user->isAdmin()) {
            throw new Exception('Unauthorized access to service');
        }

        if ($service->product?->type !== 'container_hosting') {
            throw new Exception('Service is not an application hosting service');
        }

        $deployment = $service->containerDeployment;
        if (! $deployment || $deployment->status !== 'running') {
            throw new Exception('Container is not running');
        }

        // Cap concurrent tabs; close oldest when at the limit.
        $maxSessions = max(1, (int) config('terminal.session.max_per_user_service', 3));
        $activeSessions = ContainerTerminalSession::where('service_id', $service->id)
            ->where('user_id', $user->id)
            ->active()
            ->orderBy('created_at')
            ->get();

        while ($activeSessions->count() >= $maxSessions) {
            $oldest = $activeSessions->shift();
            if ($oldest) {
                $oldest->close();
            }
        }

        $token = bin2hex(random_bytes(32));
        $now = now();
        $idleMinutes = max(5, (int) config('terminal.session.idle_minutes', 240));
        $hardHours = max(1, (int) config('terminal.session.hard_hours', 12));
        $session = ContainerTerminalSession::create([
            'token' => $token,
            'service_id' => $service->id,
            'user_id' => $user->id,
            'deployment_id' => $deployment->id,
            'container_name' => $deployment->container_name,
            'cwd' => '/app',
            'status' => 'active',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'command_count' => 0,
            'last_activity_at' => $now,
            'expires_at' => $now->clone()->addMinutes($idleMinutes),
            'hard_expires_at' => $now->clone()->addHours($hardHours),
        ]);

        \Log::info("Terminal session created for service {$service->id}, user {$user->id}");

        return $session;
    }

    public function sessionMeta(ContainerTerminalSession $session): array
    {
        $session->loadMissing('service.product.containerTemplate', 'deployment');
        $templateSlug = $session->service?->product?->containerTemplate?->slug;
        $shellUser = ContainerDockerExecUserResolver::execUser($templateSlug) ?? 'app';

        return [
            'shell_user' => $shellUser,
            'container_name' => $session->deployment?->container_name ?: $session->container_name,
            'cwd' => $session->cwd ?: '/app',
            'websocket_enabled' => (bool) config('terminal.websocket.enabled', true),
            'max_command_length' => (new TerminalSecurityGuard)->maxCommandLength(),
        ];
    }

    public function extendSession(ContainerTerminalSession $session, ?int $minutes = null): ContainerTerminalSession
    {
        if ($session->isExpired() || $session->status !== 'active') {
            throw new Exception('Session expired');
        }

        $session->extendSession($minutes);

        return $session->fresh();
    }

    public function resolveWebSocketUrl(): ?string
    {
        if (! config('terminal.websocket.enabled', true)) {
            return null;
        }

        $configured = config('terminal.websocket.public_url');
        $path = '/'.trim((string) config('terminal.websocket.path', '/container-terminal'), '/');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/').$path;
        }

        // When no public URL is configured, the browser builds a same-origin
        // WebSocket URL (nginx should proxy websocket_path to container:terminal-ws).
        return null;
    }

    public function resolveWebSocketPath(): string
    {
        return '/'.trim((string) config('terminal.websocket.path', '/container-terminal'), '/');
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

        if (! $validation['allowed']) {
            $blockMessage = $this->guard->formatBlockMessage($validation);

            // Log blocked command
            ContainerTerminalLog::create([
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'service_id' => $session->service_id,
                'command' => $rawCommand,
                'sanitized_command' => $sanitized,
                'output' => $blockMessage,
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'ip_address' => $ip,
                'is_blocked' => true,
                'block_reason' => $validation['reason'],
            ]);

            $session->addToHistory($rawCommand);
            $session->extendExpiry();

            return [
                'output' => '❌ '.$blockMessage,
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'blocked' => true,
                'block_reason' => $validation['reason'],
                'block_hint' => $validation['hint'] ?? null,
            ];
        }

        // Legacy HTTP command mode (fallback when PTY WebSocket server is unavailable).
        $dockerCmd = $this->buildDockerExecCommand(
            $session,
            $this->resolveComposerCommand(self::applyArtisanProductionFlags($sanitized))
        );

        try {
            // Execute via SSH
            $deployment = $session->deployment;
            $node = $deployment->node;

            $ssh = SSHService::forNode($node);

            try {
                $startTime = microtime(true);
                $timeoutSeconds = $this->commandTimeoutSeconds($sanitized);
                $output = $ssh->exec($dockerCmd, $timeoutSeconds);
                $executionMs = (int) ((microtime(true) - $startTime) * 1000);

                // Parse output: extract exit code and new cwd
                $lines = explode("\n", trim($output));
                $exitCode = 0;
                $newCwd = $session->cwd;
                $outputLines = [];

                foreach ($lines as $line) {
                    if (preg_match('/^__EXIT:(\d+)$/', $line, $matches)) {
                        $exitCode = (int) $matches[1];
                    } elseif (! empty($line) && ! preg_match('/^__EXIT:/', $line)) {
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

                $newCwd = $this->constrainCwdToAppRoot($newCwd);

                $cleanOutput = implode("\n", $outputLines);

                // Update session
                $session->update([
                    'cwd' => $newCwd,
                    'last_activity_at' => now(),
                    'expires_at' => now()->addMinutes(max(5, (int) config('terminal.session.idle_minutes', 240))),
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
                    'exit_code' => $this->normalizeExitCode($exitCode),
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
                    'expires_at' => $session->fresh()->expires_at?->toIso8601String(),
                ];
            } finally {
                $ssh->disconnect();
            }
        } catch (SSHCommandException $e) {
            \Log::error("Terminal command execution failed for session {$session->id}: ".$e->getMessage());

            $displayOutput = $this->formatFailedCommandOutput($e);

            ContainerTerminalLog::create([
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'service_id' => $session->service_id,
                'command' => $rawCommand,
                'sanitized_command' => $sanitized,
                'output' => $displayOutput,
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'ip_address' => $ip,
                'is_blocked' => false,
            ]);

            $session->addToHistory($rawCommand);
            $session->extendExpiry();

            return [
                'output' => $displayOutput,
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'blocked' => false,
            ];
        } catch (Exception $e) {
            \Log::error("Terminal command execution failed for session {$session->id}: ".$e->getMessage());

            ContainerTerminalLog::create([
                'session_id' => $session->id,
                'user_id' => $session->user_id,
                'service_id' => $session->service_id,
                'command' => $rawCommand,
                'sanitized_command' => $sanitized,
                'output' => 'Error executing command: '.$e->getMessage(),
                'exit_code' => 1,
                'cwd' => $session->cwd,
                'ip_address' => $ip,
                'is_blocked' => false,
            ]);

            $session->addToHistory($rawCommand);
            $session->extendExpiry();

            return [
                'output' => '❌ Error: '.$e->getMessage(),
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

        $script = 'export PATH="/usr/local/bin:/usr/bin:/bin"; '
            .'export HOME="${HOME:-/tmp}"; '
            .'export NPM_CONFIG_CACHE="${NPM_CONFIG_CACHE:-/tmp/.npm}"; '
            .'export npm_config_cache="${npm_config_cache:-/tmp/.npm}"; '
            .'cd '.escapeshellarg($targetCwd).' 2>/dev/null || cd /app 2>/dev/null; '
            .'eval "$(printf %s '.escapeshellarg($encodedCmd).' | base64 -d)"; '
            .'printf "\n__EXIT:%d\n" "$?"; pwd';

        $session->loadMissing('service.product.containerTemplate');
        $templateSlug = $session->service?->product?->containerTemplate?->slug;
        $userFlag = ContainerDockerExecUserResolver::execUserFlag($templateSlug);

        // Always set -w so exec does not inherit PID 1's cwd. After a failed Git
        // sync the /app mount can be invalid and inherited cwd triggers:
        // "current working directory is outside of container mount namespace root".
        return 'docker exec '.$userFlag.'-w /app '
            .'-e HOME=/tmp -e NPM_CONFIG_CACHE=/tmp/.npm -e npm_config_cache=/tmp/.npm '
            .escapeshellarg($containerName)
            .' sh -c '.escapeshellarg($script);
    }

    /**
     * Ensure artisan commands run non-interactively in production.
     *
     * Laravel's ConfirmableTrait cancels migrate/seed/wipe without a TTY unless --force
     * is passed. HTTP terminal mode has no TTY, so we inject the flags automatically.
     */
    public static function applyArtisanProductionFlags(string $command): string
    {
        $trimmed = trim($command);
        if ($trimmed === '' || preg_match('/^(?:php\s+)?artisan(\s+|$)/i', $trimmed) !== 1) {
            return $command;
        }

        $hasNoInteraction = preg_match('/(^|\s)(--no-interaction|-n)(\s|$)/', $trimmed) === 1;
        if (! $hasNoInteraction) {
            $trimmed .= ' --no-interaction';
        }

        $needsForce = preg_match(
            '/\bartisan\s+(migrate\b|migrate:\w+|db:seed|db:wipe)\b/i',
            $trimmed
        ) === 1;
        $hasForce = preg_match('/(^|\s)--force(\s|$)/', $trimmed) === 1;

        if ($needsForce && ! $hasForce) {
            $trimmed .= ' --force';
        }

        return $trimmed;
    }

    /**
     * Map composer CLI invocations to the persistent install path.
     */
    private function resolveComposerCommand(string $command): string
    {
        $trimmed = trim($command);
        if ($trimmed === '') {
            return $command;
        }

        if (preg_match('/^composer(\s|$)/', $trimmed) === 1) {
            return preg_replace('/^composer\b/', 'php '.self::COMPOSER_BIN, $trimmed, 1);
        }

        $firstToken = strtok($trimmed, " \t");
        $composerSubcommands = [
            'about', 'archive', 'audit', 'browse', 'bump', 'check-platform-reqs',
            'clear-cache', 'clearcache', 'config', 'create-project', 'depends',
            'diagnose', 'dump-autoload', 'dumpautoload', 'exec', 'fund', 'global',
            'help', 'home', 'init', 'install', 'licenses', 'list', 'outdated',
            'prohibits', 'reinstall', 'remove', 'require', 'run-script', 'search',
            'self-update', 'show', 'status', 'suggests', 'update', 'validate',
        ];

        if (in_array($firstToken, $composerSubcommands, true)) {
            return 'php '.self::COMPOSER_BIN.' '.$trimmed;
        }

        return $command;
    }

    private function constrainCwdToAppRoot(string $cwd): string
    {
        $normalized = trim($cwd);
        if ($normalized === '') {
            return '/app';
        }

        if ($normalized === '/app' || str_starts_with($normalized, '/app/')) {
            return $normalized;
        }

        return '/app';
    }

    private function commandTimeoutSeconds(string $command): int
    {
        if (preg_match('/\bartisan\b/i', $command)
            && preg_match('/\b(migrate(?::fresh|:reset|:rollback)?|db:seed|db:wipe|schema:dump|queue:work|schedule:run)\b/i', $command)) {
            return (int) config('terminal.command_timeouts.artisan_long', 900);
        }

        if (preg_match('/\bartisan\b/i', $command)) {
            return (int) config('terminal.command_timeouts.artisan', 600);
        }

        if (preg_match('/\b(composer|npm|yarn|pnpm|pecl|wp|next\s+build)\b/i', $command)) {
            return (int) config('terminal.command_timeouts.build', 900);
        }

        if (preg_match('/\b(wget|curl|tar|unzip|git\s+clone)\b/i', $command)) {
            return (int) config('terminal.command_timeouts.network', 120);
        }

        return (int) config('terminal.command_timeouts.default', 30);
    }

    private function formatFailedCommandOutput(SSHCommandException $e): string
    {
        $partialOutput = trim($e->output);
        $errorDetail = trim($e->errorDetail);

        if ($partialOutput !== '') {
            $hint = 'Command failed before completion.';
            if ($errorDetail === '' || str_contains(strtolower($errorDetail), 'status')) {
                $hint = 'Command timed out or was interrupted. Partial output is shown below.';
            }

            return $partialOutput
                ."\n\n⚠ {$hint}"
                .($errorDetail !== '' ? "\n❌ {$errorDetail}" : '');
        }

        return '❌ '.$e->getMessage();
    }

    /**
     * MySQL signed TINYINT maxed at 127; abort/OOM codes like 134 must be stored safely.
     */
    private function normalizeExitCode(mixed $exitCode): ?int
    {
        if ($exitCode === null || $exitCode === '') {
            return null;
        }

        $code = (int) $exitCode;

        // Keep shell-style 0–255; clamp anything wild.
        if ($code < 0) {
            return 255;
        }

        if ($code > 255) {
            return 255;
        }

        return $code;
    }
}

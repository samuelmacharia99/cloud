<?php

namespace App\Services\Terminal;

class TerminalSecurityGuard
{
    /**
     * @var list<array{pattern: string, reason: string, hint: string}>
     */
    private const BLOCKED_RULES = [
        [
            'pattern' => '/\bsudo\b/i',
            'reason' => 'Privilege escalation is not allowed',
            'hint' => 'Run commands as the app user; escalate via the control panel if you need host changes.',
        ],
        [
            'pattern' => '/\bsu\b(\s|$)/i',
            'reason' => 'Switching users is not allowed',
            'hint' => 'You are already running as the application user inside the container.',
        ],
        [
            'pattern' => '/\bsudo\s*-/i',
            'reason' => 'Privilege escalation is not allowed',
            'hint' => 'Run commands as the app user without sudo.',
        ],
        [
            'pattern' => '/\bdocker\b/i',
            'reason' => 'Docker host control is not allowed',
            'hint' => 'Manage the app from the service Overview (start/stop/redeploy) instead.',
        ],
        [
            'pattern' => '/\bdockerd\b/i',
            'reason' => 'Docker host control is not allowed',
            'hint' => 'Use the service controls in the customer portal.',
        ],
        [
            'pattern' => '/\bnsenter\b/i',
            'reason' => 'Host namespace access is not allowed',
            'hint' => 'Stay inside the application container filesystem (/app).',
        ],
        [
            'pattern' => '/\bchroot\b/i',
            'reason' => 'Changing the root filesystem is not allowed',
            'hint' => 'Work under /app for your application files.',
        ],
        [
            'pattern' => '/\bunshare\b/i',
            'reason' => 'Namespace manipulation is not allowed',
            'hint' => 'Use standard process tools (ps, kill) for your own app processes.',
        ],
        [
            'pattern' => '/\bpivot_root\b/i',
            'reason' => 'Changing the root filesystem is not allowed',
            'hint' => 'Stay inside the application container.',
        ],
        [
            'pattern' => '/chmod\s+[+]s/i',
            'reason' => 'Setuid bits are not allowed',
            'hint' => 'Use normal file permissions (e.g. chmod 644 / chmod 755).',
        ],
        [
            'pattern' => '/chown\s+root/i',
            'reason' => 'Changing ownership to root is not allowed',
            'hint' => 'Keep files owned by the application user.',
        ],
        [
            'pattern' => '/\bpasswd\b/i',
            'reason' => 'Changing system passwords is not allowed',
            'hint' => 'Manage account access from the customer portal.',
        ],
        [
            'pattern' => '/\/etc\/shadow/i',
            'reason' => 'Access to system password files is not allowed',
            'hint' => 'Use application config under /app instead.',
        ],
        [
            'pattern' => '/\/etc\/passwd/i',
            'reason' => 'Access to system account files is not allowed',
            'hint' => 'Use application config under /app instead.',
        ],
        [
            'pattern' => '/\brmdir\s+\/\b/i',
            'reason' => 'Destructive root filesystem operations are not allowed',
            'hint' => 'Remove files under /app only.',
        ],
        [
            'pattern' => '/\brm\s+(-[rfRF]+\s+)?\/\s/i',
            'reason' => 'Destructive root filesystem operations are not allowed',
            'hint' => 'Remove paths under /app (e.g. rm -rf /app/tmp/cache).',
        ],
        [
            'pattern' => '/:\(\)\s*\{.*\}/i',
            'reason' => 'Fork-bomb patterns are not allowed',
            'hint' => 'Avoid recursive process spawners.',
        ],
        [
            'pattern' => '/\bmkfs\b/i',
            'reason' => 'Disk formatting is not allowed',
            'hint' => 'Use the portal for volume/storage changes.',
        ],
        [
            'pattern' => '/\bdd\b.*of=\/dev/i',
            'reason' => 'Writing to block devices is not allowed',
            'hint' => 'Write application data under /app.',
        ],
        [
            'pattern' => '/\bpython.*-c.*import\s+os/i',
            'reason' => 'Inline OS escape patterns are not allowed',
            'hint' => 'Run scripts from files under /app instead.',
        ],
        [
            'pattern' => '/\bperl.*-e.*exec/i',
            'reason' => 'Inline OS escape patterns are not allowed',
            'hint' => 'Run scripts from files under /app instead.',
        ],
        [
            'pattern' => '/\bmount\b/i',
            'reason' => 'Mounting filesystems is not allowed',
            'hint' => 'Use the portal for storage and mounts.',
        ],
        [
            'pattern' => '/\bumount\b/i',
            'reason' => 'Unmounting filesystems is not allowed',
            'hint' => 'Use the portal for storage and mounts.',
        ],
        [
            'pattern' => '/\bshutdown\b/i',
            'reason' => 'Host power controls are not allowed',
            'hint' => 'Stop or restart the app from Overview.',
        ],
        [
            'pattern' => '/\breboot\b/i',
            'reason' => 'Host power controls are not allowed',
            'hint' => 'Redeploy or restart the app from Overview.',
        ],
        [
            'pattern' => '/\binit\s+[0-6]\b/i',
            'reason' => 'Host init controls are not allowed',
            'hint' => 'Use Overview → Start / Stop / Redeploy.',
        ],
        [
            'pattern' => '/\bsystemctl\b/i',
            'reason' => 'Host service control is not allowed',
            'hint' => 'Manage the app process from the portal or with process tools (ps, kill).',
        ],
        [
            'pattern' => '/\bservice\b/i',
            'reason' => 'Host service control is not allowed',
            'hint' => 'Manage the app from Overview or with process tools inside the container.',
        ],
        [
            'pattern' => '/\bcrontab\b/i',
            'reason' => 'System crontab editing is not allowed',
            'hint' => 'Use application schedulers (e.g. Laravel schedule) or portal cron jobs.',
        ],
        [
            'pattern' => '/\bat\s+\d/i',
            'reason' => 'System at-jobs are not allowed',
            'hint' => 'Use your app scheduler or portal cron jobs.',
        ],
        [
            'pattern' => '/\bnohup\b/i',
            'reason' => 'Detached background jobs are not allowed',
            'hint' => 'Run foreground commands, or use Overview / process managers for long services.',
        ],
        [
            'pattern' => '/\bdisown\b/i',
            'reason' => 'Detached background jobs are not allowed',
            'hint' => 'Keep processes in the foreground for this terminal session.',
        ],
        [
            'pattern' => '/\bsetsid\b/i',
            'reason' => 'Detached session leaders are not allowed',
            'hint' => 'Run commands in the foreground.',
        ],
        [
            'pattern' => '/\/var\/run\/docker\.sock/i',
            'reason' => 'Docker socket access is not allowed',
            'hint' => 'Use the customer portal to manage containers.',
        ],
        [
            'pattern' => '/\biptables\b/i',
            'reason' => 'Firewall changes are not allowed',
            'hint' => 'Network rules are managed by the platform.',
        ],
        [
            'pattern' => '/\bufw\b/i',
            'reason' => 'Firewall changes are not allowed',
            'hint' => 'Network rules are managed by the platform.',
        ],
        [
            'pattern' => '/\bnft\b/i',
            'reason' => 'Firewall changes are not allowed',
            'hint' => 'Network rules are managed by the platform.',
        ],
        [
            'pattern' => '/\bnc\b/i',
            'reason' => 'Raw network listeners/scanners are not allowed',
            'hint' => 'Use curl or wget for HTTP requests from the app.',
        ],
        [
            'pattern' => '/\bncat\b/i',
            'reason' => 'Raw network listeners/scanners are not allowed',
            'hint' => 'Use curl or wget for HTTP requests from the app.',
        ],
        [
            'pattern' => '/\bnetcat\b/i',
            'reason' => 'Raw network listeners/scanners are not allowed',
            'hint' => 'Use curl or wget for HTTP requests from the app.',
        ],
        [
            'pattern' => '/\bnmap\b/i',
            'reason' => 'Network scanning is not allowed',
            'hint' => 'Use curl/wget against known endpoints you own.',
        ],
        [
            'pattern' => '/\bsocat\b/i',
            'reason' => 'Raw network relays are not allowed',
            'hint' => 'Use curl or wget for HTTP traffic.',
        ],
        [
            'pattern' => '/\bscp\b/i',
            'reason' => 'Outbound SSH file transfer is not allowed',
            'hint' => 'Use Git pull/push from the portal, or curl/wget for downloads.',
        ],
        [
            'pattern' => '/\bsftp\b/i',
            'reason' => 'Outbound SSH file transfer is not allowed',
            'hint' => 'Use Git from the portal, or curl/wget for downloads.',
        ],
        [
            'pattern' => '/\bssh\b/i',
            'reason' => 'Outbound SSH is not allowed',
            'hint' => 'Stay in this container shell; use Git integration for remotes.',
        ],
        [
            'pattern' => '/\btelnet\b/i',
            'reason' => 'Outbound telnet is not allowed',
            'hint' => 'Use curl for HTTP connectivity checks.',
        ],
        [
            'pattern' => '/\bftp\b/i',
            'reason' => 'Outbound FTP is not allowed',
            'hint' => 'Use curl/wget or Git for transfers.',
        ],
    ];

    public function validate(string $command): array
    {
        $maxLength = $this->maxCommandLength();
        $sanitized = $this->sanitize($command);

        if ($sanitized === '') {
            return [
                'allowed' => false,
                'reason' => 'Empty commands are not allowed',
                'hint' => 'Type a command and press Enter.',
                'sanitized' => $sanitized,
            ];
        }

        if (strlen(trim($command)) > $maxLength) {
            return [
                'allowed' => false,
                'reason' => "Command exceeds the {$maxLength} character limit",
                'hint' => 'Split the work into smaller commands or save a script under /app and run it.',
                'sanitized' => $sanitized,
            ];
        }

        if ($this->containsBackgroundExecution($sanitized)) {
            return [
                'allowed' => false,
                'reason' => 'Background execution is not allowed',
                'hint' => 'Run the command in the foreground (omit trailing &).',
                'sanitized' => $sanitized,
            ];
        }

        foreach (self::BLOCKED_RULES as $rule) {
            if (preg_match($rule['pattern'], $sanitized)) {
                return [
                    'allowed' => false,
                    'reason' => $rule['reason'],
                    'hint' => $rule['hint'],
                    'sanitized' => $sanitized,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'hint' => null,
            'sanitized' => $sanitized,
        ];
    }

    public function formatBlockMessage(array $validation): string
    {
        $reason = (string) ($validation['reason'] ?? 'Command not allowed');
        $hint = trim((string) ($validation['hint'] ?? ''));

        $message = "Command blocked: {$reason}";
        if ($hint !== '') {
            $message .= " — {$hint}";
        }

        return $message;
    }

    public function sanitize(string $command): string
    {
        $command = trim($command);
        $command = str_replace("\x00", '', $command);

        $maxLength = $this->maxCommandLength();
        if (strlen($command) > $maxLength) {
            $command = substr($command, 0, $maxLength);
        }

        $command = preg_replace('/\s*\\\s*$/', '', $command) ?? $command;
        $command = preg_replace('/\s*(&&|\|\||;|\|)\s*$/', '', $command) ?? $command;

        return trim($command);
    }

    public function maxCommandLength(): int
    {
        return max(1024, (int) config('terminal.security.max_command_length', 8192));
    }

    private function containsBackgroundExecution(string $command): bool
    {
        return preg_match('/(^|[^&])&($|[^&])/', $command) === 1;
    }
}

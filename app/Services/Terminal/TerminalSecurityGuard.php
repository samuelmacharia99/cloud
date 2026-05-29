<?php

namespace App\Services\Terminal;

class TerminalSecurityGuard
{
    private const BLOCKED_PATTERNS = [
        '/\bsudo\b/i',
        '/\bsu\b(\s|$)/i',
        '/\bsudo\s*-/i',
        '/\bdocker\b/i',
        '/\bdockerd\b/i',
        '/\bnsenter\b/i',
        '/\bchroot\b/i',
        '/\bunshare\b/i',
        '/\bpivot_root\b/i',
        '/chmod\s+[+]s/i',
        '/chown\s+root/i',
        '/\bpasswd\b/i',
        '/\/etc\/shadow/i',
        '/\/etc\/passwd/i',
        '/\brmdir\s+\/\b/i',
        '/\brm\s+(-[rfRF]+\s+)?\/\s/i',
        '/:\(\)\s*\{.*\}/i',
        '/\bmkfs\b/i',
        '/\bdd\b.*of=\/dev/i',
        '/\bpython.*-c.*import\s+os/i',
        '/\bperl.*-e.*exec/i',
        '/\bmount\b/i',
        '/\bumount\b/i',
        '/\bkill(all)?\b/i',
        '/\bpkill\b/i',
        '/\bshutdown\b/i',
        '/\breboot\b/i',
        '/\binit\s+[0-6]\b/i',
        '/\bsystemctl\b/i',
        '/\bservice\b/i',
        '/\bcrontab\b/i',
        '/\bat\s+\d/i',
        '/\bnohup\b/i',
        '/\bdisown\b/i',
        '/\bsetsid\b/i',
        '/\/var\/run\/docker\.sock/i',
        '/\biptables\b/i',
        '/\bufw\b/i',
        '/\bnft\b/i',
        '/\bnc\b/i',
        '/\bncat\b/i',
        '/\bnetcat\b/i',
        '/\bnmap\b/i',
        '/\bsocat\b/i',
        '/\bscp\b/i',
        '/\bsftp\b/i',
        '/\bssh\b/i',
        '/\btelnet\b/i',
        '/\bftp\b/i',
    ];

    private const MAX_COMMAND_LENGTH = 1024;

    public function validate(string $command): array
    {
        $sanitized = $this->sanitize($command);
        if ($sanitized === '') {
            return [
                'allowed' => false,
                'reason' => 'Empty commands are not allowed',
                'sanitized' => $sanitized,
            ];
        }

        if ($this->containsBackgroundExecution($sanitized)) {
            return [
                'allowed' => false,
                'reason' => 'Background execution is not allowed',
                'sanitized' => $sanitized,
            ];
        }

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $sanitized)) {
                return [
                    'allowed' => false,
                    'reason' => 'Command contains blocked pattern',
                    'sanitized' => $sanitized,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'sanitized' => $sanitized,
        ];
    }

    public function sanitize(string $command): string
    {
        // Trim whitespace
        $command = trim($command);

        // Remove null bytes
        $command = str_replace("\x00", '', $command);

        // Enforce max length
        if (strlen($command) > self::MAX_COMMAND_LENGTH) {
            $command = substr($command, 0, self::MAX_COMMAND_LENGTH);
        }

        // Strip trailing line continuations and dangling operators (common when pasting).
        $command = preg_replace('/\s*\\\s*$/', '', $command) ?? $command;
        $command = preg_replace('/\s*(&&|\|\||;|\|)\s*$/', '', $command) ?? $command;

        return trim($command);
    }

    private function containsBackgroundExecution(string $command): bool
    {
        // Disallow common background operators to avoid persistent or runaway jobs.
        return preg_match('/(^|[^&])&($|[^&])/', $command) === 1;
    }
}

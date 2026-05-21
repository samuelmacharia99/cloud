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
    ];

    private const MAX_COMMAND_LENGTH = 1024;

    public function validate(string $command): array
    {
        $sanitized = $this->sanitize($command);

        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $command)) {
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

        return $command;
    }
}

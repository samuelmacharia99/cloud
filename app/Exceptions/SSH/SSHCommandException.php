<?php

namespace App\Exceptions\SSH;

use Exception;

class SSHCommandException extends Exception
{
    public readonly string $command;

    public readonly string $output;

    public readonly string $errorDetail;

    public function __construct(
        string $command,
        string $output = '',
        string $errorDetail = '',
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->command = self::redactSensitive($command);
        $this->output = self::redactSensitive($output);
        $this->errorDetail = self::redactSensitive($errorDetail);

        $message = "SSH command failed: {$this->command}";
        if ($this->errorDetail !== '') {
            $message .= "\nError: {$this->errorDetail}";
        }
        if ($this->output !== '') {
            $message .= "\nOutput: {$this->output}";
        }

        parent::__construct($message, $code, $previous);
    }

    public static function redactSensitive(string $value): string
    {
        $value = preg_replace(
            '#(https://)(?:[^/@\s]+(?::[^@\s]*)?@)#i',
            '$1[credentials]@',
            $value
        ) ?? $value;

        $value = preg_replace(
            '/\b(?:github_pat_[A-Za-z0-9_]+|gh[pousr]_[A-Za-z0-9]+|glpat-[A-Za-z0-9_-]+)\b/',
            '[token]',
            $value
        ) ?? $value;

        return preg_replace(
            '/([?&](?:token|access_token|private_token)=)[^&\s]+/i',
            '$1[token]',
            $value
        ) ?? $value;
    }
}

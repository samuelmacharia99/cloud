<?php

namespace App\Exceptions\SSH;

use Exception;

class SSHCommandException extends Exception
{
    public function __construct(
        public readonly string $command,
        public readonly string $output = '',
        public readonly string $errorDetail = '',
        int $code = 0,
        ?Exception $previous = null
    ) {
        $message = "SSH command failed: {$command}";
        if ($errorDetail !== '') {
            $message .= "\nError: {$errorDetail}";
        }
        if ($output !== '') {
            $message .= "\nOutput: {$output}";
        }

        parent::__construct($message, $code, $previous);
    }
}

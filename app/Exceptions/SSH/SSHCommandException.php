<?php

namespace App\Exceptions\SSH;

use Exception;

class SSHCommandException extends Exception
{
    public function __construct(
        string $command,
        string $output = '',
        string $errorMessage = '',
        int $code = 0,
        Exception $previous = null
    ) {
        $message = "SSH command failed: {$command}";
        if ($errorMessage) {
            $message .= "\nError: {$errorMessage}";
        }
        if ($output) {
            $message .= "\nOutput: {$output}";
        }
        parent::__construct($message, $code, $previous);
    }
}

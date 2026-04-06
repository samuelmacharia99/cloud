<?php

namespace App\Exceptions\SSH;

use Exception;

class SSHConnectionException extends Exception
{
    public function __construct(string $host, string $message = '', int $code = 0, Exception $previous = null)
    {
        $fullMessage = "SSH connection failed to {$host}";
        if ($message) {
            $fullMessage .= ": {$message}";
        }
        parent::__construct($fullMessage, $code, $previous);
    }
}
